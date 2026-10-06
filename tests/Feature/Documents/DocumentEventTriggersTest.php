<?php

declare(strict_types=1);

use App\Actions\Bookings\TransitionBooking;
use App\Actions\Payments\RecordPayment;
use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Events\BookingStatusChanged;
use App\Listeners\SendOnBookingStatusChanged;
use App\Mail\Documents\DocumentMail;
use App\Models\Booking;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\Payment;
use App\Services\Stripe\FakeStripeGateway;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

afterEach(function (): void {
    Storage::disk('documents')->deleteDirectory('/');
});

function recordDeposit(Booking $booking, int $amount = 2660): Payment
{
    $recorded = app(RecordPayment::class)->handle($booking, [
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::CardStripe,
        'amount' => $amount,
    ], adminUser());

    return $recorded->payment;
}

test('a deposit confirming a booking sends invoice summary and receipt once each', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-6101']);

    recordDeposit($booking);

    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Invoice)->count())->toBe(1);
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Summary)->count())->toBe(1);
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Receipt)->count())->toBe(1);
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Invoice)->value('version'))->toBe(1);
    expect(Delivery::query()->where('booking_id', $booking->id)->where('status', 'SENT')->count())->toBe(3);
    Mail::assertSent(DocumentMail::class, 3);
});

test('a manual CONFIRMED sends the invoice and summary only', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-6102']);

    app(TransitionBooking::class)->handle($booking, [
        'to' => BookingStatus::Confirmed,
        'reason' => 'Manual confirm for documents test',
    ], adminUser());

    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Invoice)->count())->toBe(1);
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Summary)->count())->toBe(1);
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Receipt)->count())->toBe(0);
    Mail::assertSent(DocumentMail::class, 2);
});

test('the CONFIRMED listener retry after issue keeps invoice v1 and one delivery', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-6103']);
    app(TransitionBooking::class)->handle($booking, [
        'to' => BookingStatus::Confirmed,
        'reason' => 'Manual confirm',
    ], adminUser());

    $booking = $booking->fresh() ?? $booking;
    $listener = app(SendOnBookingStatusChanged::class);
    $listener->handle(new BookingStatusChanged($booking, BookingStatus::PendingPayment, BookingStatus::Confirmed));
    $listener->handle(new BookingStatusChanged($booking, BookingStatus::PendingPayment, BookingStatus::Confirmed));

    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Invoice)->count())->toBe(1);
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Invoice)->value('version'))->toBe(1);
    expect(Delivery::query()
        ->where('booking_id', $booking->id)
        ->whereHas('document', fn ($query) => $query->where('kind', DocumentKind::Invoice))
        ->count())->toBe(1);
});

test('the Stripe webhook delivered twice still sends one receipt', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-6104']);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payment-link', [
            'kind' => PaymentKind::Deposit->value,
        ])
        ->assertCreated();

    $event = [
        'id' => 'evt_docs_double',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_docs_001',
                'payment_intent' => 'pi_docs_001',
                'payment_link' => 'plink_test_001',
                'amount_total' => 266000,
                'metadata' => [
                    'booking_id' => (string) $booking->id,
                    'booking_reference' => 'ANK-2026-6104',
                    'kind' => PaymentKind::Deposit->value,
                ],
            ],
        ],
    ];
    $signed = FakeStripeGateway::signedEvent($event);

    $this->call('POST', '/api/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signed['signature'],
    ], $signed['payload'])->assertOk();

    $this->call('POST', '/api/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signed['signature'],
    ], $signed['payload'])->assertOk();

    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Receipt)->count())->toBe(1);
});

test('no automatic documents are issued for requested pending on-hold cancelled or released bookings', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');

    $requested = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::Requested,
        'total' => 26600,
    ]);
    expect(Document::query()->where('booking_id', $requested->id)->count())->toBe(0);

    $pending = pendingCabin([
        'departure' => ReservationFixtures::anamaraDeparture('2028-03-12'),
        'reference' => 'ANK-2026-6105',
        'room_id' => ReservationFixtures::anamaraDeparture('2028-03-12')->property->rooms->firstWhere('code', 'S3')?->id,
    ]);
    expect(Document::query()->where('booking_id', $pending->id)->count())->toBe(0);

    $held = pendingCabin([
        'departure' => ReservationFixtures::anamaraDeparture('2028-03-19'),
        'reference' => 'ANK-2026-6106',
        'room_id' => ReservationFixtures::anamaraDeparture('2028-03-19')->property->rooms->firstWhere('code', 'S4')?->id,
        'status' => BookingStatus::OnHoldAgency,
        'commission_pct' => 15,
    ]);
    recordDeposit($held);
    expect(Document::query()->where('booking_id', $held->id)->where('kind', DocumentKind::Invoice)->count())->toBe(0);
    expect(Document::query()->where('booking_id', $held->id)->where('kind', DocumentKind::Summary)->count())->toBe(0);
    expect(Document::query()->where('booking_id', $held->id)->where('kind', DocumentKind::Receipt)->count())->toBe(1);

    $cancelled = pendingCabin([
        'departure' => ReservationFixtures::anamaraDeparture('2028-03-26'),
        'reference' => 'ANK-2026-6107',
        'room_id' => ReservationFixtures::anamaraDeparture('2028-03-26')->property->rooms->firstWhere('code', 'S5')?->id,
    ]);
    app(TransitionBooking::class)->handle($cancelled, [
        'to' => BookingStatus::Cancelled,
        'reason' => 'Guest withdrew',
    ], adminUser());
    expect(Document::query()->where('booking_id', $cancelled->id)->count())->toBe(0);

    $released = Booking::factory()->create([
        'room_id' => ReservationFixtures::anamaraDeparture('2028-04-02')->property->rooms->firstWhere('code', 'S6')?->id,
        'status' => BookingStatus::Requested,
        'reference' => 'ANK-2026-6108',
        'total' => 26600,
    ]);
    app(TransitionBooking::class)->handle($released, [
        'to' => BookingStatus::Released,
        'reason' => 'Cabin returned',
    ], adminUser());
    expect(Document::query()->where('booking_id', $released->id)->count())->toBe(0);
});
