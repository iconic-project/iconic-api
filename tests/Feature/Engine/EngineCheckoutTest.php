<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CabinCategory;
use App\Enums\CheckoutPath;
use App\Enums\CheckoutSessionStatus;
use App\Enums\ClaimKind;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentDocument;
use App\Enums\ConsentPurpose;
use App\Enums\ConsentSource;
use App\Enums\DepartureStatus;
use App\Enums\HoldType;
use App\Enums\MainChannel;
use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Enums\PngCategory;
use App\Enums\PreferredChannel;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\CheckoutSession;
use App\Models\Consent;
use App\Models\ContactConsent;
use App\Models\Departure;
use App\Models\Offer;
use App\Models\RoomNightClaim;
use App\Services\Stripe\FakeStripeGateway;
use App\Support\Countries;
use App\Support\IpHash;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Offers\OfferFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    adminUser();
});

function checkoutWestDeparture(string $date = '2027-11-07'): Departure
{
    $departure = ReservationFixtures::anamaraDeparture($date);
    $departure->update([
        'itinerary_id' => OfferFixtures::west()->id,
        'status' => DepartureStatus::OnSale,
        'waitlist_enabled' => true,
    ]);

    return $departure->fresh(['property.cabins', 'itinerary']);
}

/**
 * @param  list<array{cabin_code: string, adults: int, children: int}>  $cabins
 * @return array<string, mixed>
 */
function checkoutHoldPayload(Departure $departure, array $cabins = [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]]): array
{
    return [
        'departure_id' => $departure->id,
        'cabins' => $cabins,
    ];
}

/**
 * @param  list<array{cabin_code: string, adults: int, children: int}>  $cabins
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutSubmitPayload(array $cabins, int $expectedTotal, array $overrides = []): array
{
    $guests = [];

    foreach ($cabins as $row) {
        $count = (int) $row['adults'] + (int) $row['children'];

        for ($i = 0; $i < $count; $i++) {
            $guests[] = [
                'cabin_code' => $row['cabin_code'],
                'nationality' => 'US',
                'ecuador_resident' => false,
            ];
        }
    }

    return array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada-'.uniqid().'@iconic.test',
        'phone' => '+15551212',
        'preferred_channel' => PreferredChannel::Email->value,
        'travel_advisor' => false,
        'notes' => 'Window if possible',
        'png_collected' => false,
        'tct_collected' => false,
        'path' => CheckoutPath::PayLater->value,
        'expected_total' => $expectedTotal,
        'declarations' => [
            ConsentDocument::Privacy->value,
            ConsentDocument::Insurance->value,
        ],
        'guests' => $guests,
    ], $overrides);
}

/**
 * @param  list<array{cabin_code: string, adults: int, children: int}>  $cabins
 * @return array{token: string, expires_at: string, quote: array<string, mixed>, cabins: list<array{cabin_code: string, adults: int, children: int}>}
 */
function createCheckoutHold(Departure $departure, array $cabins = [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]]): array
{
    $response = test()->postJson('/api/engine/checkout', checkoutHoldPayload($departure, $cabins))
        ->assertCreated()
        ->json();

    return [
        'token' => $response['token'],
        'expires_at' => $response['expires_at'],
        'quote' => $response['quote'],
        'cabins' => $cabins,
    ];
}

test('creating a checkout holds the cabins', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);

    expect($created['token'])->toHaveLength(64);
    expect($created['quote']['total'])->toBeInt();

    $session = CheckoutSession::findByToken($created['token']);
    expect($session)->toBeInstanceOf(CheckoutSession::class);
    expect($session?->status)->toBe(CheckoutSessionStatus::Holding);
    expect($session?->ip_hash)->toBe(IpHash::of('127.0.0.1'));

    $claims = RoomNightClaim::query()
        ->where('holder_type', 'checkout_session')
        ->where('holder_id', $session?->id)
        ->whereNull('released_at')
        ->get();

    expect($claims->pluck('room_id')->unique())->toHaveCount(1);
    expect($claims->first()?->kind)->toBe(ClaimKind::Hold);
    expect($claims->first()?->hold_type)->toBe(HoldType::Web);
});

