<?php

declare(strict_types=1);

use App\Actions\Complete\IssueCompleteAccessToken;
use App\Actions\Consents\RecordConsent;
use App\Enums\BookingAccessTokenPurpose;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentDocument;
use App\Enums\ConsentPurpose;
use App\Enums\ConsentSource;
use App\Enums\PaymentKind;
use App\Enums\PaymentLinkStatus;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\ChangeHistory;
use App\Models\Consent;
use App\Models\ContactConsent;
use App\Models\Group;
use App\Models\Guest;
use App\Models\PaymentLink;
use App\Support\BusinessTime;
use App\Support\Complete\CompleteAccess;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    adminUser();
});

function completeBooking(array $overrides = []): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-11-05');

    return Booking::factory()->create(array_merge([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::PendingPayment,
        'total' => 26600,
        'deposit_pct' => 10,
        'owner_id' => adminUser()->id,
    ], $overrides));
}

function completeTokenFor(Booking $booking): string
{
    $url = app(IssueCompleteAccessToken::class)->handle($booking);
    $path = parse_url($url, PHP_URL_PATH);
    $token = is_string($path) ? basename($path) : '';

    expect($token)->not->toBe('');

    return $token;
}

test('the token is hashed at rest and GET returns the page without a passport number', function (): void {
    $booking = completeBooking();
    $guest = Guest::factory()->create([
        'booking_id' => $booking->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'passport_no' => 'C4F7K8Q1R',
    ]);
    $token = completeTokenFor($booking);

    $row = BookingAccessToken::query()->where('booking_id', $booking->id)->firstOrFail();
    expect($row->token_hash)->toBe(BookingAccessToken::hashToken($token));
    expect($row->token_hash)->not->toBe($token);
    expect($row->purpose)->toBe(BookingAccessTokenPurpose::Complete);
    expect($row->expires_at->utc()->format('Y-m-d H:i:s'))
        ->toBe(BusinessTime::dayEndUtc($booking->check_in->toDateString())->format('Y-m-d H:i:s'));

    $response = $this->getJson('/api/engine/complete/'.$token)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertJsonPath('bookings.0.reference', $booking->displayReference())
        ->assertJsonPath('bookings.0.property', 'ANAMARA')
        ->assertJsonPath('amount_due', 2660)
        ->assertJsonPath('amount_due_kind', PaymentKind::Deposit->value)
        ->assertJsonPath('can_pay', false)
        ->assertJsonPath('pay_url', null)
        ->assertJsonPath('bookings.0.guests.0.passport_on_file', true)
        ->assertJsonPath('bookings.0.guests.0.id', $guest->id);

    $json = $response->json();
    expect(json_encode($json))->not->toContain('C4F7K8Q1R');
    expect(json_encode($json))->not->toContain('passport_no');
    expect($json['countries'] ?? [])->not->toBeEmpty();
    expect($json['declarations'])->toHaveCount(5);
});

test('unknown expired revoked cancelled released and deleted tokens 404 identically', function (): void {
    $booking = completeBooking();
    $token = completeTokenFor($booking);
    $this->getJson('/api/engine/complete/unknown-token')
        ->assertNotFound()
        ->assertJsonPath('message', CompleteAccess::NOT_FOUND);

    $expired = bin2hex(random_bytes(32));
    BookingAccessToken::factory()->create([
        'booking_id' => $booking->id,
        'token_hash' => BookingAccessToken::hashToken($expired),
        'expires_at' => now()->subMinute(),
        'page_url' => 'http://localhost:3000/complete/'.$expired,
    ]);
    $this->getJson('/api/engine/complete/'.$expired)
        ->assertNotFound()
        ->assertJsonPath('message', CompleteAccess::NOT_FOUND);

    BookingAccessToken::query()->where('booking_id', $booking->id)->update(['revoked_at' => now()]);
    $this->getJson('/api/engine/complete/'.$token)
        ->assertNotFound()
        ->assertJsonPath('message', CompleteAccess::NOT_FOUND);

    $cancelled = completeBooking(['status' => BookingStatus::PendingPayment, 'reference' => 'ANK-2026-8101']);
    $cancelledToken = completeTokenFor($cancelled);
    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$cancelled->id.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Guest withdrew',
        ])
        ->assertOk();
    $this->getJson('/api/engine/complete/'.$cancelledToken)
        ->assertNotFound()
        ->assertJsonPath('message', CompleteAccess::NOT_FOUND);

    $released = completeBooking(['status' => BookingStatus::Requested, 'reference' => 'ANK-R-2026-8102']);
    $releasedToken = completeTokenFor($released);
    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$released->id.'/transition', [
            'to' => 'RELEASED',
            'reason' => 'No reply',
        ])
        ->assertOk();
    $this->getJson('/api/engine/complete/'.$releasedToken)
        ->assertNotFound()
        ->assertJsonPath('message', CompleteAccess::NOT_FOUND);

    $deleted = completeBooking(['reference' => 'ANK-2026-8103']);
    $deletedToken = completeTokenFor($deleted);
    $this->actingAs(adminUser())
        ->deleteJson('/api/rms/bookings/'.$deleted->id, ['reason' => 'Cleanup'])
        ->assertNoContent();
    $this->getJson('/api/engine/complete/'.$deletedToken)
        ->assertNotFound()
        ->assertJsonPath('message', CompleteAccess::NOT_FOUND);
});

