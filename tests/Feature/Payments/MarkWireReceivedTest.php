<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Payment;
use App\Support\BusinessTime;
use App\Support\Iso;
use App\Support\Payments\WireWindow;
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
});

test('marking a wire received settles it, stores the bank reference and confirms the booking', function (): void {
    $booking = pendingCabin();

    $paymentId = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::Wire->value,
            'amount' => 2660,
        ])
        ->assertCreated()
        ->json('id');

    $this->actingAs(externalFinanceUser())
        ->postJson('/api/rms/payments/'.$paymentId.'/mark-received', [
            'bank_reference' => 'WIRE-991',
        ])
        ->assertOk()
        ->assertJsonPath('status', PaymentStatus::Settled->value)
        ->assertJsonPath('gateway_id', 'WIRE-991');

    $payment = Payment::query()->findOrFail($paymentId);
    expect($payment->status)->toBe(PaymentStatus::Settled);
    expect($payment->gateway_id)->toBe('WIRE-991');
    expect($payment->paid_at->toDateString())->toBe(BusinessTime::now()->toDateString());

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $events = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->orderBy('id')
        ->pluck('event')
        ->all();

    expect($events)->toContain('payment.settled', 'booking.status_changed');
});

test('mark wire received is the first path that mutates a ledger row through the append-only trigger', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::PendingPayment,
    ]);
    $payment = Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::Wire,
        'status' => PaymentStatus::AwaitingWire,
        'amount' => 2660,
        'gateway_id' => null,
        'paid_at' => '2026-01-01',
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/payments/'.$payment->id.'/mark-received', [
            'bank_reference' => 'BANK-TRIGGER-1',
        ])
        ->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Settled);
    expect($payment->gateway_id)->toBe('BANK-TRIGGER-1');
    expect($payment->paid_at->toDateString())->not->toBe('2026-01-01');

    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['amount' => 1]))
        ->toThrow(QueryException::class);
});

test('only an awaiting wire can be marked received', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S3')?->id,
    ]);
    $payment = Payment::factory()->create([
        'booking_id' => $booking->id,
        'status' => PaymentStatus::Settled,
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/payments/'.$payment->id.'/mark-received', [
            'bank_reference' => 'NOPE',
        ])
        ->assertUnprocessable();
});

test('a pending payment booking with no wire has a null window and a wire uses payment created_at', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-0415']);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('wire_window_ends_at', null);

    $created = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::Wire->value,
            'amount' => 2660,
        ])
        ->assertCreated();

    $payment = Payment::query()->findOrFail($created->json('id'));
    $expected = Iso::utc(
        WireWindow::endsAt($payment->created_at),
    );

    $created->assertJsonPath('wire_window_ends_at', $expected);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('wire_window_ends_at', $expected);
});
