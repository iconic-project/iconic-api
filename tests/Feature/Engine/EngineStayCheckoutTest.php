<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CheckoutPath;
use App\Enums\CheckoutSessionStatus;
use App\Enums\ClaimKind;
use App\Enums\ConsentDocument;
use App\Enums\HoldType;
use App\Enums\PreferredChannel;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\CheckoutSession;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Services\Engine\EngineFeedVersion;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\StayQuoter;
use App\Services\Stripe\FakeStripeGateway;
use App\Support\Engine\QuoteToken;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-10-05 12:00:00');
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    adminUser();
});

/**
 * @param  list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string}>  $rooms
 * @return array<string, mixed>
 */
function stayQuote(array $rooms, string $checkIn = '2026-12-21', string $checkOut = '2026-12-24'): array
{
    $response = test()->postJson('/api/engine/quote', [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'rooms' => $rooms,
    ])->assertOk()->json();

    expect($response)->toBeArray();

    return $response;
}

/**
 * @param  list<string>  $declarations
 * @return array<string, mixed>
 */
function stayGuest(array $declarations): array
{
    return [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada-'.uniqid().'@iconic.test',
        'phone' => '+15551212',
        'preferred_channel' => PreferredChannel::Email->value,
        'declarations' => $declarations,
    ];
}

/**
 * @return list<string>
 */
function stayPayLaterDeclarations(): array
{
    return [
        ConsentDocument::Privacy->value,
        ConsentDocument::Insurance->value,
    ];
}

/**
 * @return list<string>
 */
function stayDepositDeclarations(): array
{
    return [
        ConsentDocument::Terms->value,
        ConsentDocument::Cancellation->value,
        ConsentDocument::Privacy->value,
        ConsentDocument::Insurance->value,
    ];
}

/**
 * @param  list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string}>  $rooms
 */
function stayOnlineTotal(array $rooms, string $checkIn = '2026-12-21', string $checkOut = '2026-12-24'): int
{
    $specs = [];

    foreach ($rooms as $room) {
        $specs[] = $room + ['online_deposit' => true];
    }

    return (int) app(StayQuoter::class)->quoteRooms(StayDates::of($checkIn, $checkOut), $specs)->total;
}

/**
 * @param  array<string, mixed>  $event
 */
function postStayStripeEvent(array $event): void
{
    $signed = FakeStripeGateway::signedEvent($event);

    test()->call(
        'POST',
        '/api/stripe/webhook',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signed['signature'],
        ],
        $signed['payload'],
    )->assertOk();
}

/**
 * @return list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string}>
 */
function twoStandardRooms(): array
{
    $room = ['room_type' => 'STD', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'BAR'];

    return [$room, $room];
}

test('a stay checkout holds every room on the quote', function (): void {
    $quote = stayQuote(twoStandardRooms());

    $created = $this->postJson('/api/engine/checkout', [
        'quote_token' => $quote['quote_token'],
        ...stayGuest(stayPayLaterDeclarations()),
    ])->assertCreated()->json();

    expect($created['token'])->toHaveLength(64);
    expect($created['quote']['check_in'])->toBe('2026-12-21');
    expect($created['quote']['check_out'])->toBe('2026-12-24');
    expect($created['quote']['total'])->toBe($quote['total']);

    $session = CheckoutSession::findByToken($created['token']);
    expect($session?->status)->toBe(CheckoutSessionStatus::Holding);
    expect($session?->rooms)->toHaveCount(2);

    $claims = RoomNightClaim::query()
        ->where('holder_type', 'checkout_session')
        ->where('holder_id', $session?->id)
        ->whereNull('released_at')
        ->get();

    expect($claims->pluck('room_id')->unique())->toHaveCount(2);
    expect($claims)->toHaveCount(6);
    expect($claims->first()?->kind)->toBe(ClaimKind::Hold);
    expect($claims->first()?->hold_type)->toBe(HoldType::Web);

    $this->getJson('/api/engine/checkout/'.$created['token'].'/status')
        ->assertOk()
        ->assertJsonPath('check_in', '2026-12-21')
        ->assertJsonPath('check_out', '2026-12-24')
        ->assertJsonPath('rooms.0.room_type', 'STD')
        ->assertJsonPath('rooms.1.room_type', 'STD')
        ->assertJsonMissingPath('rooms.0.room_id');
});

