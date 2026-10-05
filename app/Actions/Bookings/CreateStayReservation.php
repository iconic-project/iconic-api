<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Actions\Contacts\ResolveContact;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\HoldRule;
use App\Enums\HoldType;
use App\Enums\PreferredChannel;
use App\Enums\ReferenceType;
use App\Enums\RoomStatus;
use App\Enums\RoomTypeStatus;
use App\Events\BookingCreated;
use App\Exceptions\PriceChangedException;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Bookability;
use App\Services\Inventory\ClaimService;
use App\Services\Inventory\Restrictions;
use App\Services\Pricing\GuestsInvalid;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayReservationQuote;
use App\Services\Pricing\StayRoomsQuote;
use App\Services\References\ReferenceService;
use App\Support\Bookings\ReservationCreated;
use App\Support\Bookings\SoldOn;
use App\Support\BusinessHours;
use App\Support\Commissions\FreezeCommission;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\History\History;
use App\Support\Inventory\AppliedRestrictionOverride;
use App\Support\Inventory\StaffStayRestrictions;
use App\Support\Money;
use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One room is one booking. Several rooms are one group and N bookings.
 * Dates may differ per room. The yacht quoter is not used.
 */
final class CreateStayReservation extends Action
{
    public function __construct(
        private StayQuoter $quoter,
        private Restrictions $restrictions,
        private StaffStayRestrictions $staffRestrictions,
        private ClaimService $claims,
        private ResolveContact $contacts,
        private ReferenceService $references,
        private CurrentConfig $config,
        private FreezeCommission $commissions,
        private StayClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): ReservationCreated
    {
        /** @var ReservationCreated $created */
        $created = $this->transaction(fn (): ReservationCreated => $this->sell($data, $actor));

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(array $data, User $actor, ?CarbonInterface $referenceAt = null): Booking
    {
        /** @var Booking $booking */
        $booking = $this->transaction(fn (): Booking => $this->hold($data, $actor, $referenceAt));

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sell(array $data, User $actor): ReservationCreated
    {
        $prepared = $this->prepare($data, $actor);
        $this->assertPrice($data, $prepared['quote']);

        $contact = $this->contacts->handle(is_array($data['client'] ?? null) ? $data['client'] : []);
        $group = $this->resolveGroup($data, $prepared['rows'], $contact, $actor);
        $commission = $this->commissions->resolve($data, null, null);
        $notes = isset($data['internal_notes']) && is_string($data['internal_notes'])
            ? $data['internal_notes']
            : null;
        $arrival = $this->arrivalTime($data);

        $bookings = new Collection;
        $warnings = [];

        foreach ($prepared['rows'] as $row) {
            $booking = $this->insertBooking(
                $row,
                $data,
                $contact,
                $group,
                $actor,
                $commission,
                $notes,
                $arrival,
                sale: true,
                referenceAt: null,
            );
            $room = $this->place($booking, $row, ClaimKind::Booking, null, null);
            $this->recordSale($booking, $room, $group, $commission, $prepared['override']);
            $bookings->push($booking);
            $warnings = array_merge($warnings, $row['quote']->warnings);
        }

        return new ReservationCreated($bookings, $group, $warnings);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hold(array $data, User $actor, ?CarbonInterface $referenceAt): Booking
    {
        $rooms = $data['rooms'] ?? null;

        if (! is_array($rooms) || count($rooms) !== 1) {
            throw ValidationException::withMessages([
                'rooms' => ['A request is one room.'],
            ]);
        }

        $prepared = $this->prepare($data, $actor);

        if (array_key_exists('expected_total', $data)) {
            $this->assertPrice($data, $prepared['quote']);
        }

        $row = $prepared['rows'][0];
        $contact = $this->contacts->handle(is_array($data['client'] ?? null) ? $data['client'] : []);
        $rules = $this->config->businessRules();
        $submittedAt = now();
        $expiry = BusinessHours::fromDocument($rules)->holdExpiry($submittedAt, $row['stay']->checkIn(), $rules);

        $booking = $this->insertBooking(
            $row,
            $data,
            $contact,
            null,
            $actor,
            null,
            isset($data['internal_notes']) && is_string($data['internal_notes']) ? $data['internal_notes'] : null,
            $this->arrivalTime($data),
            sale: false,
            referenceAt: $referenceAt,
        );

        $this->place($booking, $row, ClaimKind::Hold, HoldType::Request, $expiry->expiresAt);

        $channel = $data['preferred_channel'] ?? PreferredChannel::Email;
        $channel = $channel instanceof PreferredChannel
            ? $channel
            : PreferredChannel::from((string) $channel);

        BookingRequest::query()->create([
            'booking_id' => $booking->id,
            'preferred_channel' => $channel,
            'travel_advisor' => (bool) ($data['travel_advisor'] ?? false),
            'notes' => isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : null,
            'submitted_at' => $submittedAt,
            'sla_due_at' => $submittedAt->copy()->addHours($rules->sla->responseHours),
            'hold_rule' => HoldRule::from($expiry->rule),
        ]);

        BookingCreated::dispatch($booking);

        $after = [
            'request_reference' => $booking->request_reference,
            'status' => $booking->status->value,
            'total' => $booking->total,
        ];

        if ($prepared['override'] !== null) {
            $after['override_restrictions'] = $prepared['override']->codes;
        }

        History::record($booking, 'booking.requested', after: $after, reason: $prepared['override']?->reason);

        return $booking->refresh()->load([
            'room',
            'roomType',
            'property',
            'contact',
            'owner',
            'bookingRequest',
            'claims',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     rows: list<array{spec: array{room_type: string, room_id: int|null, adults: int, child_ages: list<int>, rate_plan: string|null, promo: string|null, online_deposit: bool, check_in: string|null, check_out: string|null}, stay: StayDates, type: RoomType, quote: StayReservationQuote, plan: string}>,
     *     quote: StayRoomsQuote,
     *     override: AppliedRestrictionOverride|null
     * }
     */
    private function prepare(array $data, User $actor): array
    {
        $top = StayDates::of((string) $data['check_in'], (string) $data['check_out']);
        $this->assertLength($top, 'check_out');
        $specs = $this->specs($data);
        $rows = [];
        $quoted = [];
        $reasons = [];
        $errors = [];

        foreach ($specs as $index => $spec) {
            $stay = $this->stayFor($spec, $top, $index);
            $type = RoomType::query()
                ->where('code', $spec['room_type'])
                ->where('status', RoomTypeStatus::Active)
                ->orderBy('id')
                ->first();

            if (! $type instanceof RoomType) {
                $errors['rooms.'.$index.'.room_type'] = ['No room type '.$spec['room_type'].'.'];

                continue;
            }

            foreach ($this->restrictions->evaluate($type, $stay)->reasons as $reason) {
                $reasons[] = $reason;
            }

            $plan = $this->planCode($spec['rate_plan']);
            $bundle = $this->quoter->quoteRooms($stay, [[
                'room_type' => $spec['room_type'],
                'adults' => $spec['adults'],
                'child_ages' => $spec['child_ages'],
                'rate_plan' => $plan,
                'promo' => $spec['promo'],
                'online_deposit' => $spec['online_deposit'],
            ]]);
            $result = $bundle->rooms[0]['result'] ?? null;

            if ($result instanceof StayReservationQuote) {
                $rows[] = [
                    'spec' => $spec,
                    'stay' => $stay,
                    'type' => $type,
                    'quote' => $result,
                    'plan' => $plan,
                ];
                $quoted[] = [
                    'room_type' => $type->code,
                    'result' => $result,
                ];

                continue;
            }

            $errors['rooms.'.$index] = $this->quoteErrors($result, $spec['room_type']);
        }

        $override = $this->staffRestrictions->decide(Bookability::ordered($reasons), $actor, $data);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $total = 0;
        $deposit = 0;
        $withTax = 0;

        foreach ($rows as $row) {
            $total += $row['quote']->quote->total;
            $deposit += $row['quote']->quote->deposit;
            $withTax += $row['quote']->quote->totalIncludingChargedTaxes;
        }

        return [
            'rows' => $rows,
            'quote' => new StayRoomsQuote($top, $quoted, $total, $deposit, $withTax),
            'override' => $override,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPrice(array $data, StayRoomsQuote $quote): void
    {
        if ((int) $data['expected_total'] !== $quote->total) {
            throw new PriceChangedException($quote);
        }
    }

    /**
     * @param  array{spec: array{room_id: int|null, room_type: string}, stay: StayDates, type: RoomType}  $row
     */
    private function place(
        Booking $booking,
        array $row,
        ClaimKind $kind,
        ?HoldType $holdType,
        ?CarbonInterface $expiresAt,
    ): Room {
        $roomId = $row['spec']['room_id'];

        if ($roomId !== null) {
            $room = Room::query()->find($roomId);

            if (! $room instanceof Room || $room->room_type_id !== $row['type']->id || $room->status !== RoomStatus::Active) {
                throw ValidationException::withMessages([
                    'rooms' => ['Pick an active room of type '.$row['spec']['room_type'].'.'],
                ]);
            }

            $this->claims->claim($row['stay'], new Collection([$room]), $booking, $kind, $holdType, $expiresAt);

            if ($booking->room_id !== $room->id) {
                $booking->room_id = $room->id;
                $booking->save();
            }

            return $room;
        }

        $claimed = $this->claims->claimType($row['stay'], $row['type'], 1, $booking, $kind, $holdType, $expiresAt);
        $picked = Room::query()->find((int) $claimed->first()?->room_id);

        if (! $picked instanceof Room) {
            throw ValidationException::withMessages([
                'rooms' => [$row['type']->name.' is unavailable.'],
            ]);
        }

        $booking->room_id = $picked->id;
        $booking->save();

        return $picked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{spec: array{room_type: string}}>  $rows
     */
    private function resolveGroup(array $data, array $rows, Contact $contact, User $actor): ?Group
    {
        $groupInput = $data['group'] ?? null;
        $existingId = is_array($groupInput) ? ($groupInput['existing_group_id'] ?? null) : null;
        $name = is_array($groupInput) && isset($groupInput['name'])
            ? trim((string) $groupInput['name'])
            : '';

        if ($existingId !== null && $existingId !== '') {
            $group = Group::query()
                ->visibleTo($actor)
                ->whereKey((int) $existingId)
                ->first();

            if (! $group instanceof Group) {
                throw ValidationException::withMessages([
                    'group.existing_group_id' => ['This group is not available.'],
                ]);
            }

            return $group;
        }

        if (count($rows) < 2) {
            return null;
        }

        $group = Group::query()->create([
            'reference' => $this->references->next(ReferenceType::Group),
            'name' => $name !== '' ? $name : $contact->name.' group',
            'departure_id' => null,
            'coordinator_contact_id' => $contact->id,
        ]);

        History::record($group, 'group.created', after: [
            'reference' => $group->reference,
            'name' => $group->name,
            'departure_id' => null,
            'coordinator_contact_id' => $group->coordinator_contact_id,
        ]);

        return $group;
    }

    /**
     * @param  array{spec: array{room_id: int|null, adults: int, child_ages: list<int>, promo: string|null, online_deposit: bool}, stay: StayDates, type: RoomType, quote: StayReservationQuote, plan: string}  $row
     * @param  array<string, mixed>  $data
     * @param  array{agency_id: int, commission_pct: int, commission_approved: bool, over_cap: bool, offer_codes: list<string>}|null  $commission
     */
    private function insertBooking(
        array $row,
        array $data,
        Contact $contact,
        ?Group $group,
        User $actor,
        ?array $commission,
        ?string $notes,
        ?string $arrival,
        bool $sale,
        ?CarbonInterface $referenceAt,
    ): Booking {
        $quote = $row['quote']->quote;
        $overCap = $commission !== null && $commission['over_cap'];

        return Booking::query()->create([
            'reference' => $sale ? $this->references->next(ReferenceType::Booking) : null,
            'request_reference' => $sale ? null : $this->references->next(ReferenceType::Request, $referenceAt),
            'type' => BookingType::Cabin,
            'departure_id' => null,
            'property_id' => $row['type']->property_id,
            'room_type_id' => $row['type']->id,
            'room_id' => $row['spec']['room_id'] ?? null,
            'check_in' => $row['stay']->checkIn()->toDateString(),
            'check_out' => $row['stay']->checkOut()->toDateString(),
            'nights' => $row['stay']->nights(),
            'rate_plan_code' => $row['plan'],
            'night_lines' => $quote->toArray()['night_lines'],
            'tax_lines' => $quote->toArray()['tax_lines'],
            'child_ages' => $row['spec']['child_ages'],
            'expected_arrival_time' => $arrival,
            'contact_id' => $contact->id,
            'group_id' => $group?->id,
            'owner_id' => $actor->id,
            'agency_id' => $commission === null ? null : $commission['agency_id'],
            'commission_pct' => $commission === null ? null : $commission['commission_pct'],
            'commission_approved' => $commission === null ? false : $commission['commission_approved'],
            'status' => $sale
                ? ($overCap ? BookingStatus::OnHoldAgency : BookingStatus::PendingPayment)
                : BookingStatus::Requested,
            'main_channel' => $data['main_channel'],
            'channel_of_origin' => $data['channel_of_origin'],
            'adults' => $row['spec']['adults'],
            'children' => count($row['spec']['child_ages']),
            'back_to_back' => false,
            'rates_version_id' => $quote->ratesVersionId ?? $this->config->version(ConfigKind::Rates)->id,
            'price_lines' => $quote->toArray()['lines'],
            'total' => $quote->total,
            'deposit_pct' => $quote->depositPct,
            'balance_days' => $quote->terms->balanceDays,
            'promo_code' => $row['spec']['promo'] === null ? null : strtoupper($row['spec']['promo']),
            'online_deposit' => $row['spec']['online_deposit'],
            'sold_on' => SoldOn::today(),
            'internal_notes' => $notes,
        ]);
    }

    /**
     * @param  array{agency_id: int, commission_pct: int, commission_approved: bool, over_cap: bool, offer_codes: list<string>}|null  $commission
     */
    private function recordSale(
        Booking $booking,
        Room $room,
        ?Group $group,
        ?array $commission,
        ?AppliedRestrictionOverride $override,
    ): void {
        $what = 'Reservation created in RMS — '
            .$room->label
            .' · '
            .$booking->partyLabel()
            .' · '
            .Money::format($booking->total);

        if ($group instanceof Group) {
            $what .= ' · group '.$group->reference;
        }

        $after = [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'total' => $booking->total,
            'what' => $what,
        ];

        if ($commission !== null && $commission['offer_codes'] !== []) {
            $after['commission_pct'] = $commission['commission_pct'];
            $after['commission_offers'] = $commission['offer_codes'];
        }

        if ($override !== null) {
            $after['override_restrictions'] = $override->codes;
        }

        History::record($booking, 'booking.created', after: $after, reason: $override?->reason);
        BookingCreated::dispatch($booking);

        if ($commission !== null) {
            $this->commissions->recordHold($booking, $commission);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{room_type: string, room_id: int|null, adults: int, child_ages: list<int>, rate_plan: string|null, promo: string|null, online_deposit: bool, check_in: string|null, check_out: string|null}>
     */
    private function specs(array $data): array
    {
        $rooms = $data['rooms'] ?? null;

        if (! is_array($rooms) || $rooms === []) {
            throw ValidationException::withMessages([
                'rooms' => ['Pick at least one room.'],
            ]);
        }

        $max = $this->config->businessRules()->stay->maxRoomsPerBooking;

        if (count($rooms) > $max) {
            throw ValidationException::withMessages([
                'rooms' => ['A booking can include at most '.$max.' rooms.'],
            ]);
        }

        $specs = [];

        foreach (array_values($rooms) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'rooms.'.$index => ['Each room needs a type and a party.'],
                ]);
            }

            $code = $row['room_type'] ?? null;

            if (! is_string($code) || $code === '') {
                throw ValidationException::withMessages([
                    'rooms.'.$index.'.room_type' => ['Pick a room type.'],
                ]);
            }

            $ages = [];

            foreach ($row['child_ages'] ?? [] as $age) {
                $ages[] = (int) $age;
            }

            $plan = $row['rate_plan'] ?? null;
            $promo = $row['promo'] ?? null;
            $roomId = $row['room_id'] ?? null;

            $specs[] = [
                'room_type' => $code,
                'room_id' => is_numeric($roomId) ? (int) $roomId : null,
                'adults' => (int) ($row['adults'] ?? 0),
                'child_ages' => $ages,
                'rate_plan' => is_string($plan) && $plan !== '' ? $plan : null,
                'promo' => is_string($promo) && $promo !== '' ? $promo : null,
                'online_deposit' => (bool) ($row['online_deposit'] ?? false),
                'check_in' => isset($row['check_in']) && is_string($row['check_in']) ? $row['check_in'] : null,
                'check_out' => isset($row['check_out']) && is_string($row['check_out']) ? $row['check_out'] : null,
            ];
        }

        return $specs;
    }

    /**
     * @param  array{check_in: string|null, check_out: string|null}  $spec
     */
    private function stayFor(array $spec, StayDates $top, int $index): StayDates
    {
        if ($spec['check_in'] === null && $spec['check_out'] === null) {
            return $top;
        }

        if ($spec['check_in'] === null || $spec['check_out'] === null) {
            throw ValidationException::withMessages([
                'rooms.'.$index.'.check_out' => ['Give both check-in and check-out for this room.'],
            ]);
        }

        try {
            $stay = StayDates::of($spec['check_in'], $spec['check_out']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'rooms.'.$index.'.check_out' => ['Check-out must be after check-in.'],
            ]);
        }

        $this->assertLength($stay, 'rooms.'.$index.'.check_out');

        return $stay;
    }

    private function assertLength(StayDates $stay, string $key): void
    {
        $max = $this->clock->maxNights();

        if ($stay->nights() > $max) {
            throw ValidationException::withMessages([
                $key => ['A stay cannot be longer than '.$max.' nights.'],
            ]);
        }
    }

    private function planCode(?string $requested): string
    {
        if ($requested !== null && $requested !== '') {
            return $requested;
        }

        foreach ($this->config->rates()->ratePlans as $plan) {
            if ($plan->isDefault) {
                return $plan->code;
            }
        }

        $first = $this->config->rates()->ratePlans[0] ?? null;

        return $first instanceof RatePlan ? $first->code : '';
    }

    /**
     * @return list<string>
     */
    private function quoteErrors(mixed $result, string $code): array
    {
        if ($result instanceof GuestsInvalid) {
            return $result->errors;
        }

        if ($result instanceof NoRate) {
            return [$result->reason];
        }

        return ['A price could not be calculated for '.$code.'.'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function arrivalTime(array $data): ?string
    {
        $time = $data['expected_arrival_time'] ?? null;

        return is_string($time) && $time !== '' ? $time : null;
    }
}
