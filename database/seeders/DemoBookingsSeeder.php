<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Contacts\ResolveContact;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PreferredChannel;
use App\Enums\ReferenceType;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Group;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\CabinPricer;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\Quote;
use App\Services\Pricing\QuoteInput;
use App\Services\References\ReferenceService;
use App\Support\Bookings\ChannelSeedMap;
use App\Support\Bookings\SoldOn;
use App\Support\Bookings\StayFromDeparture;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;
use App\Support\Payments\InsertLedgerRow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class DemoBookingsSeeder extends Seeder
{
    /**
     * @var list<array{reference: string, seed_total: int, calculator_total: int}>
     */
    public static array $priceDifferences = [];

    public function run(): void
    {
        return;
    }

    public function runYachtSeedDisabled(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        self::$priceDifferences = [];

        $seed = $this->seedFile();
        $departures = $this->anamaraByDateIndex($seed['departures'] ?? []);
        $groupRow = $seed['groups'][0] ?? null;
        $bookings = array_values(array_filter(
            $seed['bookings'] ?? [],
            fn (array $row): bool => ($row['st'] ?? '') !== 'REQUESTED',
        ));

        $departureIds = [];

        foreach ($bookings as $row) {
            $departureIds[] = $this->departureFor((int) $row['dep'], $departures)->id;
        }

        DB::transaction(function () use ($bookings, $departures, $groupRow, $departureIds, $seed): void {
            DepartureLocks::lockMany($departureIds);

            $group = is_array($groupRow) ? $this->seedGroup($groupRow, $departures) : null;

            foreach ($bookings as $row) {
                $this->seedBooking($row, $departures, $group);
            }

            $this->seedPayments($seed['payments'] ?? []);

            app(ReferenceService::class)->ensureAtLeast(ReferenceType::Group, 7);
            app(ReferenceService::class)->ensureAtLeast(ReferenceType::Booking, 19, 2026);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, Departure>  $departures
     */
    private function seedGroup(array $row, array $departures): Group
    {
        $coord = is_array($row['coord'] ?? null) ? $row['coord'] : [];
        $contact = app(ResolveContact::class)->handle([
            'name' => (string) ($coord['name'] ?? 'Coordinator'),
            'email' => isset($coord['email']) ? (string) $coord['email'] : null,
            'phone' => isset($coord['phone']) ? (string) $coord['phone'] : null,
            'preferred_channel' => $this->preferredChannel($coord['pc'] ?? null),
        ]);

        $departure = $this->departureFor(3, $departures);

        $group = Group::query()->firstOrCreate(
            ['reference' => (string) $row['id']],
            [
                'name' => (string) $row['name'],
                'departure_id' => $departure->id,
                'coordinator_contact_id' => $contact->id,
            ],
        );

        if ($group->wasRecentlyCreated) {
            History::record($group, 'group.created', after: [
                'reference' => $group->reference,
                'name' => $group->name,
            ]);
        }

        return $group;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, Departure>  $departures
     */
    private function seedBooking(array $row, array $departures, ?Group $group): void
    {
        $departure = $this->departureFor((int) $row['dep'], $departures);
        $type = BookingType::from((string) $row['type']);
        $status = $this->status((string) $row['st']);
        $channels = ChannelSeedMap::fromPrototype((string) $row['chan']);
        $quote = $this->price($departure, $type, $row);
        $seedTotal = (int) $row['total'];

        if ($quote->total !== $seedTotal) {
            self::$priceDifferences[] = [
                'reference' => (string) $row['id'],
                'seed_total' => $seedTotal,
                'calculator_total' => $quote->total,
            ];
        }

        $contact = app(ResolveContact::class)->handle([
            'name' => (string) $row['guest'],
            'email' => $this->leadEmail($row),
        ]);

        $cabin = $this->cabin($departure, $row);
        $terms = app(CurrentConfig::class)->rates()->terms;

        $booking = Booking::query()->firstOrCreate(
            ['reference' => (string) $row['id']],
            [
                'request_reference' => null,
                'type' => $type,
                ...StayFromDeparture::columns($departure, $cabin),
                'room_id' => $cabin?->id,
                'contact_id' => $contact->id,
                'group_id' => ($row['grp'] ?? null) === 'GRP-007' ? $group?->id : null,
                'owner_id' => $this->owner((string) $row['owner'])->id,
                'status' => $status,
                'main_channel' => $channels['main'],
                'channel_of_origin' => $channels['origin'],
                'adults' => (int) ($row['adults'] ?? 0),
                'children' => (int) ($row['children'] ?? 0),
                'rates_version_id' => app(CurrentConfig::class)->version(ConfigKind::Rates)->id,
                'price_lines' => $quote->toArray()['lines'],
                'total' => $quote->total,
                'deposit_pct' => $quote->depositPct,
                'balance_days' => $type === BookingType::Charter
                    ? $terms->charterBalanceDays
                    : $terms->cabinBalanceDays,
                'online_deposit' => false,
                'sold_on' => SoldOn::today(),
            ],
        );

        if ($booking->wasRecentlyCreated) {
            History::record($booking, 'booking.created', after: [
                'reference' => $booking->reference,
                'status' => $booking->status->value,
                'total' => $booking->total,
                'what' => 'Reservation created in RMS — '.$booking->cabinLabel().' · '.$booking->partyLabel().' · seeded',
            ]);
        }

        $billing = [];
        if (isset($row['billing']) && is_string($row['billing']) && $row['billing'] !== '') {
            $billing['billing_address'] = $row['billing'];
        }
        if (isset($row['phone']) && is_string($row['phone']) && $row['phone'] !== '') {
            $billing['billing_phone'] = $row['phone'];
        }
        if ($billing !== [] && ($booking->billing_address === null || $booking->billing_phone === null)) {
            $booking->fill($billing);
            $booking->save();
        }

        if (! $status->holdsInventory()) {
            return;
        }

        if ($booking->claims()->whereNull('released_at')->exists()) {
            return;
        }

        $cabins = $type === BookingType::Charter
            ? $departure->property->cabins->sortBy('sort')->values()
            : collect([$cabin]);

        app(ClaimService::class)->claim($departure->stayDates(), $cabins, $booking, ClaimKind::Booking);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function price(Departure $departure, BookingType $type, array $row): Quote
    {
        $cabin = $this->cabin($departure, $row);
        $input = new QuoteInput(
            year: (int) $departure->date->format('Y'),
            type: $type->quoteType(),
            category: $cabin?->roomType?->code,
            adults: (int) ($row['adults'] ?? 0),
            children: (int) ($row['children'] ?? 0),
            festive: $departure->festive,
        );

        $priced = app(CabinPricer::class)->quote(app(CurrentConfig::class)->rates(), $input);

        if ($priced instanceof NoRate) {
            throw new RuntimeException($priced->reason.' for '.(string) $row['id']);
        }

        return $priced;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function cabin(Departure $departure, array $row): ?Room
    {
        if (($row['type'] ?? '') === 'CHARTER' || ($row['cab'] ?? '') === 'ALL') {
            return null;
        }

        $cabin = $departure->property->cabins->first(
            fn (Room $item): bool => $item->code === (string) $row['cab'],
        );

        if (! $cabin instanceof Room) {
            throw new RuntimeException('Cabin '.$row['cab'].' is missing on '.$departure->reference.'.');
        }

        return $cabin;
    }

    /**
     * @param  array<int, Departure>  $departures
     */
    private function departureFor(int $di, array $departures): Departure
    {
        $departure = $departures[$di] ?? null;

        if (! $departure instanceof Departure) {
            throw new RuntimeException('No ANAMARA departure for date index '.$di.'.');
        }

        return $departure;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, Departure>
     */
    private function anamaraByDateIndex(array $rows): array
    {
        $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
        $mapped = [];

        foreach ($rows as $row) {
            if (($row['property'] ?? '') !== 'ANAMARA') {
                continue;
            }

            $departure = Departure::query()
                ->where('property_id', $property->id)
                ->whereDate('date', (string) $row['date'])
                ->with('property.cabins')
                ->first();

            if (! $departure instanceof Departure) {
                throw new RuntimeException('ANAMARA departure '.$row['date'].' is not seeded.');
            }

            $mapped[(int) $row['di']] = $departure;
        }

        return $mapped;
    }

    private function status(string $status): BookingStatus
    {
        if ($status === 'OVERDUE') {
            return BookingStatus::Confirmed;
        }

        return BookingStatus::from($status);
    }

    private function owner(string $firstName): User
    {
        $user = User::query()
            ->get()
            ->first(fn (User $candidate): bool => str_starts_with($candidate->name, $firstName));

        if (! $user instanceof User) {
            throw new RuntimeException('No demo user named '.$firstName.'.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function leadEmail(array $row): ?string
    {
        $guests = $row['guests'] ?? [];

        if (! is_array($guests)) {
            return null;
        }

        foreach ($guests as $guest) {
            if (! is_array($guest)) {
                continue;
            }

            $email = $guest['email'] ?? '';

            if (($guest['lead'] ?? false) && is_string($email) && $email !== '') {
                return $email;
            }
        }

        foreach ($guests as $guest) {
            if (! is_array($guest)) {
                continue;
            }

            $email = $guest['email'] ?? '';

            if (is_string($email) && $email !== '') {
                return $email;
            }
        }

        return null;
    }

    private function preferredChannel(mixed $value): PreferredChannel
    {
        if (is_string($value) && $value !== '') {
            return PreferredChannel::from($value);
        }

        return PreferredChannel::Email;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function seedPayments(array $rows): void
    {
        $grouped = [];

        foreach ($rows as $row) {
            $reference = (string) ($row['bk'] ?? '');

            if ($reference === '') {
                continue;
            }

            $grouped[$reference][] = $row;
        }

        foreach ($grouped as $reference => $payments) {
            $booking = Booking::query()->where('reference', $reference)->lockForUpdate()->first();

            if (! $booking instanceof Booking) {
                throw new RuntimeException('No seeded booking '.$reference.' for a payment.');
            }

            foreach ($payments as $row) {
                $this->seedPayment($booking, $row);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function seedPayment(Booking $booking, array $row): void
    {
        $kind = $this->paymentKind((string) ($row['kind'] ?? ''));

        if ($booking->payments()->where('kind', $kind)->exists()) {
            return;
        }

        $amount = match ($kind) {
            PaymentKind::Deposit => $booking->depositAmount(),
            PaymentKind::Balance => $booking->total - $booking->depositAmount(),
            default => throw new RuntimeException('Seed payments only support deposit and balance.'),
        };

        $gateway = (string) ($row['gw'] ?? '');

        app(InsertLedgerRow::class)->handle($booking, [
            'kind' => $kind,
            'method' => $this->paymentMethod((string) ($row['method'] ?? '')),
            'amount' => $amount,
            'status' => $this->paymentStatus((string) ($row['status'] ?? '')),
            'paid_at' => is_string($row['date'] ?? null) && $row['date'] !== ''
                ? $row['date']
                : null,
            'gateway_id' => $gateway === '' ? null : $gateway,
        ]);
    }

    private function paymentKind(string $kind): PaymentKind
    {
        return match ($kind) {
            'Deposit' => PaymentKind::Deposit,
            'Balance' => PaymentKind::Balance,
            'Extras' => PaymentKind::Extras,
            'Refund' => PaymentKind::Refund,
            'Other' => PaymentKind::Other,
            default => throw new RuntimeException('Unknown seed payment kind '.$kind),
        };
    }

    private function paymentMethod(string $method): PaymentMethod
    {
        return match ($method) {
            'Card (Stripe)' => PaymentMethod::CardStripe,
            'Stripe payment link' => PaymentMethod::StripeLink,
            'Wire transfer' => PaymentMethod::Wire,
            default => throw new RuntimeException('Unknown seed payment method '.$method),
        };
    }

    private function paymentStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'Settled' => PaymentStatus::Settled,
            'Awaiting wire' => PaymentStatus::AwaitingWire,
            'Refunded' => PaymentStatus::Refunded,
            default => throw new RuntimeException('Unknown seed payment status '.$status),
        };
    }

    /**
     * @return array{departures: list<array<string, mixed>>, groups: list<array<string, mixed>>, bookings: list<array<string, mixed>>, payments: list<array<string, mixed>>}
     */
    private function seedFile(): array
    {
        $path = base_path('docs/requirements/examples/seed-data.json');

        try {
            /** @var array{departures: list<array<string, mixed>>, groups: list<array<string, mixed>>, bookings: list<array<string, mixed>>, payments: list<array<string, mixed>>} $seed */
            $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('seed-data.json is not valid JSON.', 0, $exception);
        }

        return $seed;
    }
}
