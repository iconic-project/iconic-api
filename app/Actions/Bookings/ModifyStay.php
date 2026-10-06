<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\HoldType;
use App\Enums\Permission;
use App\Enums\RefundRequestStatus;
use App\Enums\ReleaseReason;
use App\Enums\RoomStatus;
use App\Enums\TaxBasis;
use App\Events\BookingChargesChanged;
use App\Events\BookingStatusChanged;
use App\Events\RefundRequested;
use App\Events\StayModified;
use App\Exceptions\RoomUnavailableException;
use App\Models\Booking;
use App\Models\RefundRequest;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\RoomPricer;
use App\Services\Pricing\StayQuoteInput;
use App\Support\Bookings\FrontDeskLock;
use App\Support\Bookings\Transitions;
use App\Support\BusinessHours;
use App\Support\BusinessTime;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Config\Documents\Tax;
use App\Support\History\History;
use App\Support\Payments\CancellationBands;
use App\Support\Payments\CancellationPenalty;
use App\Support\Payments\Ledger;
use App\Support\Rounding;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Extend, shorten, shift, or reprice a stay.
 *
 * Length-of-stay: the band for the new night count applies to added nights
 * only. Nights that stay in the booking keep the sold night line, so a
 * discount already inside that sold total is not recomputed.
 * TODO(OPEN: HQ8) confirm this length-of-stay rule with the client.
 *
 * Early departure still uses {@see self::shorten()} so check-out does not
 * add a cancellation penalty or the modification fee.
 */
final class ModifyStay extends Action
{
    public function __construct(
        private ClaimService $claims,
        private CurrentConfig $config,
        private RoomPricer $pricer,
    ) {}

    public static function allows(BookingStatus $status): bool
    {
        return in_array($status, [
            BookingStatus::Requested,
            BookingStatus::PendingPayment,
            BookingStatus::Confirmed,
            BookingStatus::FullyPaid,
            BookingStatus::InHouse,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     available: bool,
     *     check_in: string,
     *     check_out: string,
     *     nights: int,
     *     night_lines: list<array<string, mixed>>,
     *     price_lines: list<array{code: string, label: string, amount: int}>,
     *     tax_lines: list<array<string, mixed>>,
     *     credit: int,
     *     penalty: int,
     *     penalty_pct: int,
     *     penalty_waived: bool,
     *     modification_fee: int,
     *     length_of_stay: int,
     *     current_total: int,
     *     new_total: int,
     *     balance: int,
     *     refund_due: int,
     *     status: string,
     *     room_id: int|null
     * }
     */
    public function preview(Booking $booking, array $data, User $actor): array
    {
        return $this->quote($booking, $data, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);
            $quote = $this->quote($booking, $data, $actor);
            $current = $booking->stay();
            $next = StayDates::of($quote['check_in'], $quote['check_out']);
            $room = $this->room($booking, $data);
            $reprice = (bool) ($data['reprice'] ?? false);
            $pinned = isset($data['room_id']) && is_numeric($data['room_id']);
            $started = $current->checkIn()->toDateString() < BusinessTime::now()->toDateString();

            if (! $quote['available'] && ! $pinned && ! $started) {
                $fallback = $this->fallbackRoom($booking, $room, $this->nightList($next));

                if ($fallback instanceof Room) {
                    $data['room_id'] = $fallback->id;
                    $quote = $this->quote($booking, $data, $actor);
                    $room = $fallback;
                }
            }

            if (! $quote['available']) {
                throw new RoomUnavailableException(
                    (string) ($room->roomType->name ?? 'Room'),
                    $next->checkIn()->toDateString(),
                );
            }

            $this->place($booking, $current, $next, $room, $reprice);
            $from = $booking->status;
            $before = [
                'check_in' => $current->checkIn()->toDateString(),
                'check_out' => $current->checkOut()->toDateString(),
                'nights' => $current->nights(),
                'total' => $booking->total,
                'room_id' => $booking->room_id,
                'status' => $from->value,
            ];

            $booking->setAttribute('check_in', $next->checkIn()->toDateString());
            $booking->setAttribute('check_out', $next->checkOut()->toDateString());
            $booking->nights = $next->nights();
            $booking->night_lines = $quote['night_lines'];
            $booking->price_lines = $quote['price_lines'];
            $booking->tax_lines = $quote['tax_lines'];
            $booking->total = $quote['new_total'];
            $booking->room_id = $room->id;
            $booking->room_type_id = $room->room_type_id;
            $booking->status = BookingStatus::from($quote['status']);
            $booking->save();

            $what = 'Stay modified · '.$before['check_in'].'–'.$before['check_out']
                .' → '.$quote['check_in'].'–'.$quote['check_out'];

            if ($from->value !== $quote['status']) {
                $what .= ' · '.Transitions::statusLabel($from).' → '.Transitions::statusLabel($booking->status);
            }

            History::record($booking, 'booking.stay_modified', before: $before, after: [
                'check_in' => $quote['check_in'],
                'check_out' => $quote['check_out'],
                'nights' => $quote['nights'],
                'total' => $quote['new_total'],
                'room_id' => $room->id,
                'status' => $quote['status'],
                'credit' => $quote['credit'],
                'penalty' => $quote['penalty'],
                'what' => $what,
            ], reason: $this->reason($data), actor: $actor);

            if ($from !== $booking->status) {
                BookingStatusChanged::dispatch($booking, $from, $booking->status);
            }

            if ($quote['new_total'] !== $before['total']) {
                BookingChargesChanged::dispatch($booking, 'Stay modified');
            }

            StayModified::dispatch($booking);

            $this->refundOverpayment($booking, $quote, $actor);

            return $booking->refresh()->load(['room', 'roomType', 'property', 'contact', 'owner']);
        });
    }

