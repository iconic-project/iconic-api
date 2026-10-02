<?php

declare(strict_types=1);

use App\Enums\CheckoutPath;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Contact;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    adminUser();
});

/**
 * @return array<string, mixed>
 */
function engineAttribution(string $campaign, string $landing = '/itineraries/western-realm'): array
{
    return [
        'source' => 'google',
        'medium' => 'cpc',
        'campaign' => $campaign,
        'content' => 'ad',
        'term' => 'galapagos',
        'landing_path' => $landing,
        'captured_at' => now()->toIso8601String(),
    ];
}

test('engine checkout stores UTM on the booking at creation and never again', function (): void {
    $departure = checkoutWestDeparture();
    $hold = createCheckoutHold($departure);
    $first = engineAttribution('western-realm');
    $payload = checkoutSubmitPayload($hold['cabins'], (int) $hold['quote']['total'], [
        'session_id' => engineSessionId(),
        'attribution' => [
            'first_touch' => $first,
            'last_touch' => $first,
        ],
    ]);

    $this->postJson('/api/engine/checkout/'.$hold['token'].'/submit', $payload)->assertOk();

    $booking = Booking::query()->where('request_reference', 'like', 'ANK-R-%')->latest('id')->firstOrFail();
    $contact = Contact::query()->where('email', $payload['email'])->firstOrFail();

    expect($booking->utm_first['campaign'] ?? null)->toBe('western-realm');
    expect($booking->utm_last['campaign'] ?? null)->toBe('western-realm');
    expect($booking->agency_id)->toBeNull();
    expect($booking->commission_pct)->toBeNull();
    expect($contact->first_touch['campaign'] ?? null)->toBe('western-realm');
    expect($contact->last_touch['campaign'] ?? null)->toBe('western-realm');

    expect(fn () => DB::table('bookings')->where('id', $booking->id)->update([
        'utm_first' => json_encode(['source' => 'meta']),
    ]))->toThrow(QueryException::class);
});

test('contact first touch is set once and last touch updates on a later submission', function (): void {
    $email = 'repeat-'.uniqid().'@iconic.test';
    $departure = checkoutWestDeparture();

    $firstHold = createCheckoutHold($departure);
    $firstTouch = engineAttribution('first-campaign');
    $this->postJson('/api/engine/checkout/'.$firstHold['token'].'/submit', checkoutSubmitPayload(
        $firstHold['cabins'],
        (int) $firstHold['quote']['total'],
        [
            'email' => $email,
            'session_id' => engineSessionId(),
            'attribution' => ['first_touch' => $firstTouch, 'last_touch' => $firstTouch],
        ],
    ))->assertOk();

    $later = checkoutWestDeparture('2027-11-14');
    $secondHold = createCheckoutHold($later, [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]]);
    $lastTouch = engineAttribution('second-campaign');
    $this->postJson('/api/engine/checkout/'.$secondHold['token'].'/submit', checkoutSubmitPayload(
        $secondHold['cabins'],
        (int) $secondHold['quote']['total'],
        [
            'email' => $email,
            'path' => CheckoutPath::PayLater->value,
            'session_id' => engineSessionId(),
            'attribution' => ['first_touch' => $lastTouch, 'last_touch' => $lastTouch],
        ],
    ))->assertOk();

    $contact = Contact::query()->where('email', $email)->firstOrFail();
    expect($contact->first_touch['campaign'] ?? null)->toBe('first-campaign');
    expect($contact->last_touch['campaign'] ?? null)->toBe('second-campaign');
});

test('a staff agency booking keeps commission and has no marketing touch', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture('2027-12-12');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'owner_id' => adminUser()->id,
    ]);

    expect($booking->utm_first)->toBeNull();
    expect($booking->utm_last)->toBeNull();
    expect($booking->agency_id)->toBe($agency->id);
    expect($booking->commission_pct)->toBe(10);

    expect(fn () => DB::table('bookings')->where('id', $booking->id)->update([
        'utm_last' => json_encode(['source' => 'google']),
    ]))->toThrow(QueryException::class);
});

test('a complete-page landing path is redacted on the booking', function (): void {
    $token = 'abc123secretTokenValue';
    $departure = checkoutWestDeparture();
    $hold = createCheckoutHold($departure);
    $touch = engineAttribution('charter', '/complete/'.$token);

    $this->postJson('/api/engine/checkout/'.$hold['token'].'/submit', checkoutSubmitPayload(
        $hold['cabins'],
        (int) $hold['quote']['total'],
        [
            'attribution' => ['first_touch' => $touch, 'last_touch' => $touch],
        ],
    ))->assertOk();

    $booking = Booking::query()->latest('id')->firstOrFail();
    expect($booking->utm_first['landing_path'] ?? null)->toBe('/complete/[token]');
    expect(json_encode($booking->utm_first))->not->toContain($token);
});