test('a second checkout on the same cabin is 409 and holds nothing', function (): void {
    $departure = checkoutWestDeparture();
    createCheckoutHold($departure);

    $this->postJson('/api/engine/checkout', checkoutHoldPayload($departure))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Cabin unavailable.');

    expect(CheckoutSession::query()->count())->toBe(1);
    expect(RoomNightClaim::query()->whereNull('released_at')->pluck('room_id')->unique())->toHaveCount(1);
});

test('a hold can be extended once and the second extend is 409', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
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

    $this->postJson('/api/engine/checkout/'.$created['token'].'/extend')
        ->assertStatus(409);
});

test('deleting a checkout releases the hold and is idempotent', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);

    $this->deleteJson('/api/engine/checkout/'.$created['token'])->assertNoContent();

    $session = CheckoutSession::findByToken($created['token']);
    expect($session?->status)->toBe(CheckoutSessionStatus::Released);
    expect(RoomNightClaim::query()->where('holder_id', $session?->id)->whereNull('released_at')->count())->toBe(0);

    $this->deleteJson('/api/engine/checkout/'.$created['token'])->assertNoContent();
    $this->deleteJson('/api/engine/checkout/'.bin2hex(random_bytes(32)))->assertNotFound();
});

test('the expiry job marks a web checkout session expired', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $session = CheckoutSession::findByToken($created['token']);

    RoomNightClaim::query()
        ->where('holder_type', 'checkout_session')
        ->where('holder_id', $session?->id)
        ->update(['expires_at' => now()->subMinute()]);

    $this->artisan('inventory:release-expired-holds')->assertSuccessful();

    expect($session?->fresh()?->status)->toBe(CheckoutSessionStatus::Expired);
    expect(RoomNightClaim::query()->where('holder_id', $session?->id)->whereNull('released_at')->count())->toBe(0);
});

test('a third holding session for the same ip_hash releases the oldest', function (): void {
    $departure = checkoutWestDeparture();
    $first = createCheckoutHold($departure, [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]]);
    $second = createCheckoutHold($departure, [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]]);
    $third = createCheckoutHold($departure, [['cabin_code' => 'S3', 'adults' => 2, 'children' => 0]]);

    expect(CheckoutSession::findByToken($first['token'])?->status)->toBe(CheckoutSessionStatus::Released);
    expect(CheckoutSession::findByToken($second['token'])?->status)->toBe(CheckoutSessionStatus::Holding);
    expect(CheckoutSession::findByToken($third['token'])?->status)->toBe(CheckoutSessionStatus::Holding);
    expect(RoomNightClaim::query()->whereNull('released_at')->pluck('room_id')->unique())->toHaveCount(2);
});

test('party rules fail on the field named in the spec', function (array $cabins, string $field): void {
    $departure = checkoutWestDeparture();

    $this->postJson('/api/engine/checkout', checkoutHoldPayload($departure, $cabins))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'over cabin cap' => [[['cabin_code' => 'S1', 'adults' => 4, 'children' => 0]], 'cabins.0.adults'],
    'empty cabin' => [[['cabin_code' => 'S1', 'adults' => 0, 'children' => 0]], 'cabins.0.adults'],
    'child without adult' => [[['cabin_code' => 'S1', 'adults' => 0, 'children' => 1]], 'cabins.0.adults'],
    'duplicate cabin' => [[
        ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
        ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
    ], 'cabins.1.cabin_code'],
    'over property cap' => [[
        ['cabin_code' => 'S1', 'adults' => 3, 'children' => 0],
        ['cabin_code' => 'S2', 'adults' => 3, 'children' => 0],
        ['cabin_code' => 'S3', 'adults' => 3, 'children' => 0],
        ['cabin_code' => 'S4', 'adults' => 3, 'children' => 0],
        ['cabin_code' => 'S5', 'adults' => 3, 'children' => 0],
        ['cabin_code' => 'S6', 'adults' => 2, 'children' => 0],
    ], 'cabins'],
]);

test('more than nine cabins is refused', function (): void {
    $departure = checkoutWestDeparture();
    $rows = [];

    foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'OWNER'] as $code) {
        $rows[] = ['cabin_code' => $code, 'adults' => 1, 'children' => 0];
    }

    $rows[] = ['cabin_code' => 'S1', 'adults' => 1, 'children' => 0];

    $this->postJson('/api/engine/checkout', checkoutHoldPayload($departure, $rows))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cabins']);
});