    public function shorten(Booking $booking, StayDates $next, string $reason, ?User $actor): Booking
    {
        $current = $booking->stay();
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A reason is required to shorten the stay.'],
            ]);
        }

        if ($next->checkIn()->toDateString() !== $current->checkIn()->toDateString()
            || $next->checkOut()->toDateString() >= $current->checkOut()->toDateString()
        ) {
            throw ValidationException::withMessages([
                'check_out' => ['Early departure shortens the stay and keeps the arrival date.'],
            ]);
        }

        $removed = StayDates::of($next->checkOut()->toDateString(), $current->checkOut()->toDateString());
        $credit = $this->sold($booking->night_lines ?? [], $this->nightList($removed));

        $this->claims->release($booking, ReleaseReason::Released, nights: $removed);

        $kept = [];

        foreach ($booking->night_lines ?? [] as $line) {
            $night = $line['night'] ?? null;

            if (is_string($night) && $night >= $next->checkIn()->toDateString() && $night < $next->checkOut()->toDateString()) {
                $kept[] = $line;
            }
        }

        $lines = $booking->price_lines;
        $lines[] = [
            'code' => 'EARLY_DEPARTURE',
            'label' => 'Early departure credit',
            'amount' => -$credit,
        ];

        $before = [
            'check_out' => $current->checkOut()->toDateString(),
            'nights' => $current->nights(),
            'total' => $booking->total,
        ];

        $booking->setAttribute('check_out', $next->checkOut()->toDateString());
        $booking->nights = $next->nights();
        $booking->night_lines = $kept;
        $booking->price_lines = $lines;
        $booking->total = max(0, $booking->total - $credit);
        $booking->save();

        History::record($booking, 'booking.stay_shortened', before: $before, after: [
            'check_out' => $next->checkOut()->toDateString(),
            'nights' => $next->nights(),
            'total' => $booking->total,
            'credit' => $credit,
            'what' => 'Early departure — '.$removed->nights().' night'.($removed->nights() === 1 ? '' : 's').' credited',
        ], reason: $reason, actor: $actor);

        BookingChargesChanged::dispatch($booking, 'Early departure credit');
        StayModified::dispatch($booking);

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     available: bool,
     *     check_in: string,
     *     check_out: string,
     *     nights: int,
     *     night_lines: list<array<string, mixed>>,
     *     price_lines: list<array{code: string, label: string, amount: int}>,
     *     tax_lines: list<array<string, mixed>>,
     *     credit: int,
     *     penalty: int,
     *     penalty_pct: int,
     *     penalty_waived: bool,
     *     modification_fee: int,
     *     length_of_stay: int,
     *     current_total: int,
     *     new_total: int,
     *     balance: int,
     *     refund_due: int,
     *     status: string,
     *     room_id: int|null
     * }
     */
    private function quote(Booking $booking, array $data, User $actor): array
    {
        $this->guardStatus($booking);
        $current = $booking->stay();
        $next = $this->nextStay($booking, $data);
        $reprice = (bool) ($data['reprice'] ?? false);
        $room = $this->room($booking, $data);
        $sameRoom = (int) $room->id === (int) $booking->room_id;
        $sameDates = $next->checkIn()->toDateString() === $current->checkIn()->toDateString()
            && $next->checkOut()->toDateString() === $current->checkOut()->toDateString();

        if ($sameDates && $sameRoom && ! $reprice) {
            throw ValidationException::withMessages([
                'check_in' => ['The stay did not change.'],
            ]);
        }

        $oldNights = $this->nightList($current);
        $newNights = $this->nightList($next);
        $addedNights = $reprice ? $newNights : array_values(array_diff($newNights, $oldNights));
        $removedNights = $reprice ? [] : array_values(array_diff($oldNights, $newNights));
        $kept = $reprice ? [] : $this->linesFor($booking->night_lines ?? [], $newNights);
        $added = $this->priceAdded($booking, $room, $addedNights);
        $credit = $reprice ? 0 : $this->sold($booking->night_lines ?? [], $removedNights);
        $waived = $this->waived($data, $actor);
        $penaltyPct = $waived || $removedNights === [] ? 0 : $this->penaltyPct($booking);
        $penalty = $penaltyPct === 0 ? 0 : Rounding::halfUp($credit * $penaltyPct / 100);
        $los = $this->lengthOfStay($this->config->rates(), count($newNights), $this->sold($added, $addedNights));
        $fee = $this->config->businessRules()->modificationFeeUsd;
        $nightLines = $this->mergeLines($kept, $added);
        $priceLines = $this->summary($nightLines, $room, $los, $penalty, $fee);
        $newTotal = 0;

        foreach ($priceLines as $line) {
            $newTotal += $line['amount'];
        }

        $roomDelta = $this->sold($added, $addedNights) - $credit - $los + $penalty + $fee;
        $taxLines = $this->taxLines($booking, count($addedNights), count($removedNights), $roomDelta);
        $paid = Ledger::paidFresh($booking);
        $status = $booking->status;

        if ($booking->status === BookingStatus::FullyPaid && $newTotal > $booking->total) {
            $status = BookingStatus::Confirmed;
        }

        return [
            'available' => $this->available($booking, $room, $reprice || ! $sameRoom ? $newNights : $addedNights),
            'check_in' => $next->checkIn()->toDateString(),
            'check_out' => $next->checkOut()->toDateString(),
            'nights' => count($newNights),
            'night_lines' => $nightLines,
            'price_lines' => $priceLines,
            'tax_lines' => $taxLines,
            'credit' => $credit,
            'penalty' => $penalty,
            'penalty_pct' => $penaltyPct,
            'penalty_waived' => $waived,
            'modification_fee' => $fee,
            'length_of_stay' => $los,
            'current_total' => $booking->total,
            'new_total' => $newTotal,
            'balance' => $newTotal - $paid,
            'refund_due' => max(0, $paid - $newTotal),
            'status' => $status->value,
            'room_id' => $room->id,
        ];
    }

    private function guardStatus(Booking $booking): void
    {
        if (! self::allows($booking->status)) {
            throw ValidationException::withMessages([
                'status' => ['A stay can be changed while it is requested, pending, confirmed, fully paid, or in house.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nextStay(Booking $booking, array $data): StayDates
    {
        $checkIn = isset($data['check_in']) && is_string($data['check_in']) && $data['check_in'] !== ''
            ? $data['check_in']
            : $booking->stay()->checkIn()->toDateString();
        $checkOut = isset($data['check_out']) && is_string($data['check_out']) && $data['check_out'] !== ''
            ? $data['check_out']
            : $booking->stay()->checkOut()->toDateString();

        try {
            return StayDates::of($checkIn, $checkOut);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'check_out' => ['Check-out must be after check-in.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function room(Booking $booking, array $data): Room
    {
        $booking->loadMissing('room.roomType');
        $current = $booking->room;

        if (! $current instanceof Room) {
            throw ValidationException::withMessages([
                'room_id' => ['This booking has no room.'],
            ]);
        }

        $typeCode = isset($data['room_type']) && is_string($data['room_type']) && $data['room_type'] !== ''
            ? $data['room_type']
            : null;

        if (isset($data['room_id']) && is_numeric($data['room_id'])) {
            $room = Room::query()->with('roomType')->find((int) $data['room_id']);

            if (! $room instanceof Room || $room->status !== RoomStatus::Active || (int) $room->property_id !== (int) $booking->property_id) {
                throw ValidationException::withMessages([
                    'room_id' => ['Pick an active room at this property.'],
                ]);
            }

            if ($typeCode !== null && $room->roomType->code !== $typeCode) {
                throw ValidationException::withMessages([
                    'room_type' => ['That room is not the requested type.'],
                ]);
            }

            $this->guardParty($booking, $room->roomType);

            return $room;
        }

        if ($typeCode === null || $current->roomType->code === $typeCode) {
            return $current;
        }

        $type = $this->type($booking, $typeCode);
        $this->guardParty($booking, $type);
        $candidate = Room::query()
            ->where('property_id', $booking->property_id)
            ->where('room_type_id', $type->id)
            ->where('status', RoomStatus::Active)
            ->orderBy('sort')
            ->orderBy('id')
            ->first();

        if (! $candidate instanceof Room) {
            throw ValidationException::withMessages([
                'room_type' => ['No active room of that type.'],
            ]);
        }

        return $candidate->load('roomType');
    }

    private function type(Booking $booking, string $code): RoomType
    {
        $type = RoomType::query()
            ->where('property_id', $booking->property_id)
            ->where('code', $code)
            ->first();

        if (! $type instanceof RoomType) {
            throw ValidationException::withMessages([
                'room_type' => ['Unknown room type.'],
            ]);
        }

        return $type;
    }

    private function guardParty(Booking $booking, RoomType $type): void
    {
        $children = count($booking->child_ages ?? []);

        if ($booking->adults > $type->max_adults || $booking->adults + $children > $type->max_occupancy) {
            throw ValidationException::withMessages([
                'room_type' => ['That room type cannot hold this party.'],
            ]);
        }
    }

    /**
     * @param  list<string>  $nights
     * @return list<array<string, mixed>>
     */
    private function priceAdded(Booking $booking, Room $room, array $nights): array
    {
        if ($nights === []) {
            return [];
        }

        $room->loadMissing('roomType');
        $rates = $this->config->rates();
        $plan = $this->plan($rates, $booking->rate_plan_code);
        $versionId = (int) $this->config->version(ConfigKind::Rates)->id;
        $lines = [];

        foreach ($nights as $night) {
            $priced = $this->pricer->quote($rates, $room->roomType, new StayQuoteInput(
                StayDates::forNights($night, 1),
                $room->roomType->code,
                $booking->adults,
                $booking->child_ages ?? [],
                $plan,
                ratesVersionId: $versionId,
            ));

            if ($priced instanceof NoRate) {
                throw ValidationException::withMessages([
                    'check_in' => [$priced->reason],
                ]);
            }

            $line = $priced->nightLines[0]->toArray();
            $line['rates_version_id'] = $versionId;
            $lines[] = $line;
        }

        return $lines;
    }

    private function plan(RatesDocument $rates, ?string $code): string
    {
        if (is_string($code) && $code !== '') {
            return $code;
        }

        foreach ($rates->ratePlans as $plan) {
            if ($plan->isDefault) {
                return $plan->code;
            }
        }

        return 'BAR';
    }

    private function penaltyPct(Booking $booking): int
    {
        $days = BusinessTime::calendarDaysBetween(
            BusinessTime::now()->toDateString(),
            $booking->stay()->checkIn()->toDateString(),
        );
        $bands = app(CancellationBands::class)->forBooking($booking);

        if ($bands === []) {
            throw ValidationException::withMessages([
                'rate_plan' => ['This rate plan has no cancellation set.'],
            ]);
        }

        return (int) CancellationPenalty::bandFor($days, $bands)['penalty_pct'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function waived(array $data, User $actor): bool
    {
        $code = isset($data['reason_code']) && is_string($data['reason_code']) ? $data['reason_code'] : '';

        return $code === 'GUEST_FRIENDLY' && $actor->hasPermission(Permission::BookingsWaivePenalty);
    }

    private function lengthOfStay(RatesDocument $rates, int $nights, int $addedTotal): int
    {
        $pct = 0;
        $minNights = -1;

        foreach ($rates->lengthOfStay as $band) {
            if ($band->minNights <= $nights && $band->minNights > $minNights) {
                $minNights = $band->minNights;
                $pct = $band->discountPct;
            }
        }

        if ($pct === 0 || $addedTotal === 0) {
            return 0;
        }

        return Rounding::halfUp($addedTotal * $pct / 100);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{code: string, label: string, amount: int}>
     */
    private function summary(array $lines, Room $room, int $los, int $penalty, int $fee): array
    {
        $room->loadMissing('roomType');
        $base = 0;
        $extras = 0;
        $single = 0;
        $dow = 0;
        $supplements = 0;
        $plan = 0;

        foreach ($lines as $line) {
            $base += (int) ($line['base'] ?? 0);
            $extras += (int) ($line['extras'] ?? 0);
            $single += (int) ($line['single'] ?? 0);
            $dow += (int) ($line['dow'] ?? 0);
            $supplements += (int) ($line['supplements'] ?? 0);
            $plan += (int) ($line['plan_adjust'] ?? 0);
        }

        $summary = [];
        $this->push($summary, 'room', $room->roomType->name.' · '.count($lines).' nights', $base);
        $this->push($summary, 'extras', 'Extras', $extras);
        $this->push($summary, 'single_occupancy', 'Single occupancy', $single);
        $this->push($summary, 'day_of_week', 'Day of week', $dow);
        $this->push($summary, 'supplement', 'Supplement', $supplements);
        $this->push($summary, 'rate_plan', 'Rate plan', $plan);
        $this->push($summary, 'length_of_stay', 'Length of stay', -$los);
        $this->push($summary, 'modification_penalty', 'Modification penalty', $penalty);
        $this->push($summary, 'modification_fee', 'Modification fee', $fee);

        return $summary;
    }

    /**
     * @param  list<array{code: string, label: string, amount: int}>  $lines
     */
    private function push(array &$lines, string $code, string $label, int $amount): void
    {
        if ($amount === 0) {
            return;
        }

        $lines[] = [
            'code' => $code,
            'label' => $label,
            'amount' => $amount,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taxLines(Booking $booking, int $addedNights, int $removedNights, int $roomDelta): array
    {
        $existing = [];

        foreach ($booking->tax_lines ?? [] as $line) {
            $code = $line['code'] ?? null;

            if (is_string($code) && $code !== '') {
                $existing[$code] = $line;
            }
        }

        $nightDelta = $addedNights - $removedNights;

        foreach ($this->config->businessRules()->taxes as $tax) {
            $delta = match ($tax->basis) {
                TaxBasis::PerStay => 0,
                TaxBasis::PerNight => $tax->amount * $nightDelta,
                TaxBasis::PerPersonPerNight => $tax->amount * ($booking->adults + $this->chargeableChildren($booking, $tax)) * $nightDelta,
                TaxBasis::PctOfRoom => Rounding::halfUp($roomDelta * $tax->amount / 100),
            };

            if (! isset($existing[$tax->code])) {
                if ($delta === 0) {
                    continue;
                }

                $existing[$tax->code] = [
                    'code' => $tax->code,
                    'label' => $tax->label,
                    'amount' => $delta,
                    'charged' => $tax->charged,
                    'shown_in_price_panel' => $tax->shownInPricePanel,
                ];

                continue;
            }

            $existing[$tax->code]['amount'] = (int) ($existing[$tax->code]['amount'] ?? 0) + $delta;
        }

        return array_values($existing);
    }

    private function chargeableChildren(Booking $booking, Tax $tax): int
    {
        $count = 0;

        foreach ($booking->child_ages ?? [] as $age) {
            if ($tax->childExemptUnderAge !== null && $age < $tax->childExemptUnderAge) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @param  list<string>  $claimNights
     */
    private function fallbackRoom(Booking $booking, Room $current, array $claimNights): ?Room
    {
        if ($claimNights === []) {
            return null;
        }

        $busy = RoomNightClaim::query()
            ->whereIn('night', $claimNights)
            ->whereNull('released_at')
            ->where(function ($query) use ($booking): void {
                $query->where('holder_type', '!=', $booking->getMorphClass())
                    ->orWhere('holder_id', '!=', $booking->getKey());
            })
            ->pluck('room_id');

        return Room::query()
            ->with('roomType')
            ->where('property_id', $booking->property_id)
            ->where('room_type_id', $current->room_type_id)
            ->where('status', RoomStatus::Active)
            ->where('id', '!=', $current->id)
            ->whereNotIn('id', $busy)
            ->orderBy('sort')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<string>  $claimNights
     */
    private function available(Booking $booking, Room $room, array $claimNights): bool
    {
        if ($claimNights === []) {
            return true;
        }

        return ! RoomNightClaim::query()
            ->where('room_id', $room->id)
            ->whereIn('night', $claimNights)
            ->whereNull('released_at')
            ->where(function ($query) use ($booking): void {
                $query->where('holder_type', '!=', $booking->getMorphClass())
                    ->orWhere('holder_id', '!=', $booking->getKey());
            })
            ->exists();
    }

    private function place(Booking $booking, StayDates $current, StayDates $next, Room $room, bool $reprice): void
    {
        $started = $current->checkIn()->toDateString() < BusinessTime::now()->toDateString();
        $sameRoom = (int) $room->id === (int) $booking->room_id;

        if (! $sameRoom && $started) {
            throw ValidationException::withMessages([
                'room_id' => ['A stay that has already started cannot move to another room.'],
            ]);
        }

        $added = array_values(array_diff($this->nightList($next), $this->nightList($current)));
        $removed = array_values(array_diff($this->nightList($current), $this->nightList($next)));

        if ($sameRoom) {
            if (! $reprice) {
                $this->claimRuns($booking, $room, $added);
                $this->releaseRuns($booking, $removed);
            }

            return;
        }

        $this->claimStay($booking, $room, $next);
        $this->claims->release($booking, ReleaseReason::Moved, rooms: $this->otherRooms($booking, $room));
        $this->releaseRuns($booking, $removed);
    }

    /**
     * @param  list<string>  $nights
     */
    private function claimRuns(Booking $booking, Room $room, array $nights): void
    {
        foreach ($this->runs($nights) as $run) {
            $this->claimStay($booking, $room, $run);
        }
    }

    private function claimStay(Booking $booking, Room $room, StayDates $stay): void
    {
        [$kind, $holdType, $expiresAt] = $this->claimShape($booking);

        try {
            $this->claims->claim($stay, new Collection([$room]), $booking, $kind, $holdType, $expiresAt);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'check_in' => [$exception->getMessage()],
            ]);
        }
    }

    /**
     * @return array{0: ClaimKind, 1: HoldType|null, 2: CarbonInterface|null}
     */
    private function claimShape(Booking $booking): array
    {
        $claim = RoomNightClaim::query()
            ->where('holder_type', $booking->getMorphClass())
            ->where('holder_id', $booking->getKey())
            ->whereNull('released_at')
            ->first();

        if ($claim instanceof RoomNightClaim && $claim->kind === ClaimKind::Hold) {
            return [$claim->kind, $claim->hold_type, $claim->expires_at];
        }

        return [ClaimKind::Booking, null, null];
    }

    /**
     * @param  list<string>  $nights
     */
    private function releaseRuns(Booking $booking, array $nights): void
    {
        foreach ($this->runs($nights) as $run) {
            $this->claims->release($booking, ReleaseReason::Released, nights: $run);
        }
    }

    /**
     * @return Collection<int, Room>
     */
    private function otherRooms(Booking $booking, Room $keep): Collection
    {
        return Room::query()
            ->whereIn('id', RoomNightClaim::query()
                ->where('holder_type', $booking->getMorphClass())
                ->where('holder_id', $booking->getKey())
                ->whereNull('released_at')
                ->where('room_id', '!=', $keep->id)
                ->pluck('room_id'))
            ->get();
    }

    /**
     * @param  array{
     *     refund_due: int,
     *     penalty: int,
     *     penalty_pct: int,
     *     new_total: int
     * }  $quote
     */
    private function refundOverpayment(Booking $booking, array $quote, User $actor): void
    {
        if ($quote['refund_due'] <= 0) {
            return;
        }

        if ($booking->refundRequest()->where('status', RefundRequestStatus::Pending)->exists()) {
            return;
        }

        $rules = $this->config->businessRules();
        $now = BusinessTime::now();
        $days = BusinessTime::calendarDaysBetween($now->toDateString(), $booking->stay()->checkIn()->toDateString());
        $request = RefundRequest::query()->create([
            'booking_id' => $booking->id,
            'cancelled_at' => $now,
            'days_before_departure' => $days,
            'band_min_days' => 0,
            'band_source' => 'CABIN',
            'penalty_pct' => $quote['penalty_pct'],
            'penalty_amount' => $quote['penalty'],
            'paid_at_cancellation' => Ledger::paidFresh($booking),
            'refund_due' => $quote['refund_due'],
            'status' => RefundRequestStatus::Pending,
            'due_by' => BusinessHours::fromDocument($rules)->endOfNthBusinessDay($now, $rules->sla->refundBusinessDays),
        ]);

        RefundRequested::dispatch($request);

        History::record($booking, 'refund.requested', after: [
            'refund_due' => $quote['refund_due'],
            'penalty_amount' => $quote['penalty'],
            'what' => 'Stay modified — refund requested for the amount paid above the new total',
        ], reason: 'stay modified', actor: $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reason(array $data): string
    {
        $reason = isset($data['reason']) && is_string($data['reason']) ? trim($data['reason']) : '';

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A reason is required to modify a stay.'],
            ]);
        }

        return $reason;
    }

    /**
     * @return list<string>
     */
    private function nightList(StayDates $stay): array
    {
        $nights = [];

        foreach ($stay->eachNight() as $night) {
            $nights[] = $night->toDateString();
        }

        return $nights;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $nights
     * @return list<array<string, mixed>>
     */
    private function linesFor(array $lines, array $nights): array
    {
        $keep = array_fill_keys($nights, true);
        $chosen = [];

        foreach ($lines as $line) {
            $night = $line['night'] ?? null;

            if (is_string($night) && isset($keep[$night])) {
                $chosen[] = $line;
            }
        }

        return $chosen;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $nights
     */
    private function sold(array $lines, array $nights): int
    {
        $wanted = array_fill_keys($nights, true);
        $total = 0;

        foreach ($lines as $line) {
            $night = $line['night'] ?? null;

            if (is_string($night) && isset($wanted[$night])) {
                $total += (int) ($line['total'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $kept
     * @param  list<array<string, mixed>>  $added
     * @return list<array<string, mixed>>
     */
    private function mergeLines(array $kept, array $added): array
    {
        $lines = [...$kept, ...$added];
        usort($lines, function (array $left, array $right): int {
            return strcmp((string) ($left['night'] ?? ''), (string) ($right['night'] ?? ''));
        });

        return $lines;
    }

    /**
     * @param  list<string>  $nights
     * @return list<StayDates>
     */
    private function runs(array $nights): array
    {
        sort($nights);

        if ($nights === []) {
            return [];
        }

        $runs = [];
        $start = $nights[0];
        $previous = $nights[0];

        foreach (array_slice($nights, 1) as $night) {
            if ($night === CarbonImmutable::parse($previous)->addDay()->toDateString()) {
                $previous = $night;

                continue;
            }

            $runs[] = StayDates::of($start, CarbonImmutable::parse($previous)->addDay()->toDateString());
            $start = $night;
            $previous = $night;
        }

        $runs[] = StayDates::of($start, CarbonImmutable::parse($previous)->addDay()->toDateString());

        return $runs;
    }
}