test('GET for a group includes every booking and guest', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-11-05');
    $group = Group::factory()->create();
    $first = completeBooking([
        'group_id' => $group->id,
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'reference' => 'ANK-2026-8110',
    ]);
    $second = completeBooking([
        'group_id' => $group->id,
        'room_id' => $departure->property->rooms->firstWhere('code', 'S2')?->id,
        'reference' => 'ANK-2026-8111',
    ]);
    Guest::factory()->create(['booking_id' => $first->id, 'first_name' => 'Ada']);
    Guest::factory()->create(['booking_id' => $second->id, 'first_name' => 'Charles']);
    $token = completeTokenFor($first);

    $this->getJson('/api/engine/complete/'.$token)
        ->assertOk()
        ->assertJsonCount(2, 'bookings')
        ->assertJsonPath('bookings.0.reference', 'ANK-2026-8110')
        ->assertJsonPath('bookings.1.reference', 'ANK-2026-8111')
        ->assertJsonPath('bookings.0.guests.0.first_name', 'Ada')
        ->assertJsonPath('bookings.1.guests.0.first_name', 'Charles');
});

test('billing guest and declarations write through existing actions', function (): void {
    $booking = completeBooking();
    $guest = Guest::factory()->create([
        'booking_id' => $booking->id,
        'first_name' => '',
        'last_name' => '',
        'dob' => '2012-04-01',
    ]);
    $token = completeTokenFor($booking);
    PaymentLink::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => 2660,
        'status' => PaymentLinkStatus::Open,
        'url' => 'https://buy.stripe.com/test/plink_complete',
    ]);

    $this->putJson('/api/engine/complete/'.$token.'/billing', [
        'billing_name' => 'Ada Lovelace',
        'billing_email' => 'ada@example.com',
        'billing_address' => '1 Engine Lane',
        'billing_phone' => '+1 555 0100',
    ])
        ->assertOk()
        ->assertJsonPath('billing.billing_name', 'Ada Lovelace')
        ->assertJsonPath('can_pay', false);

    $this->putJson('/api/engine/complete/'.$token.'/guests/'.$guest->id, [
        'first_name' => 'Leon',
        'last_name' => 'Brandt',
        'dob' => '2012-04-01',
        'nationality' => 'de',
        'passport_no' => 'C4F7K8Q1R',
        'passport_expiry' => '2032-04-01',
        'email' => 'leon@example.com',
        'insurance_declared' => true,
        'guardian_name' => 'Julia Brandt',
        'guardian_relationship' => 'Mother',
        'guardian_consented' => true,
    ])
        ->assertOk()
        ->assertJsonPath('bookings.0.guests.0.passport_on_file', true)
        ->assertJsonPath('bookings.0.guests.0.nationality', 'DE')
        ->assertJsonPath('bookings.0.guests.0.guardian.name', 'Julia Brandt')
        ->assertJsonMissingPath('bookings.0.guests.0.passport_no');

    $stored = $guest->fresh();
    expect($stored?->passport_no)->toBe('C4F7K8Q1R');
    expect($stored?->guardian_recorded_by)->toBeNull();

    $history = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'guest.updated')
        ->latest('id')
        ->first();
    expect($history?->actor_label)->toBe(CompleteAccess::ACTOR_LABEL);
    expect($history?->actor_id)->toBeNull();
    expect($history?->after['what'] ?? '')->toContain('passport number');
    expect(json_encode($history?->after))->not->toContain('C4F7K8Q1R');

    $this->putJson('/api/engine/complete/'.$token.'/guests/'.$guest->id, [
        'passport_no' => '',
    ])->assertOk();
    expect($guest->fresh()?->passport_no)->toBe('C4F7K8Q1R');

    $this->putJson('/api/engine/complete/'.$token.'/guests/'.$guest->id, [
        'dob' => BusinessTime::now()->addDay()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors('dob');

    $this->postJson('/api/engine/complete/'.$token.'/declarations', [
        'documents' => [
            ConsentDocument::Terms->value,
            ConsentDocument::Cancellation->value,
            ConsentDocument::Privacy->value,
            ConsentDocument::Insurance->value,
        ],
    ])
        ->assertOk()
        ->assertJsonPath('can_pay', true)
        ->assertJsonPath('pay_url', 'https://buy.stripe.com/test/plink_complete');

    expect(Consent::query()->where('booking_id', $booking->id)->count())->toBe(4);
    expect(Consent::query()->where('booking_id', $booking->id)->where('source', ConsentSource::PaymentLink)->count())->toBe(4);
    expect(Consent::query()->where('booking_id', $booking->id)->whereNotNull('ip')->count())->toBe(4);
});