test('a stay hold extends once', function (): void {
    $quote = stayQuote([['room_type' => 'STD', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'BAR']]);
    $created = $this->postJson('/api/engine/checkout', [
        'quote_token' => $quote['quote_token'],
        ...stayGuest(stayPayLaterDeclarations()),
    ])->assertCreated()->json();

    $session = CheckoutSession::findByToken($created['token']);
    $original = $session?->expires_at;

    $this->postJson('/api/engine/checkout/'.$created['token'].'/extend')
        ->assertOk()
        ->assertJsonPath('extended', true);

    $session?->refresh();
    expect($session?->extended)->toBeTrue();
    expect($session?->expires_at?->greaterThan($original))->toBeTrue();

    $claimExpiry = RoomNightClaim::query()
        ->where('holder_type', 'checkout_session')
        ->where('holder_id', $session?->id)
        ->value('expires_at');

    expect((string) $claimExpiry)->toBe((string) $session?->expires_at);

    $this->postJson('/api/engine/checkout/'.$created['token'].'/extend')->assertStatus(409);
});

test('expiry releases the nights and publishes an availability change', function (): void {
    $quote = stayQuote([['room_type' => 'STD', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'BAR']]);
    $created = $this->postJson('/api/engine/checkout', [
        'quote_token' => $quote['quote_token'],
        ...stayGuest(stayPayLaterDeclarations()),
    ])->assertCreated()->json();

    $session = CheckoutSession::findByToken($created['token']);
    $version = EngineFeedVersion::current();

    RoomNightClaim::query()
        ->where('holder_type', 'checkout_session')
        ->where('holder_id', $session?->id)
        ->update(['expires_at' => now()->subMinute()]);

    $this->artisan('inventory:release-expired-holds')->assertSuccessful();

    expect($session?->fresh()?->status)->toBe(CheckoutSessionStatus::Expired);
    expect(RoomNightClaim::query()->where('holder_id', $session?->id)->whereNull('released_at')->count())->toBe(0);
    expect(EngineFeedVersion::current())->toBeGreaterThan($version);
});

test('two checkouts on the last room leave one hold', function (): void {
    $type = RoomType::query()->where('code', 'STD')->firstOrFail();
    $free = Room::query()->where('room_type_id', $type->id)->where('status', RoomStatus::Active)->count();
    $stay = StayDates::of('2026-12-21', '2026-12-24');
    $holder = CheckoutSession::query()->create([
        'token_hash' => CheckoutSession::hashToken(bin2hex(random_bytes(32))),
        'departure_id' => null,
        'cabins' => [],
        'check_in' => '2026-12-21',
        'check_out' => '2026-12-24',
        'status' => CheckoutSessionStatus::Submitted,
        'expires_at' => now()->addHour(),
        'extended' => false,
        'ip_hash' => hash('sha256', 'filled'),
    ]);

    DB::transaction(function () use ($stay, $type, $free, $holder): void {
        app(ClaimService::class)->claimType($stay, $type, $free - 1, $holder, ClaimKind::Hold, HoldType::Web, now()->addHour());
    });

    $party = [['room_type' => 'STD', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'BAR']];
    $first = stayQuote($party);
    $second = stayQuote($party);

    $this->postJson('/api/engine/checkout', [
        'quote_token' => $first['quote_token'],
        ...stayGuest(stayPayLaterDeclarations()),
    ])->assertCreated();

    $this->postJson('/api/engine/checkout', [
        'quote_token' => $second['quote_token'],
        ...stayGuest(stayPayLaterDeclarations()),
    ])->assertStatus(409);

    expect(CheckoutSession::query()->where('status', CheckoutSessionStatus::Holding)->count())->toBe(1);
});

