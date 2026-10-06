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
use App\Models\Group;
use App\Models\Guest;
use App\Models\Room;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayReservationQuote;
use App\Services\References\ReferenceService;
use App\Support\Bookings\ChannelSeedMap;
use App\Support\Bookings\SoldOn;
use App\Support\BusinessHours;
use App\Support\Engine\EngineBookingOwner;
use App\Support\History\History;
use App\Support\Stays\StayDates;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Turns a stay hold into one booking per room. Pay later stays a request.
 * Pay deposit prices the online-deposit advantage, then Stripe charges the plan deposit.
 */
final class SubmitStayCheckout extends Action
{
    public function __construct(
        private readonly StayQuoter $quoter,
        private readonly ResolveContact $contacts,
        private readonly StitchEngineIdentity $identity,
        private readonly ReferenceService $references,
        private readonly ClaimService $claims,
        private readonly CurrentConfig $config,
        private readonly RecordConsent $consents,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{path: CheckoutPath, bookings: Collection<int, Booking>, email: string}
     */
    public function handle(CheckoutSession $session, array $data, ?string $ip): array
    {
        return $this->transaction(function () use ($session, $data, $ip): array {
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            $checkIn = $session->check_in;
            $checkOut = $session->check_out;

            if ($checkIn === null || $checkOut === null || $session->status !== CheckoutSessionStatus::Holding || $session->expires_at->isPast()) {
                throw new ConflictException('This checkout session is no longer holding rooms.');
            }

            $path = $data['path'] instanceof CheckoutPath
                ? $data['path']
                : CheckoutPath::from((string) $data['path']);
            $guest = $session->guest ?? [];
            $this->assertDeclarations($path, $guest['declarations'] ?? []);

            $stay = StayDates::of($checkIn, $checkOut);
            $specs = $this->specs($session, $path === CheckoutPath::PayDeposit);
            $quote = $this->quoter->quoteRooms($stay, $specs);

            if ($quote->total === null) {
                throw ValidationException::withMessages([
                    'rooms' => ['A price could not be calculated.'],
                ]);
            }

            if ((int) $data['expected_total'] !== $quote->total) {
                throw new PriceChangedException($quote);
            }

            $channel = PreferredChannel::from((string) ($guest['preferred_channel'] ?? PreferredChannel::Email->value));
            $contact = $this->contacts->handle([
                'name' => trim((string) ($guest['first_name'] ?? '').' '.(string) ($guest['last_name'] ?? '')),
                'email' => $guest['email'] ?? null,
                'phone' => $guest['phone'] ?? null,
                'preferred_channel' => $channel,
            ]);

            $sessionId = $data['session_id'] ?? null;
            $this->identity->handle(
                $contact,
                is_string($sessionId) ? $sessionId : null,
                is_array($data['attribution'] ?? null) ? $data['attribution'] : null,
            );

            $owner = EngineBookingOwner::user();
            $channels = ChannelSeedMap::fromPrototype('WEB_DIRECT');
            $rules = $this->config->businessRules();
            $submittedAt = now();
            $hold = BusinessHours::fromDocument($rules)->holdExpiry($submittedAt, $stay->checkIn(), $rules);

            if ($hold->expiresAt->lessThanOrEqualTo($submittedAt->copy()->addMinutes(30))) {
                throw new ConflictException('The request hold must last longer than the online deposit window.');
            }

            $group = $this->group($session, $contact);
            $bookings = new Collection;
            $leadAssigned = false;
            $rooms = $session->rooms ?? [];

            foreach ($rooms as $index => $line) {
                $result = $quote->rooms[$index]['result'] ?? null;

                if (! $result instanceof StayReservationQuote) {
                    throw ValidationException::withMessages([
                        'rooms' => ['A price could not be calculated.'],
                    ]);
                }

                $room = Room::query()->find((int) $line['room_id']);

                if (! $room instanceof Room) {
                    throw ValidationException::withMessages([
                        'rooms' => ['A held room is no longer available.'],
                    ]);
                }

                $booking = $this->createBooking(
                    $stay,
                    $result,
                    $line,
                    $room,
                    $contact,
                    $group,
                    $owner->id,
                    $channels,
                    $path,
                    $session->id,
                );

                $converted = $this->claims->convert(
                    $session,
                    $booking,
                    ClaimKind::Hold,
                    HoldType::Request,
                    $hold->expiresAt,
                    collect([$room]),
                );

                if ($converted === 0) {
                    throw new ConflictException('This checkout session is no longer holding rooms.');
                }

                BookingRequest::query()->create([
                    'booking_id' => $booking->id,
                    'preferred_channel' => $channel,
                    'travel_advisor' => $guest['travel_advisor'],
                    'notes' => $guest['notes'],
                    'submitted_at' => $submittedAt,
                    'sla_due_at' => $submittedAt->copy()->addHours($rules->sla->responseHours),
                    'hold_rule' => HoldRule::from($hold->rule),
                ]);

                $this->createGuests($booking, $contact->name, ! $leadAssigned);
                $leadAssigned = true;
                $this->recordConsents($booking, $path, (bool) ($guest['marketing'] ?? false), $ip);

                History::record($booking, 'booking.requested', after: [
                    'request_reference' => $booking->request_reference,
                    'status' => $booking->status->value,
                    'total' => $booking->total,
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
     * @param  list<string>  $accepted
     */
    private function assertDeclarations(CheckoutPath $path, array $accepted): void
    {
        $required = $path === CheckoutPath::PayDeposit
            ? [ConsentDocument::Terms, ConsentDocument::Cancellation, ConsentDocument::Privacy, ConsentDocument::Insurance]
            : [ConsentDocument::Privacy, ConsentDocument::Insurance];

        foreach ($required as $document) {
            if (! in_array($document->value, $accepted, true)) {
                throw ValidationException::withMessages([
                    'declarations' => [$document->label().' must be accepted.'],
                ]);
            }
        }
    }

    /**
     * @return list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string, online_deposit: bool}>
     */
    private function specs(CheckoutSession $session, bool $onlineDeposit): array
    {
        $specs = [];

        foreach ($session->rooms ?? [] as $line) {
            $ages = [];

            foreach ($line['child_ages'] as $age) {
                $ages[] = (int) $age;
            }

            $specs[] = [
                'room_type' => (string) $line['room_type'],
                'adults' => (int) $line['adults'],
                'child_ages' => $ages,
                'rate_plan' => (string) $line['rate_plan'],
                'online_deposit' => $onlineDeposit,
            ];
        }

        return $specs;
    }

    private function group(CheckoutSession $session, Contact $contact): ?Group
    {
        if (count($session->rooms ?? []) < 2) {
            return null;
        }

        $group = Group::query()->create([
            'reference' => $this->references->next(ReferenceType::Group),
            'name' => $contact->name.' group',
            'coordinator_contact_id' => $contact->id,
        ]);

        History::record($group, 'group.created', after: [
            'reference' => $group->reference,
            'name' => $group->name,
            'coordinator_contact_id' => $group->coordinator_contact_id,
        ], system: true);

        return $group;
    }

    /**
     * @param  array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string, room_id: int}  $line
     * @param  array{main: MainChannel, origin: ChannelOfOrigin}  $channels
     */
    private function createBooking(
        StayDates $stay,
        StayReservationQuote $result,
        array $line,
        Room $room,
        Contact $contact,
        ?Group $group,
        int $ownerId,
        array $channels,
        CheckoutPath $path,
        int $sessionId,
    ): Booking {
        $priced = $result->quote->toArray();
        $type = $result->roomType;
        $ages = [];

        foreach ($line['child_ages'] as $age) {
            $ages[] = (int) $age;
        }

        $booking = Booking::query()->create([
            'reference' => null,
            'request_reference' => $this->references->next(ReferenceType::Request),
            'type' => BookingType::Room,
            'property_id' => $type->property_id,
            'room_type_id' => $type->id,
            'room_id' => $room->id,
            'check_in' => $stay->checkIn()->toDateString(),
            'check_out' => $stay->checkOut()->toDateString(),
            'nights' => $stay->nights(),
            'rate_plan_code' => $line['rate_plan'],
            'night_lines' => $priced['night_lines'],
            'tax_lines' => $priced['tax_lines'],
            'child_ages' => $ages,
            'contact_id' => $contact->id,
            'group_id' => $group?->id,
            'owner_id' => $ownerId,
            'status' => BookingStatus::Requested,
            'main_channel' => $channels['main'],
            'channel_of_origin' => $channels['origin'],
            'adults' => (int) $line['adults'],
            'children' => count($ages),
            'rates_version_id' => $result->quote->ratesVersionId ?? $this->config->version(ConfigKind::Rates)->id,
            'price_lines' => $priced['lines'],
            'total' => $result->quote->total,
            'deposit_pct' => $result->quote->depositPct,
            'balance_days' => $result->quote->terms->balanceDays,
            'online_deposit' => $path === CheckoutPath::PayDeposit,
            'sold_on' => SoldOn::today(),
            'checkout_session_id' => $sessionId,
            'tct_collected' => false,
            'utm_first' => null,
            'utm_last' => null,
        ]);

        BookingCreated::dispatch($booking);

        return $booking;
    }

    private function createGuests(Booking $booking, string $leadName, bool $assignLead): void
    {
        $parts = preg_split('/\s+/', trim($leadName)) ?: [];
        $first = is_string($parts[0] ?? null) ? $parts[0] : '';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        $count = $booking->adults + $booking->children;

        for ($index = 0; $index < $count; $index++) {
            $guest = new Guest;
            $guest->booking_id = $booking->id;
            $guest->position = $index + 1;
            $guest->is_lead = $assignLead && $index === 0;
            $guest->first_name = $guest->is_lead ? $first : '';
            $guest->last_name = $guest->is_lead ? $last : '';
            $guest->ecuador_resident = false;
            $guest->insurance_declared = false;
            $guest->save();
        }
    }

    private function recordConsents(Booking $booking, CheckoutPath $path, bool $marketing, ?string $ip): void
    {
        $required = $path === CheckoutPath::PayDeposit
            ? [ConsentDocument::Terms, ConsentDocument::Cancellation, ConsentDocument::Privacy, ConsentDocument::Insurance]
            : [ConsentDocument::Privacy, ConsentDocument::Insurance];

        foreach ($required as $document) {
            $this->consents->handle($booking, $document, ConsentSource::Engine, ip: $ip);
        }

        if ($marketing) {
            $this->consents->handle($booking, ConsentDocument::Marketing, ConsentSource::Engine, ip: $ip);
        }
    }
}
