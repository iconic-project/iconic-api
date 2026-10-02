<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Actions\Consents\RecordConsent;
use App\Actions\Contacts\ResolveContact;
use App\Actions\Contacts\StitchEngineIdentity;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChannelOfOrigin;
use App\Enums\CheckoutPath;
use App\Enums\CheckoutSessionStatus;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\ConsentDocument;
use App\Enums\ConsentSource;
use App\Enums\HoldRule;
use App\Enums\HoldType;
use App\Enums\MainChannel;
use App\Enums\PreferredChannel;
use App\Enums\ReferenceType;
use App\Events\BookingCreated;
use App\Exceptions\ConflictException;
use App\Exceptions\PriceChangedException;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\CheckoutSession;
use App\Models\Contact;
use App\Models\Departure;
use App\Models\Group;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\QuotedParty;
use App\Services\Pricing\ReservationQuote;
use App\Services\Pricing\ReservationQuoter;
use App\Services\References\ReferenceService;
use App\Support\Bookings\ChannelSeedMap;
use App\Support\Bookings\SoldOn;
use App\Support\BusinessHours;
use App\Support\Crm\AttributionTouch;
use App\Support\Engine\EngineBookingOwner;
use App\Support\Guests\ApplyPng;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class SubmitEngineCheckout extends Action
{
    public function __construct(
        private readonly ReservationQuoter $quoter,
        private readonly ResolveContact $contacts,
        private readonly StitchEngineIdentity $identity,
        private readonly ReferenceService $references,
        private readonly ClaimService $claims,
        private readonly CurrentConfig $config,
        private readonly RecordConsent $consents,
        private readonly ApplyPng $png,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{path: CheckoutPath, bookings: Collection<int, Booking>, email: string}
     */
    public function handle(CheckoutSession $session, array $data, ?string $ip): array
    {
        return $this->transaction(function () use ($session, $data, $ip): array {
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $departure = DepartureLocks::lock((int) $session->departure_id);
            $departure->load(['property.cabins', 'itinerary']);

            if ($session->status !== CheckoutSessionStatus::Holding || $session->expires_at->isPast()) {
                throw new ConflictException('This checkout session is no longer holding cabins.');
            }

            $path = $data['path'] instanceof CheckoutPath
                ? $data['path']
                : CheckoutPath::from((string) $data['path']);

            $quote = $this->quoter->quote([
                'departure_id' => $departure->id,
                'type' => BookingType::Cabin->value,
                'cabins' => $session->cabins,
                'online_deposit' => $path === CheckoutPath::PayDeposit,
                'promo_code' => $data['promo_code'] ?? null,
            ], $departure);

            if ($quote->hasErrors() || $quote->total() === null) {
                throw ValidationException::withMessages([
                    'cabins' => $quote->errors() !== [] ? $quote->errors() : ['A price could not be calculated.'],
                ]);
            }

            if ((int) $data['expected_total'] !== $quote->total()) {
                throw new PriceChangedException($quote);
            }

            $channel = $data['preferred_channel'] ?? PreferredChannel::Email;
            $channel = $channel instanceof PreferredChannel
                ? $channel
                : PreferredChannel::from((string) $channel);

            $contact = $this->contacts->handle([
                'name' => trim((string) $data['first_name'].' '.(string) $data['last_name']),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'preferred_channel' => $channel,
            ]);

            $this->identity->handle(
                $contact,
                isset($data['session_id']) && is_string($data['session_id']) ? $data['session_id'] : null,
                is_array($data['attribution'] ?? null) ? $data['attribution'] : null,
            );

            $group = $this->resolveGroup($quote, $contact, $departure);
            $owner = EngineBookingOwner::user();
            $channels = ChannelSeedMap::fromPrototype('WEB_DIRECT');
            $ratesVersion = $this->config->version(ConfigKind::Rates);
            $rules = $this->config->businessRules();
            $submittedAt = now();
            $hold = BusinessHours::fromDocument($rules)->holdExpiry($submittedAt, $departure->date, $rules);

            if ($hold->expiresAt->lessThanOrEqualTo($submittedAt->copy()->addMinutes(30))) {
                throw new ConflictException('The request hold must last longer than the online deposit window.');
            }

            $guestsByCabin = $this->guestsByCabin($data['guests'] ?? []);
            $bookings = new Collection;
            $leadAssigned = false;

            foreach ($quote->parties as $party) {
                $booking = $this->createBooking(
                    $session,
                    $quote,
                    $party,
                    $contact,
                    $group,
                    $owner->id,
                    $ratesVersion->id,
                    $channels,
                    $data,
                    $path,
                );

                $cabin = $party->cabin;

                if ($cabin === null) {
                    throw ValidationException::withMessages([
                        'cabins' => ['Pick a cabin.'],
                    ]);
                }

                $this->claims->convert(
                    $session,
                    $booking,
                    ClaimKind::Hold,
                    HoldType::Request,
                    $hold->expiresAt,
                    collect([$cabin]),
                );

                BookingRequest::query()->create([
                    'booking_id' => $booking->id,
                    'preferred_channel' => $channel,
                    'travel_advisor' => (bool) ($data['travel_advisor'] ?? false),
                    'notes' => isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : null,
                    'submitted_at' => $submittedAt,
                    'sla_due_at' => $submittedAt->copy()->addHours($rules->sla->responseHours),
                    'hold_rule' => HoldRule::from($hold->rule),
                ]);

                $this->createGuests(
                    $booking,
                    $guestsByCabin[$cabin->code] ?? [],
                    $contact->name,
                    ! $leadAssigned,
                );
                $leadAssigned = true;

                $this->recordConsents($booking, $path, (bool) ($data['marketing'] ?? false), $ip);

                History::record($booking, 'booking.requested', after: [
                    'request_reference' => $booking->request_reference,
                    'status' => $booking->status->value,
                    'total' => $booking->total,
                    'utm_first' => $booking->utm_first,
                    'utm_last' => $booking->utm_last,
                    'what' => 'Booking requested via the booking engine',
                ], system: true);

                $bookings->push($booking->refresh()->load(['bookingRequest', 'guests', 'contact', 'claims']));
            }

            $session->status = CheckoutSessionStatus::Submitted;
            $session->path = $path;
            $session->save();

            return [
                'path' => $path,
                'bookings' => $bookings,
                'email' => (string) $contact->email,
            ];
        });
    }

    /**
     * @param  array{main: MainChannel, origin: ChannelOfOrigin}  $channels
     * @param  array<string, mixed>  $data
     */
    private function createBooking(
        CheckoutSession $session,
        ReservationQuote $quote,
        QuotedParty $party,
        Contact $contact,
        ?Group $group,
        int $ownerId,
        int $ratesVersionId,
        array $channels,
        array $data,
        CheckoutPath $path,
    ): Booking {
        $priced = $party->quote;

        if ($priced === null) {
            throw ValidationException::withMessages([
                'cabins' => ['A price could not be calculated.'],
            ]);
        }

        $tctCollected = (bool) ($data['tct_collected'] ?? false);

        $booking = Booking::query()->create([
            'reference' => null,
            'request_reference' => $this->references->next(ReferenceType::Request),
            'type' => BookingType::Cabin,
            'departure_id' => $quote->departure->id,
            'room_id' => $party->cabin?->id,
            'contact_id' => $contact->id,
            'group_id' => $group?->id,
            'owner_id' => $ownerId,
            'status' => BookingStatus::Requested,
            'main_channel' => $channels['main'],
            'channel_of_origin' => $channels['origin'],
            'adults' => $party->adults,
            'children' => $party->children,
            'back_to_back' => $quote->backToBack,
            'rates_version_id' => $ratesVersionId,
            'price_lines' => $priced->toArray()['lines'],
            'total' => $priced->total,
            'deposit_pct' => $priced->depositPct,
            'balance_days' => $this->config->rates()->terms->cabinBalanceDays,
            'promo_code' => $this->promoCode($data),
            'online_deposit' => $path === CheckoutPath::PayDeposit,
            'sold_on' => SoldOn::today(),
            'utm_first' => AttributionTouch::from(
                is_array($data['attribution'] ?? null) ? ($data['attribution']['first_touch'] ?? null) : null,
            ),
            'utm_last' => AttributionTouch::from(
                is_array($data['attribution'] ?? null) ? ($data['attribution']['last_touch'] ?? null) : null,
            ),
            'checkout_session_id' => $session->id,
            'png_collected' => (bool) ($data['png_collected'] ?? false),
            'tct_collected' => $tctCollected,
            'tct_rate_usd' => $tctCollected
                ? $this->config->engineSettings()->fees->tctPp
                : null,
        ]);

        BookingCreated::dispatch($booking);

        return $booking;
    }

    private function resolveGroup(ReservationQuote $quote, Contact $contact, Departure $departure): ?Group
    {
        if (count($quote->parties) < 2) {
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
        ], system: true);

        return $group;
    }

    /**
     * @param  list<array{cabin_code: string, nationality: string, ecuador_resident: bool}>  $guests
     * @return array<string, list<array{nationality: string, ecuador_resident: bool}>>
     */
    private function guestsByCabin(array $guests): array
    {
        $grouped = [];

        foreach ($guests as $guest) {
            $code = (string) $guest['cabin_code'];
            $grouped[$code][] = [
                'nationality' => strtoupper((string) $guest['nationality']),
                'ecuador_resident' => (bool) $guest['ecuador_resident'],
            ];
        }

        return $grouped;
    }

    /**
     * @param  list<array{nationality: string, ecuador_resident: bool}>  $guests
     */
    private function createGuests(Booking $booking, array $guests, string $leadName, bool $assignLead): void
    {
        $parts = preg_split('/\s+/', trim($leadName)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        foreach ($guests as $index => $row) {
            $guest = new Guest;
            $guest->booking_id = $booking->id;
            $guest->position = $index + 1;
            $guest->is_lead = $assignLead && $index === 0;
            $guest->first_name = $guest->is_lead ? $first : '';
            $guest->last_name = $guest->is_lead ? $last : '';
            $guest->nationality = $row['nationality'];
            $guest->ecuador_resident = $row['ecuador_resident'];
            $guest->insurance_declared = false;
            $this->png->toGuest($guest, $booking);
            $guest->save();
        }
    }

    private function recordConsents(Booking $booking, CheckoutPath $path, bool $marketing, ?string $ip): void
    {
        $required = $path === CheckoutPath::PayDeposit
            ? [
                ConsentDocument::Terms,
                ConsentDocument::Cancellation,
                ConsentDocument::Privacy,
                ConsentDocument::Insurance,
            ]
            : [
                ConsentDocument::Privacy,
                ConsentDocument::Insurance,
            ];

        foreach ($required as $document) {
            $this->consents->handle($booking, $document, ConsentSource::Engine, ip: $ip);
        }

        if ($marketing) {
            $this->consents->handle($booking, ConsentDocument::Marketing, ConsentSource::Engine, ip: $ip);
        }
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
}