test('a hidden departure is 404', function (): void {
    $departure = checkoutWestDeparture();
    $departure->update(['status' => DepartureStatus::Hidden]);

    $this->postJson('/api/engine/checkout', checkoutHoldPayload($departure))->assertNotFound();
});

test('re-price after an offer change is 409 and writes no bookings', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $total = (int) $created['quote']['total'];

    Offer::factory()->live()->create([
        'type' => OfferType::Percent,
        'value' => 12,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'code' => 'LAST12',
        'price_line' => 'Last cabins −12%',
    ]);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], $total),
    )
        ->assertStatus(409)
        ->assertJsonPath('message', 'The price changed. Review the new quote and submit again.')
        ->assertJsonStructure(['quote' => ['total']]);

    expect(Booking::query()->count())->toBe(0);
    expect(CheckoutSession::findByToken($created['token'])?->status)->toBe(CheckoutSessionStatus::Holding);
});

test('pay later creates requested bookings, a group, guests, consents and converted claims', function (): void {
    $departure = checkoutWestDeparture();
    $cabins = [
        ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
        ['cabin_code' => 'S2', 'adults' => 1, 'children' => 1],
    ];
    $created = createCheckoutHold($departure, $cabins);
    $email = 'party-'.uniqid().'@iconic.test';

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($cabins, (int) $created['quote']['total'], [
            'email' => $email,
            'marketing' => false,
        ]),
    )
        ->assertOk()
        ->assertJsonPath('path', CheckoutPath::PayLater->value)
        ->assertJsonPath('email', $email);

    $bookings = Booking::query()->orderBy('id')->get();
    expect($bookings)->toHaveCount(2);
    expect($bookings->pluck('group_id')->unique())->toHaveCount(1);
    expect($bookings->first()?->group_id)->not->toBeNull();

    foreach ($bookings as $booking) {
        expect($booking->status)->toBe(BookingStatus::Requested);
        expect($booking->request_reference)->toStartWith('ANK-R-');
        expect($booking->reference)->toBeNull();
        expect($booking->main_channel)->toBe(MainChannel::D2C);
        expect($booking->channel_of_origin->value)->toBe('Hotel Booking Engine');
        expect($booking->online_deposit)->toBeFalse();
        expect($booking->bookingRequest)->not->toBeNull();
        expect($booking->bookingRequest?->preferred_channel)->toBe(PreferredChannel::Email);
        expect($booking->guests)->toHaveCount($booking->adults + $booking->children);
        expect($booking->guests->first()?->png_category)->toBe(PngCategory::Pending);
        expect($booking->guests->first()?->nationality)->toBe('US');

        $claim = $booking->claims->first();
        expect($claim?->kind)->toBe(ClaimKind::Hold);
        expect($claim?->hold_type)->toBe(HoldType::Request);
        expect($claim?->released_at)->toBeNull();
        expect($claim?->expires_at?->greaterThan(now()->addMinutes(30)))->toBeTrue();

        $documents = Consent::query()->where('booking_id', $booking->id)->pluck('document');
        expect($documents)->toContain(ConsentDocument::Privacy, ConsentDocument::Insurance);
        expect($documents)->not->toContain(ConsentDocument::Terms, ConsentDocument::Marketing);
        expect(ContactConsent::query()->where('contact_id', $booking->contact_id)->where('purpose', ConsentPurpose::Marketing)->count())->toBe(0);
        expect(Consent::query()->where('booking_id', $booking->id)->value('source'))->toBe(ConsentSource::Engine);
        expect(Consent::query()->where('booking_id', $booking->id)->value('ip'))->toBe('127.0.0.1');
    }

    $lead = $bookings->first()?->guests->firstWhere('is_lead', true);
    expect($lead?->first_name)->toBe('Ada');
    expect($lead?->last_name)->toBe('Lovelace');

    expect(CheckoutSession::findByToken($created['token'])?->status)->toBe(CheckoutSessionStatus::Submitted);
    expect(ChangeHistory::query()->where('event', 'booking.requested')->where('actor_id', null)->count())->toBe(2);
});