test('can_pay stays false until all four current declarations and an open link exist', function (): void {
    $booking = completeBooking();
    $token = completeTokenFor($booking);

    $this->postJson('/api/engine/complete/'.$token.'/declarations', [
        'documents' => [ConsentDocument::Privacy->value, ConsentDocument::Insurance->value],
    ])
        ->assertOk()
        ->assertJsonPath('can_pay', false)
        ->assertJsonPath('pay_url', null);

    PaymentLink::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => 2660,
        'status' => PaymentLinkStatus::Open,
        'url' => 'https://buy.stripe.com/test/plink_wait',
    ]);

    $this->getJson('/api/engine/complete/'.$token)
        ->assertOk()
        ->assertJsonPath('can_pay', false)
        ->assertJsonPath('pay_url', 'https://buy.stripe.com/test/plink_wait');

    $this->postJson('/api/engine/complete/'.$token.'/declarations', [
        'documents' => [ConsentDocument::Terms->value, ConsentDocument::Cancellation->value],
    ])
        ->assertOk()
        ->assertJsonPath('can_pay', true);
});

test('engine consents from pay-later count toward can_pay', function (): void {
    $booking = completeBooking();
    $token = completeTokenFor($booking);
    PaymentLink::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => 2660,
        'status' => PaymentLinkStatus::Open,
        'url' => 'https://buy.stripe.com/test/plink_later',
    ]);

    foreach ([ConsentDocument::Privacy, ConsentDocument::Insurance] as $document) {
        app(RecordConsent::class)->handle(
            $booking,
            $document,
            ConsentSource::Engine,
            ip: '10.0.0.1',
        );
    }

    $this->getJson('/api/engine/complete/'.$token)
        ->assertOk()
        ->assertJsonPath('can_pay', false);

    $this->postJson('/api/engine/complete/'.$token.'/declarations', [
        'documents' => [ConsentDocument::Terms->value, ConsentDocument::Cancellation->value],
    ])
        ->assertOk()
        ->assertJsonPath('can_pay', true);
});

test('a guest on another booking is a 404 unless it shares the group', function (): void {
    $booking = completeBooking(['reference' => 'ANK-2026-8120']);
    $other = completeBooking(['reference' => 'ANK-2026-8121']);
    $guest = Guest::factory()->create(['booking_id' => $other->id]);
    $token = completeTokenFor($booking);

    $this->putJson('/api/engine/complete/'.$token.'/guests/'.$guest->id, [
        'first_name' => 'Nope',
    ])
        ->assertNotFound()
        ->assertJsonPath('message', CompleteAccess::NOT_FOUND);
});

test('complete endpoints are rate limited per token', function (): void {
    $booking = completeBooking();
    $token = completeTokenFor($booking);

    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/api/engine/complete/'.$token)->assertOk();
    }

    $this->getJson('/api/engine/complete/'.$token)->assertStatus(429);
});

test('a complete-page marketing withdrawal is granted false in the log and the register', function (): void {
    $booking = completeBooking();
    $token = completeTokenFor($booking);

    $this->postJson('/api/engine/complete/'.$token.'/declarations', [
        'documents' => [ConsentDocument::Terms->value],
        'withdrawn' => [ConsentDocument::Marketing->value],
    ])->assertOk();

    $log = Consent::query()
        ->where('booking_id', $booking->id)
        ->where('document', ConsentDocument::Marketing)
        ->first();

    expect($log)->not->toBeNull();
    expect($log?->withdrawn)->toBeTrue();
    expect($log?->source)->toBe(ConsentSource::PaymentLink);

    $register = ContactConsent::query()
        ->where('contact_id', $booking->contact_id)
        ->where('purpose', ConsentPurpose::Marketing)
        ->get();

    expect($register)->toHaveCount(1);
    expect($register->first()?->granted)->toBeFalse();
    expect($register->first()?->capture_point)->toBe(ConsentCapturePoint::EngineForm);
    expect($register->first()?->source_consent_id)->toBe($log?->id);
});