test('a drifted quote is refused and holds nothing', function (): void {
    $quote = stayQuote([['room_type' => 'STD', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'BAR']]);
    $payload = QuoteToken::open($quote['quote_token']);
    $payload['total'] = 1;
    $stale = QuoteToken::issue($payload);

    $this->postJson('/api/engine/checkout', [
        'quote_token' => $stale,
        ...stayGuest(stayPayLaterDeclarations()),
    ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The price changed. Review the new quote and submit again.')
        ->assertJsonPath('quote.total', $quote['total']);

    expect(CheckoutSession::query()->count())->toBe(0);
});

test('pay later converts both rooms of a three night stay', function (): void {
    $rooms = twoStandardRooms();
    $quote = stayQuote($rooms);
    $created = $this->postJson('/api/engine/checkout', [
        'quote_token' => $quote['quote_token'],
        ...stayGuest(stayPayLaterDeclarations()),
    ])->assertCreated()->json();

    $this->postJson('/api/engine/checkout/'.$created['token'].'/submit', [
        'path' => CheckoutPath::PayLater->value,
        'expected_total' => $quote['total'],
    ])->assertOk()->assertJsonPath('path', CheckoutPath::PayLater->value);

    $sessionId = CheckoutSession::findByToken($created['token'])?->id;
    $bookings = Booking::query()->where('checkout_session_id', $sessionId)->orderBy('id')->get();
    expect($bookings)->toHaveCount(2);
    expect($bookings->pluck('group_id')->unique())->toHaveCount(1);
    expect($bookings->every(fn (Booking $booking): bool => $booking->nights === 3))->toBeTrue();
    expect($bookings->every(fn (Booking $booking): bool => $booking->status === BookingStatus::Requested))->toBeTrue();
    expect($bookings->every(fn (Booking $booking): bool => $booking->online_deposit === false))->toBeTrue();

    foreach ($bookings as $booking) {
        expect($booking->guests)->toHaveCount($booking->adults + $booking->children);
        expect($booking->guests->first()?->png_category)->toBeNull();
        expect($booking->claims()->whereNull('released_at')->where('kind', ClaimKind::Hold)->where('hold_type', HoldType::Request)->count())->toBe(3);
    }

    expect(CheckoutSession::findByToken($created['token'])?->status)->toBe(CheckoutSessionStatus::Submitted);
});

test('submitting a stale total keeps the hold', function (): void {
    $quote = stayQuote([['room_type' => 'STD', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'BAR']]);
    $created = $this->postJson('/api/engine/checkout', [
        'quote_token' => $quote['quote_token'],
        ...stayGuest(stayPayLaterDeclarations()),
    ])->assertCreated()->json();

    $this->postJson('/api/engine/checkout/'.$created['token'].'/submit', [
        'path' => CheckoutPath::PayLater->value,
        'expected_total' => 1,
    ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The price changed. Review the new quote and submit again.');

    expect(Booking::query()->where('checkout_session_id', CheckoutSession::findByToken($created['token'])?->id)->count())->toBe(0);
    expect(CheckoutSession::findByToken($created['token'])?->status)->toBe(CheckoutSessionStatus::Holding);
});

test('a guest can hold and pay a three night two room stay', function (): void {
    $rooms = twoStandardRooms();
    $quote = stayQuote($rooms);
    $created = $this->postJson('/api/engine/checkout', [
        'quote_token' => $quote['quote_token'],
        ...stayGuest(stayDepositDeclarations()),
    ])->assertCreated()->json();

    $this->postJson('/api/engine/checkout/'.$created['token'].'/submit', [
        'path' => CheckoutPath::PayDeposit->value,
        'expected_total' => stayOnlineTotal($rooms),
    ])
        ->assertOk()
        ->assertJsonPath('path', CheckoutPath::PayDeposit->value)
        ->assertJsonStructure(['references', 'checkout_url']);

    $session = CheckoutSession::findByToken($created['token']);
    $bookings = Booking::query()->where('checkout_session_id', $session?->id)->orderBy('id')->get();
    expect($bookings)->toHaveCount(2);
    expect($bookings->every(fn (Booking $booking): bool => $booking->deposit_pct === 30))->toBeTrue();
    expect($bookings->every(fn (Booking $booking): bool => $booking->depositAmount() < $booking->total))->toBeTrue();

    $metadata = ['checkout_session_id' => (string) $session?->id, 'kind' => 'DEPOSIT'];
    $deposit = 0;

    foreach ($bookings as $booking) {
        $metadata['deposit_'.$booking->id] = (string) $booking->depositAmount();
        $deposit += $booking->depositAmount();
    }

    postStayStripeEvent([
        'id' => 'evt_stay_pay',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => $session?->stripe_checkout_session_id,
                'payment_intent' => 'pi_stay_two_rooms',
                'amount_total' => $deposit * 100,
                'metadata' => $metadata,
            ],
        ],
    ]);

    foreach ($bookings as $booking) {
        $booking->refresh();
        expect($booking->status)->toBe(BookingStatus::Confirmed);
        expect($booking->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->count())->toBe(3);
    }

    $replay = [
        'id' => 'evt_stay_replay',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => $session?->stripe_checkout_session_id,
                'payment_intent' => 'pi_stay_two_rooms',
                'amount_total' => $deposit * 100,
                'metadata' => $metadata,
            ],
        ],
    ];
    postStayStripeEvent($replay);

    expect(Payment::query()->where('gateway_id', 'like', 'pi_stay_two_rooms%')->count())->toBe(2);
});

test('a non refundable plan charges the full stay only when the deposit is 100 percent', function (): void {
    $rooms = [['room_type' => 'STD', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'NR']];
    $quote = stayQuote($rooms);
    $created = $this->postJson('/api/engine/checkout', [
        'quote_token' => $quote['quote_token'],
        ...stayGuest(stayDepositDeclarations()),
    ])->assertCreated()->json();

    $this->postJson('/api/engine/checkout/'.$created['token'].'/submit', [
        'path' => CheckoutPath::PayDeposit->value,
        'expected_total' => stayOnlineTotal($rooms),
    ])->assertOk();

    $booking = Booking::query()->where('checkout_session_id', CheckoutSession::findByToken($created['token'])?->id)->firstOrFail();
    expect($booking->deposit_pct)->toBe(100);
    expect($booking->depositAmount())->toBe($booking->total);
});
