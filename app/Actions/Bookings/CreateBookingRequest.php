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
use App\Events\BookingCreated;
use App\Exceptions\CabinUnavailableException;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Cabin;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\QuotedParty;
use App\Services\Pricing\ReservationQuoter;
use App\Services\References\ReferenceService;
use App\Support\Blocks\ConflictMessage;
use App\Support\Bookings\SoldOn;
use App\Support\BusinessHours;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class CreateBookingRequest extends Action
{
    public function __construct(
        private ReservationQuoter $quoter,
        private ResolveContact $contacts,
        private ReferenceService $references,
        private ClaimService $claims,
        private CurrentConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CabinUnavailableException
     */
    public function handle(array $data, User $actor, ?CarbonInterface $referenceAt = null): Booking
    {
        return $this->transaction(function () use ($data, $actor, $referenceAt): Booking {
            $departure = DepartureLocks::lock((int) $data['departure_id']);
            $departure->load(['property.cabins']);

            $type = $data['type'] instanceof BookingType
                ? $data['type']
                : BookingType::from((string) $data['type']);

            if ($type !== BookingType::Cabin) {
                throw ValidationException::withMessages([
                    'type' => ['A request is one cabin.'],
                ]);
            }

            $cabins = $data['cabins'] ?? null;

            if (! is_array($cabins) || count($cabins) !== 1) {
                throw ValidationException::withMessages([
                    'cabins' => ['A request is one cabin.'],
                ]);
            }

            $quote = $this->quoter->quote($data, $departure);

            if ($quote->hasErrors()) {
                throw ValidationException::withMessages([
                    'cabins' => $quote->errors(),
                ]);
            }

            $party = $quote->parties[0] ?? null;

            if (! $party instanceof QuotedParty) {
                throw ValidationException::withMessages([
                    'cabins' => ['Pick a cabin.'],
                ]);
            }

            $cabin = $party->cabin;

            if (! $cabin instanceof Cabin) {
                throw ValidationException::withMessages([
                    'cabins' => ['Pick a cabin.'],
                ]);
            }

            $priced = $party->quote;

            if ($priced === null) {
                throw ValidationException::withMessages([
                    'cabins' => ['A price could not be calculated.'],
                ]);
            }

            $contact = $this->contacts->handle(is_array($data['client'] ?? null) ? $data['client'] : []);
            $ratesVersion = $this->config->version(ConfigKind::Rates);
            $rules = $this->config->businessRules();
            $submittedAt = now();
            $hold = BusinessHours::fromDocument($rules)->holdExpiry($submittedAt, $departure->date, $rules);

            $booking = Booking::query()->create([
                'reference' => null,
                'request_reference' => $this->references->next(ReferenceType::Request, $referenceAt),
                'type' => BookingType::Cabin,
                'departure_id' => $departure->id,
                'cabin_id' => $cabin->id,
                'contact_id' => $contact->id,
                'group_id' => null,
                'owner_id' => $actor->id,
                'status' => BookingStatus::Requested,
                'main_channel' => $data['main_channel'],
                'channel_of_origin' => $data['channel_of_origin'],
                'adults' => $party->adults,
                'children' => $party->children,
                'back_to_back' => $quote->backToBack,
                'rates_version_id' => $ratesVersion->id,
                'price_lines' => $priced->toArray()['lines'],
                'total' => $priced->total,
                'deposit_pct' => $priced->depositPct,
                'balance_days' => $this->config->rates()->terms->cabinBalanceDays,
                'promo_code' => $this->promoCode($data),
                'online_deposit' => (bool) ($data['online_deposit'] ?? false),
                'sold_on' => SoldOn::today(),
                'internal_notes' => isset($data['internal_notes']) && is_string($data['internal_notes'])
                    ? $data['internal_notes']
                    : null,
            ]);

            try {
                $this->claims->claim(
                    $departure,
                    collect([$cabin]),
                    $booking,
                    ClaimKind::Hold,
                    HoldType::Request,
                    $hold->expiresAt,
                );
            } catch (CabinUnavailableException $exception) {
                $lines = [];

                foreach ($exception->unavailable as $row) {
                    $lines[] = ConflictMessage::line(
                        $departure,
                        $row['cabin']['label'],
                        ClaimKind::from($row['held_by']['kind']),
                    );
                }

                throw new CabinUnavailableException($exception->unavailable, ConflictMessage::join($lines));
            }

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
                'hold_rule' => HoldRule::from($hold->rule),
            ]);

            BookingCreated::dispatch($booking);

            History::record($booking, 'booking.requested', after: [
                'request_reference' => $booking->request_reference,
                'status' => $booking->status->value,
                'total' => $booking->total,
            ]);

            return $booking->refresh()->load([
                'departure.property',
                'cabin',
                'contact',
                'owner',
                'bookingRequest',
                'claims',
            ]);
        });
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
