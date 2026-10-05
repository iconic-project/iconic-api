<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Actions\Contacts\ResolveContact;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\ReferenceType;
use App\Events\BookingCreated;
use App\Exceptions\CabinUnavailableException;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Departure;
use App\Models\Group;
use App\Models\Room;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\LegacyDepartureClaims;
use App\Services\Pricing\QuotedParty;
use App\Services\Pricing\ReservationQuote;
use App\Services\Pricing\ReservationQuoter;
use App\Services\References\ReferenceService;
use App\Support\Blocks\ConflictMessage;
use App\Support\Bookings\ReservationCreated;
use App\Support\Bookings\SoldOn;
use App\Support\Commissions\FreezeCommission;
use App\Support\History\History;
use App\Support\Inventory\AppliedRestrictionOverride;
use App\Support\Inventory\DepartureLocks;
use App\Support\Inventory\StaffStayRestrictions;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class CreateReservation extends Action
{
    public function __construct(
        private ReservationQuoter $quoter,
        private ResolveContact $contacts,
        private ReferenceService $references,
        private LegacyDepartureClaims $claims,
        private CurrentConfig $config,
        private FreezeCommission $commissions,
        private StaffStayRestrictions $stayRestrictions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CabinUnavailableException
     */
    public function handle(array $data, User $actor): ReservationCreated
    {
        return $this->transaction(function () use ($data, $actor): ReservationCreated {
            $departure = DepartureLocks::lock((int) $data['departure_id']);
            $departure->load(['property.cabins']);

            $quote = $this->quoter->quote($data, $departure);

            if ($quote->hasErrors()) {
                throw ValidationException::withMessages([
                    'cabins' => $quote->errors(),
                ]);
            }

            $rooms = $quote->type === BookingType::Charter
                ? $departure->property->cabins
                : array_map(
                    fn (QuotedParty $party): Room => $this->requireCabin($party),
                    $quote->parties,
                );
            $override = $this->stayRestrictions->check($rooms, $departure->stayDates(), $actor, $data);

            $contact = $this->contacts->handle($data['client']);
            $group = $this->resolveGroup($data, $quote, $contact, $actor, $departure);
            $ratesVersion = $this->config->version(ConfigKind::Rates);
            $terms = $this->config->rates()->terms;
            $balanceDays = $quote->type === BookingType::Charter
                ? $terms->charterBalanceDays
                : $terms->cabinBalanceDays;

            $bookings = new Collection;

            foreach ($quote->parties as $party) {
                $commission = $this->commissions->resolve(
                    $data,
                    $departure,
                    $party->cabin?->pricingCategory(),
                );

                $booking = $this->createBooking(
                    $quote,
                    $party,
                    $contact,
                    $group,
                    $actor,
                    $ratesVersion->id,
                    $balanceDays,
                    isset($data['internal_notes']) && is_string($data['internal_notes'])
                        ? $data['internal_notes']
                        : null,
                    $data,
                    $commission,
                    $override,
                );

                $cabins = $quote->type === BookingType::Charter
                    ? $departure->property->cabins->sortBy('sort')->values()
                    : collect([$this->requireCabin($party)]);

                try {
                    $this->claims->claim($departure, $cabins, $booking, ClaimKind::Booking);
                } catch (CabinUnavailableException $exception) {
                    throw $this->conflict($exception, $departure);
                }

                $bookings->push($booking);
            }

            return new ReservationCreated($bookings, $group, $quote->warnings);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveGroup(
        array $data,
        ReservationQuote $quote,
        Contact $contact,
        User $actor,
        Departure $departure,
    ): ?Group {
        $groupInput = $data['group'] ?? null;
        $existingId = is_array($groupInput) ? ($groupInput['existing_group_id'] ?? null) : null;
        $name = is_array($groupInput) && isset($groupInput['name'])
            ? trim((string) $groupInput['name'])
            : '';

        if ($quote->type === BookingType::Charter) {
            return null;
        }

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

            if ($group->departure_id !== $departure->id) {
                throw ValidationException::withMessages([
                    'group.existing_group_id' => ['The group is not on this departure.'],
                ]);
            }

            return $group;
        }

        if (count($quote->parties) < 2) {
            return null;
        }

        $group = Group::query()->create([
            'reference' => $this->references->next(ReferenceType::Group),
            'name' => $name !== '' ? $name : $contact->name.' group',
            'departure_id' => $departure->id,
            'coordinator_contact_id' => $contact->id,
        ]);

        History::record($group, 'group.created', after: [
            'reference' => $group->reference,
            'name' => $group->name,
            'departure_id' => $group->departure_id,
            'coordinator_contact_id' => $group->coordinator_contact_id,
        ]);

        return $group;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{agency_id: int, commission_pct: int, commission_approved: bool, over_cap: bool, offer_codes: list<string>}|null  $commission
     */
    private function createBooking(
        ReservationQuote $quote,
        QuotedParty $party,
        Contact $contact,
        ?Group $group,
        User $actor,
        int $ratesVersionId,
        int $balanceDays,
        ?string $notes,
        array $data,
        ?array $commission,
        ?AppliedRestrictionOverride $override = null,
    ): Booking {
        $priced = $party->quote;

        if ($priced === null) {
            throw ValidationException::withMessages([
                'cabins' => ['A price could not be calculated.'],
            ]);
        }

        $booking = Booking::query()->create([
            'reference' => $this->references->next(ReferenceType::Booking),
            'request_reference' => null,
            'type' => $quote->type,
            'departure_id' => $quote->departure->id,
            'room_id' => $party->cabin?->id,
            'contact_id' => $contact->id,
            'group_id' => $group?->id,
            'owner_id' => $actor->id,
            'agency_id' => $commission === null ? null : $commission['agency_id'],
            'commission_pct' => $commission === null ? null : $commission['commission_pct'],
            'commission_approved' => $commission === null ? false : $commission['commission_approved'],
            'status' => $commission !== null && $commission['over_cap']
                ? BookingStatus::OnHoldAgency
                : BookingStatus::PendingPayment,
            'main_channel' => $data['main_channel'],
            'channel_of_origin' => $data['channel_of_origin'],
            'adults' => $party->adults,
            'children' => $party->children,
            'back_to_back' => $quote->backToBack,
            'rates_version_id' => $ratesVersionId,
            'price_lines' => $priced->toArray()['lines'],
            'total' => $priced->total,
            'deposit_pct' => $priced->depositPct,
            'balance_days' => $balanceDays,
            'promo_code' => $this->promoCode($data),
            'online_deposit' => (bool) ($data['online_deposit'] ?? false),
            'sold_on' => SoldOn::today(),
            'internal_notes' => $notes,
        ]);

        $what = 'Reservation created in RMS — '
            .$party->cabinLabel
            .' · '
            .$booking->partyLabel()
            .' · '
            .Money::format($priced->total);

        if ($group instanceof Group) {
            $what .= ' · group '.$group->reference;
        }

        $createdAfter = [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'total' => $booking->total,
            'what' => $what,
        ];

        if ($commission !== null && $commission['offer_codes'] !== []) {
            $createdAfter['commission_pct'] = $commission['commission_pct'];
            $createdAfter['commission_offers'] = $commission['offer_codes'];
        }

        if ($override !== null) {
            $createdAfter['override_restrictions'] = $override->codes;
        }

        History::record($booking, 'booking.created', after: $createdAfter, reason: $override?->reason);

        BookingCreated::dispatch($booking);

        if ($commission !== null) {
            $this->commissions->recordHold($booking, $commission);
        }

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function promoCode(array $data): ?string
    {
        $code = $data['promo_code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return strtoupper(trim($code));
    }

    private function requireCabin(QuotedParty $party): Room
    {
        if (! $party->cabin instanceof Room) {
            throw ValidationException::withMessages([
                'cabins' => ['Pick a cabin for each party.'],
            ]);
        }

        return $party->cabin;
    }

    private function conflict(CabinUnavailableException $exception, Departure $departure): CabinUnavailableException
    {
        $lines = [];

        foreach ($exception->unavailable as $row) {
            $reference = $row['held_by']['reference'] ?? null;
            $lines[] = ConflictMessage::line(
                $row['cabin']['label'],
                $departure->date,
                ClaimKind::from($row['held_by']['kind']),
                is_string($reference) ? $reference : null,
            );
        }

        return new CabinUnavailableException($exception->unavailable, ConflictMessage::join($lines));
    }
}
