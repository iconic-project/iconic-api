<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Actions\Action;
use App\Actions\Contacts\ResolveContact;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CabinCategory;
use App\Enums\CabinState;
use App\Enums\ConfigKind;
use App\Enums\EngineLabelCode;
use App\Enums\HoldRule;
use App\Enums\PreferredChannel;
use App\Enums\ReferenceType;
use App\Events\BookingCreated;
use App\Exceptions\CabinUnavailableException;
use App\Models\AgencyUser;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Contact;
use App\Models\Departure;
use App\Models\Group;
use App\Models\Room;
use App\Services\Config\CurrentConfig;
use App\Services\Engine\EngineFeed;
use App\Services\Inventory\Availability;
use App\Services\Pricing\QuotedParty;
use App\Services\Pricing\ReservationQuoter;
use App\Services\References\ReferenceService;
use App\Support\Bookings\ChannelSeedMap;
use App\Support\Bookings\SoldOn;
use App\Support\BusinessHours;
use App\Support\Commissions\FreezeCommission;
use App\Support\Engine\EngineBookingOwner;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;
use App\Support\Inventory\DepartureSnapshot;
use App\Support\Portal\PortalRequestWords;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class SubmitPortalRequest extends Action
{
    /** @var list<EngineLabelCode> */
    private const UNSALEABLE = [
        EngineLabelCode::Closed,
        EngineLabelCode::Full,
        EngineLabelCode::Charter,
    ];

    public function __construct(
        private readonly ReservationQuoter $quoter,
        private readonly ResolveContact $contacts,
        private readonly ReferenceService $references,
        private readonly CurrentConfig $config,
        private readonly Availability $availability,
        private readonly FreezeCommission $commissions,
        private readonly EngineFeed $feed,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{bookings: Collection<int, Booking>, message: string}
     */
    public function handle(AgencyUser $actor, array $data): array
    {
        $driver = Auth::getDefaultDriver();
        Auth::shouldUse('web');

        try {
            return $this->transaction(fn (): array => $this->submit($actor, $data));
        } finally {
            Auth::shouldUse($driver);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{bookings: Collection<int, Booking>, message: string}
     */
    private function submit(AgencyUser $actor, array $data): array
    {
        $departure = DepartureLocks::lock((int) $data['departure_id']);
        $departure->load(['property.cabins', 'itinerary']);

        abort_unless($this->feed->isVisible($departure), 404);
        abort_if($this->outsideSalesCalendar($departure), 404);

        $snapshots = $this->availability->forDepartures(collect([$departure]));
        $snapshot = $snapshots[$departure->id];
        $this->refuseUnsaleable($snapshot);

        $category = $data['category'] instanceof CabinCategory
            ? $data['category']
            : CabinCategory::from((string) $data['category']);
        /** @var list<array{adults: int, children: int}> $parties */
        $parties = $data['cabins'];
        $picked = $this->freeCabins($departure, $snapshot, $category, count($parties));

        $channels = ChannelSeedMap::fromPrototype('AGENCY');
        $rows = [];

        foreach ($picked as $index => $cabin) {
            $rows[] = [
                'cabin_code' => $cabin->code,
                'adults' => (int) $parties[$index]['adults'],
                'children' => (int) $parties[$index]['children'],
            ];
        }

        $quote = $this->quoter->quote([
            'departure_id' => $departure->id,
            'type' => BookingType::Cabin->value,
            'cabins' => $rows,
            'main_channel' => $channels['main'],
            'online_deposit' => false,
        ], $departure);

        if ($quote->hasErrors()) {
            throw ValidationException::withMessages([
                'cabins' => $quote->errors(),
            ]);
        }

        /** @var array{name: string, email: string} $client */
        $client = $data['client'];
        $contact = $this->contacts->handle([
            'name' => $client['name'],
            'email' => $client['email'],
            'preferred_channel' => PreferredChannel::Email,
        ]);

        $group = $this->resolveGroup($quote->parties, $contact, $departure, $actor);
        $ratesVersion = $this->config->version(ConfigKind::Rates);
        $rules = $this->config->businessRules();
        $owner = EngineBookingOwner::user();
        $submittedAt = now();
        $hold = BusinessHours::fromDocument($rules)->holdExpiry($submittedAt, $departure->stayDates()->checkIn(), $rules);
        $notes = isset($data['notes']) && is_string($data['notes']) && $data['notes'] !== ''
            ? $data['notes']
            : null;
        $label = $actor->name.' ('.$actor->email.')';
        /** @var list<Booking> $created */
        $created = [];

        foreach ($quote->parties as $party) {
            $priced = $party->quote;

            if ($priced === null || ! $party->cabin instanceof Room) {
                throw ValidationException::withMessages([
                    'cabins' => ['A price could not be calculated.'],
                ]);
            }

            $commission = $this->commissions->resolve([
                'agency_id' => $actor->agency_id,
                'main_channel' => $channels['main'],
            ], $departure, $party->cabin->pricingCategory());

            if ($commission === null) {
                throw ValidationException::withMessages([
                    'departure' => ['The agency is not available.'],
                ]);
            }

            $booking = Booking::query()->create([
                'reference' => null,
                'request_reference' => $this->references->next(ReferenceType::Request),
                'type' => BookingType::Cabin,
                'departure_id' => $departure->id,
                'room_id' => $party->cabin->id,
                'contact_id' => $contact->id,
                'group_id' => $group?->id,
                'owner_id' => $owner->id,
                'agency_id' => $commission['agency_id'],
                'commission_pct' => $commission['commission_pct'],
                'commission_approved' => $commission['commission_approved'],
                'status' => $commission['over_cap']
                    ? BookingStatus::OnHoldAgency
                    : BookingStatus::Requested,
                'main_channel' => $channels['main'],
                'channel_of_origin' => $channels['origin'],
                'adults' => $party->adults,
                'children' => $party->children,
                'back_to_back' => false,
                'rates_version_id' => $ratesVersion->id,
                'price_lines' => $priced->toArray()['lines'],
                'total' => $priced->total,
                'deposit_pct' => $priced->depositPct,
                'balance_days' => $this->config->rates()->terms->cabinBalanceDays,
                'promo_code' => null,
                'online_deposit' => false,
                'sold_on' => SoldOn::today(),
            ]);

            BookingRequest::query()->create([
                'booking_id' => $booking->id,
                'preferred_channel' => PreferredChannel::Email,
                'travel_advisor' => true,
                'notes' => $notes,
                'submitted_at' => $submittedAt,
                'sla_due_at' => $submittedAt->copy()->addHours($rules->sla->responseHours),
                'hold_rule' => HoldRule::from($hold->rule),
            ]);

            History::record($booking, 'booking.requested', after: [
                'request_reference' => $booking->request_reference,
                'status' => $booking->status->value,
                'total' => $booking->total,
                'agency_id' => $booking->agency_id,
                'commission_pct' => $booking->commission_pct,
                'client_of_record' => true,
                'what' => 'Booking requested via the agent portal',
            ], actorLabel: $label, extraContext: [
                'agency_user_id' => $actor->id,
            ]);

            $this->commissions->recordHold($booking, $commission);

            BookingCreated::dispatch($booking);

            $created[] = $booking->refresh()->load(['bookingRequest', 'contact', 'agency']);
        }

        $bookings = collect($created);

        $actor->loadMissing('agency');

        History::record($actor->agency, 'portal.request_created', after: [
            'references' => $bookings->map(fn (Booking $booking): ?string => $booking->request_reference)->values()->all(),
            'what' => 'Booking requested via the agent portal',
        ], actorLabel: $label, extraContext: [
            'agency_user_id' => $actor->id,
        ]);

        $first = $created[0] ?? null;

        if (! $first instanceof Booking) {
            throw ValidationException::withMessages([
                'cabins' => ['At least one party is required.'],
            ]);
        }

        return [
            'bookings' => $bookings,
            'message' => PortalRequestWords::forStatus($first->status, $rules->sla->responseHours),
        ];
    }

    private function refuseUnsaleable(DepartureSnapshot $snapshot): void
    {
        $code = EngineLabelCode::tryFrom($snapshot->engineLabel['code']);

        if ($code instanceof EngineLabelCode && in_array($code, self::UNSALEABLE, true)) {
            throw ValidationException::withMessages([
                'departure' => [$snapshot->engineLabel['text']],
            ]);
        }
    }

    /**
     * @return list<Room>
     */
    private function freeCabins(Departure $departure, DepartureSnapshot $snapshot, CabinCategory $category, int $needed): array
    {
        $freeCodes = [];

        foreach ($snapshot->cabins as $row) {
            if ($row['state'] === CabinState::Free->value && $row['cabin']['category'] === $category->value) {
                $freeCodes[] = $row['cabin']['code'];
            }
        }

        $cabins = $departure->property->cabins
            ->filter(fn (Room $cabin): bool => in_array($cabin->code, $freeCodes, true))
            ->sortBy('sort')
            ->values();

        if ($cabins->count() < $needed) {
            throw new CabinUnavailableException([]);
        }

        /** @var list<Room> $picked */
        $picked = $cabins->take($needed)->all();

        return $picked;
    }

    /**
     * @param  list<QuotedParty>  $parties
     */
    private function resolveGroup(array $parties, Contact $contact, Departure $departure, AgencyUser $actor): ?Group
    {
        if (count($parties) < 2) {
            return null;
        }

        $group = Group::query()->create([
            'reference' => $this->references->next(ReferenceType::Group),
            'name' => $contact->name.' group',
            'departure_id' => $departure->id,
            'coordinator_contact_id' => $contact->id,
        ]);

        History::record($group, 'group.created', after: [
            'reference' => $group->reference,
            'name' => $group->name,
            'departure_id' => $group->departure_id,
            'coordinator_contact_id' => $group->coordinator_contact_id,
        ], actorLabel: $actor->name.' ('.$actor->email.')', extraContext: [
            'agency_user_id' => $actor->id,
        ]);

        return $group;
    }

    private function outsideSalesCalendar(Departure $departure): bool
    {
        $calendar = $this->config->engineSettings()->calendar;
        $parts = explode('-', $calendar->defaultSearchFrom);
        $firstIndex = ((int) $parts[0]) * 12 + ((int) ($parts[1] ?? 1)) - 1;
        $lastIndex = $firstIndex + $calendar->horizonMonths - 1;
        $date = $departure->date;
        $index = ((int) $date->format('Y')) * 12 + ((int) $date->format('n')) - 1;

        return $index < $firstIndex || $index > $lastIndex;
    }
}
