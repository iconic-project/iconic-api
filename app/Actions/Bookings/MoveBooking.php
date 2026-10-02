<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CabinState;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Events\BookingChargesChanged;
use App\Exceptions\CabinUnavailableException;
use App\Exceptions\ConflictException;
use App\Models\Booking;
use App\Models\CabinClaim;
use App\Models\Departure;
use App\Models\Group;
use App\Models\Room;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Availability;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\Quote;
use App\Services\Pricing\ReservationQuoter;
use App\Support\Blocks\ConflictMessage;
use App\Support\Bookings\BookingMutationLock;
use App\Support\BusinessTime;
use App\Support\Dates\Format;
use App\Support\Guests\ApplyPng;
use App\Support\History\History;
use App\Support\Inventory\DepartureSnapshot;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class MoveBooking extends Action
{
    private const MOVABLE = [
        BookingStatus::Requested,
        BookingStatus::PendingPayment,
        BookingStatus::Confirmed,
        BookingStatus::FullyPaid,
    ];

    public function __construct(
        private ReservationQuoter $quoter,
        private ClaimService $claims,
        private CurrentConfig $config,
        private Availability $availability,
        private ApplyPng $png,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     available: bool,
     *     current_total: int,
     *     new_total: int,
     *     difference: int,
     *     new_price_lines: list<array{code: string, label: string, amount: int}>,
     *     sailing_year_changes: bool,
     *     festive_changes: bool,
     *     warnings: list<string>
     * }
     */
    public function preview(Booking $booking, array $data): array
    {
        $booking->load(['departure.property', 'cabin', 'group', 'claims']);
        $target = $this->targetDeparture((int) $data['departure_id']);
        $cabinCode = $this->cabinCode($booking, $data);

        $this->assertMoveAllowed($booking, $target, $cabinCode);

        return $this->quotePreview($booking, $target, $cabinCode);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CabinUnavailableException
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $oldDepartureId = (int) $booking->departure_id;
            $newDepartureId = (int) $data['departure_id'];

            $booking = BookingMutationLock::acquire(
                $booking,
                $oldDepartureId,
                [$oldDepartureId, $newDepartureId],
            );
            $booking->load(['departure.property', 'cabin', 'group', 'claims', 'contact']);

            $target = Departure::query()->with(['property.cabins', 'itinerary'])->findOrFail($newDepartureId);
            $cabinCode = $this->cabinCode($booking, $data);

            $this->assertMoveAllowed($booking, $target, $cabinCode);

            $quoted = $this->quotePreview($booking, $target, $cabinCode);

            if (! $quoted['available']) {
                throw $this->unavailable($target, $booking, $cabinCode);
            }

            $confirmTotal = (int) $data['confirm_total'];

            if ($confirmTotal !== $quoted['new_total']) {
                throw new ConflictException(
                    'The price changed since the preview ('
                    .Money::format($confirmTotal)
                    .' → '
                    .Money::format($quoted['new_total'])
                    .'). Review and confirm again.',
                );
            }

            $before = $this->historySnapshot($booking);

            $this->moveClaims($booking, $target, $cabinCode);

            $cabin = $booking->type === BookingType::Charter
                ? null
                : $target->property->cabins->first(fn (Room $item): bool => $item->code === $cabinCode);

            $booking->departure_id = $target->id;
            $booking->room_id = $cabin?->id;
            $booking->rates_version_id = $this->config->version(ConfigKind::Rates)->id;
            $booking->price_lines = $quoted['new_price_lines'];
            $booking->total = $quoted['new_total'];
            $booking->save();

            $booking->load(['departure.property', 'cabin']);
            $this->png->toBooking($booking);

            History::record($booking, 'booking.moved', before: $before, after: $this->historySnapshot($booking), actor: $actor);

            BookingChargesChanged::dispatch($booking, 'Moved — reprice');

            return $booking->refresh()->load([
                'departure.property',
                'cabin',
                'contact',
                'group.coordinator',
                'owner',
                'ratesVersion',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cabinCode(Booking $booking, array $data): ?string
    {
        if ($booking->type === BookingType::Charter) {
            return null;
        }

        $code = isset($data['cabin_code']) ? trim((string) $data['cabin_code']) : '';

        if ($code === '') {
            throw ValidationException::withMessages([
                'cabin_code' => ['Pick a cabin.'],
            ]);
        }

        return $code;
    }

    private function targetDeparture(int $id): Departure
    {
        return Departure::query()->with(['property.cabins', 'itinerary'])->findOrFail($id);
    }

    private function assertMoveAllowed(Booking $booking, Departure $target, ?string $cabinCode): void
    {
        if (! in_array($booking->status, self::MOVABLE, true)) {
            throw ValidationException::withMessages([
                'status' => ['This booking cannot be moved.'],
            ]);
        }

        if ($target->date->toDateString() <= BusinessTime::now()->toDateString()) {
            throw ValidationException::withMessages([
                'departure_id' => ['The target departure must be in the future.'],
            ]);
        }

        $sameDeparture = (int) $booking->departure_id === (int) $target->id;
        $sameCabin = $booking->type === BookingType::Charter
            || ($booking->cabin instanceof Room && $booking->cabin->code === $cabinCode);

        if ($sameDeparture && $sameCabin) {
            throw ValidationException::withMessages([
                'departure_id' => ['Nothing to move.'],
            ]);
        }

        if ($booking->status === BookingStatus::Requested && ! $this->hasActiveClaim($booking)) {
            throw ValidationException::withMessages([
                'departure_id' => ["This request's hold has expired — confirm or release it first."],
            ]);
        }

        if ($booking->group_id !== null && ! $sameDeparture) {
            $booking->loadMissing('group');
            $group = $booking->group;
            $reference = $group instanceof Group ? $group->reference : 'GRP';

            throw new ConflictException(
                'This booking belongs to '.$reference.' — moving a group to another departure isn\'t supported yet.',
            );
        }

        if ($booking->type === BookingType::Cabin) {
            $cabin = $target->property->cabins->first(fn (Room $item): bool => $item->code === $cabinCode);

            if (! $cabin instanceof Room) {
                throw ValidationException::withMessages([
                    'cabin_code' => ['Pick a cabin on this departure\'s property.'],
                ]);
            }
        }
    }

    /**
     * @return array{
     *     available: bool,
     *     current_total: int,
     *     new_total: int,
     *     difference: int,
     *     new_price_lines: list<array{code: string, label: string, amount: int}>,
     *     sailing_year_changes: bool,
     *     festive_changes: bool,
     *     warnings: list<string>
     * }
     */
    private function quotePreview(Booking $booking, Departure $target, ?string $cabinCode): array
    {
        $input = [
            'departure_id' => $target->id,
            'type' => $booking->type->value,
            'back_to_back' => $booking->back_to_back,
            'main_channel' => $booking->main_channel,
            'promo_code' => $booking->promo_code,
            'online_deposit' => $booking->online_deposit,
            'booking_date' => $booking->sold_on->toDateString(),
            'cabins' => $booking->type === BookingType::Charter
                ? [['adults' => $booking->adults, 'children' => $booking->children]]
                : [['cabin_code' => $cabinCode, 'adults' => $booking->adults, 'children' => $booking->children]],
        ];

        $quote = $this->quoter->quote($input, $target);

        if ($quote->hasErrors()) {
            throw ValidationException::withMessages([
                'cabins' => $quote->errors(),
            ]);
        }

        $party = $quote->parties[0];
        $priced = $party->quote;

        if (! $priced instanceof Quote) {
            throw ValidationException::withMessages([
                'cabins' => ['A price could not be calculated.'],
            ]);
        }

        $lines = $priced->toArray()['lines'];
        $total = $priced->total;
        $fee = $this->config->businessRules()->modificationFeeUsd;

        if ($fee > 0) {
            $lines[] = [
                'code' => 'modification_fee',
                'label' => 'Modification fee (FIN-006)',
                'amount' => $fee,
            ];
            $total += $fee;
        }

        $booking->loadMissing('departure');

        return [
            'available' => $this->isAvailable($target, $booking, $cabinCode),
            'current_total' => $booking->total,
            'new_total' => $total,
            'difference' => $total - $booking->total,
            'new_price_lines' => $lines,
            'sailing_year_changes' => (int) $booking->departure->date->format('Y') !== (int) $target->date->format('Y'),
            'festive_changes' => $booking->departure->festive !== $target->festive,
            'warnings' => $quote->warnings,
        ];
    }

    private function isAvailable(Departure $target, Booking $booking, ?string $cabinCode): bool
    {
        $snapshots = $this->availability->forDepartures(collect([$target]));
        $snapshot = $snapshots[$target->id];

        if ($booking->type === BookingType::Charter) {
            foreach ($snapshot->cabins as $row) {
                if (! $this->rowIsFreeOrOurs($row, $booking)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($snapshot->cabins as $row) {
            if ($row['cabin']['code'] === $cabinCode) {
                return $this->rowIsFreeOrOurs($row, $booking);
            }
        }

        return false;
    }

    /**
     * @param  array{cabin: array{code: string, label: string, category: string}, state: string, claim: array<string, mixed>|null}  $row
     */
    private function rowIsFreeOrOurs(array $row, Booking $booking): bool
    {
        if ($row['state'] === CabinState::Free->value) {
            return true;
        }

        $claim = $row['claim'];

        if (! is_array($claim)) {
            return false;
        }

        $holder = $claim['holder'] ?? null;

        return is_array($holder)
            && ($holder['type'] ?? null) === $booking->getMorphClass()
            && (int) ($holder['id'] ?? 0) === (int) $booking->id;
    }

    private function moveClaims(Booking $booking, Departure $target, ?string $cabinCode): void
    {
        $active = $booking->claims->filter(
            fn (CabinClaim $claim): bool => $claim->released_at === null,
        );
        $hold = $active->first(fn (CabinClaim $claim): bool => $claim->kind === ClaimKind::Hold);

        $this->claims->release($booking, ReleaseReason::Moved);

        $cabins = $this->targetCabins($booking, $target, $cabinCode);

        try {
            if ($booking->status === BookingStatus::Requested && $hold instanceof CabinClaim) {
                $this->claims->claim(
                    $target,
                    $cabins,
                    $booking,
                    ClaimKind::Hold,
                    $hold->hold_type ?? HoldType::Request,
                    $hold->expires_at,
                );

                return;
            }

            $this->claims->claim($target, $cabins, $booking, ClaimKind::Booking);
        } catch (CabinUnavailableException $exception) {
            throw $this->conflict($exception, $target);
        }
    }

    /**
     * @return Collection<int, Room>
     */
    private function targetCabins(Booking $booking, Departure $target, ?string $cabinCode): Collection
    {
        if ($booking->type === BookingType::Charter) {
            return $target->property->cabins->sortBy('sort')->values();
        }

        $cabin = $target->property->cabins->first(fn (Room $item): bool => $item->code === $cabinCode);

        if (! $cabin instanceof Room) {
            throw ValidationException::withMessages([
                'cabin_code' => ['Pick a cabin on this departure\'s property.'],
            ]);
        }

        return collect([$cabin]);
    }

    private function hasActiveClaim(Booking $booking): bool
    {
        return $booking->claims->contains(
            fn (CabinClaim $claim): bool => $claim->released_at === null,
        );
    }

    /**
     * @return array{departure: string, cabin: string, total: int}
     */
    private function historySnapshot(Booking $booking): array
    {
        $booking->loadMissing(['departure.property', 'cabin']);

        return [
            'departure' => Format::calendar($booking->departure->date).' · '.$booking->departure->property->code,
            'cabin' => $booking->cabinLabel(),
            'total' => $booking->total,
        ];
    }

    private function unavailable(Departure $target, Booking $booking, ?string $cabinCode): CabinUnavailableException
    {
        $snapshots = $this->availability->forDepartures(collect([$target]));

        return $this->conflictFromSnapshot($snapshots[$target->id], $target, $booking, $cabinCode);
    }

    private function conflictFromSnapshot(
        DepartureSnapshot $snapshot,
        Departure $target,
        Booking $booking,
        ?string $cabinCode,
    ): CabinUnavailableException {
        $lines = [];
        $unavailable = [];

        foreach ($snapshot->cabins as $row) {
            if ($booking->type === BookingType::Cabin && $row['cabin']['code'] !== $cabinCode) {
                continue;
            }

            if ($this->rowIsFreeOrOurs($row, $booking)) {
                continue;
            }

            $kind = ClaimKind::tryFrom((string) ($row['claim']['kind'] ?? ClaimKind::Booking->value))
                ?? ClaimKind::Booking;
            $label = (string) $row['cabin']['label'];
            $lines[] = ConflictMessage::line($target, $label, $kind);
            $unavailable[] = [
                'cabin' => [
                    'id' => 0,
                    'code' => (string) $row['cabin']['code'],
                    'label' => $label,
                ],
                'held_by' => [
                    'kind' => $kind->value,
                    'holder_type' => (string) ($row['claim']['holder']['type'] ?? ''),
                    'reference' => $row['claim']['holder']['reference'] ?? null,
                ],
            ];
        }

        return new CabinUnavailableException($unavailable, ConflictMessage::join($lines));
    }

    private function conflict(CabinUnavailableException $exception, Departure $departure): CabinUnavailableException
    {
        $lines = [];

        foreach ($exception->unavailable as $row) {
            $lines[] = ConflictMessage::line(
                $departure,
                $row['cabin']['label'],
                ClaimKind::from($row['held_by']['kind']),
            );
        }

        return new CabinUnavailableException($exception->unavailable, ConflictMessage::join($lines));
    }
}