test('pay later records marketing only when it is explicitly true', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $created['quote']['total'], [
            'marketing' => true,
        ]),
    )->assertOk();

    $booking = Booking::query()->firstOrFail();
    $documents = Consent::query()->where('booking_id', $booking->id)->where('document', ConsentDocument::Marketing)->get();
    expect($documents)->toHaveCount(1);

    $register = ContactConsent::query()
        ->where('contact_id', $booking->contact_id)
        ->where('purpose', ConsentPurpose::Marketing)
        ->get();
    expect($register)->toHaveCount(1);
    expect($register->first()?->granted)->toBeTrue();
    expect($register->first()?->capture_point)->toBe(ConsentCapturePoint::EngineForm);
    expect($register->first()?->source_consent_id)->toBe($documents->first()?->id);
});

test('pay deposit requires the remaining declarations', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $created['quote']['total'], [
            'path' => CheckoutPath::PayDeposit->value,
        ]),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['declarations']);
});

test('an unknown or expired token is 404 on extend and submit', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $session = CheckoutSession::findByToken($created['token']);
    $session?->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/engine/checkout/'.$created['token'].'/extend')->assertNotFound();
    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $created['quote']['total']),
    )->assertNotFound();
    $this->postJson('/api/engine/checkout/missing/extend')->assertNotFound();
});

test('checkout status is read from the database and never calls stripe', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $email = 'status-'.uniqid().'@iconic.test';

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $created['quote']['total'], [
            'email' => $email,
        ]),
    )->assertOk();

    $gateway = app(FakeStripeGateway::class);
    $before = $gateway->retrieveCheckoutCalls;

    $json = $this->getJson('/api/engine/checkout/'.$created['token'].'/status')
        ->assertOk()
        ->json();

    expect($gateway->retrieveCheckoutCalls)->toBe($before);
    expect($json['status'])->toBe(CheckoutSessionStatus::Submitted->value);
    expect($json['path'])->toBe(CheckoutPath::PayLater->value);
    expect($json['email'])->toBe($email);
    expect($json['bookings'])->toHaveCount(1);
    expect($json['bookings'][0]['status'])->toBe(BookingStatus::Requested->value);
    expect($json['bookings'][0]['reference'])->toStartWith('ANK-R-');
    expect($json['stripe_checkout_session_id'])->toBeNull();

    Booking::query()->update(['status' => BookingStatus::Confirmed, 'reference' => 'ANK-2027-0001']);

    $confirmed = $this->getJson('/api/engine/checkout/'.$created['token'].'/status')
        ->assertOk()
        ->json();

    expect($gateway->retrieveCheckoutCalls)->toBe($before);
    expect($confirmed['bookings'][0]['status'])->toBe(BookingStatus::Confirmed->value);
    expect($confirmed['bookings'][0]['reference'])->toBe('ANK-2027-0001');

    $session = CheckoutSession::findByToken($created['token']);
    $session?->update(['stripe_expires_at' => now()->subMinute()]);

    $expired = $this->getJson('/api/engine/checkout/'.$created['token'].'/status')
        ->assertOk()
        ->json();

    expect($gateway->retrieveCheckoutCalls)->toBe($before);
    expect($expired['stripe_expires_at'])->not->toBeNull();
    expect($expired['bookings'][0]['status'])->toBe(BookingStatus::Confirmed->value);

    $this->getJson('/api/engine/checkout/missing/status')->assertNotFound();
});

test('submit accepts guest cabin labels from the engine deck', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure, [['cabin_code' => 'Suite 01', 'adults' => 2, 'children' => 0]]);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload(
            [['cabin_code' => 'Suite 01', 'adults' => 2, 'children' => 0]],
            (int) $created['quote']['total'],
        ),
    )
        ->assertOk()
        ->assertJsonPath('path', CheckoutPath::PayLater->value);

    $booking = Booking::query()->firstOrFail();
    expect($booking->cabin?->code)->toBe('S1');
});

test('the public country list comes from the api', function (): void {
    $rows = $this->getJson('/api/engine/countries')->assertOk()->json();

    expect($rows)->toBeArray();
    expect(count($rows))->toBe(count(Countries::all()));
    expect($rows[0] ?? null)->toHaveKeys(['code', 'name']);
    expect(array_column($rows, 'code'))->toContain('US', 'EC');
});
