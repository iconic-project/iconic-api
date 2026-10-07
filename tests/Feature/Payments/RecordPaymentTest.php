<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\ChangeHistory;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('admin and external finance can record a payment and a sales exec cannot', function (): void {
    $booking = pendingCabin();

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 2660,
        ])
        ->assertForbidden();

    $this->actingAs(externalFinanceUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 2660,
        ])
        ->assertCreated()
        ->assertJsonPath('status', PaymentStatus::Settled->value)
        ->assertJsonPath('booking.status', BookingStatus::Confirmed->value);

    $other = pendingCabin([
        'departure' => ReservationFixtures::anamaraDeparture('2028-01-02'),
        'reference' => 'ANK-2026-0412',
        'room_id' => ReservationFixtures::anamaraDeparture('2028-01-02')->property->rooms->firstWhere('code', 'S2')?->id,
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$other->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 2660,
        ])
        ->assertCreated();
});

test('a settled deposit confirms a pending booking as system with the payment reference', function (): void {
    $booking = pendingCabin([
        'reference' => null,
        'request_reference' => 'ANK-R-2026-0413',
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 2660,
        ])
        ->assertCreated()
        ->assertJsonPath('reference', 'ANK-R-2026-0413-D01')
        ->assertJsonPath('booking.status', BookingStatus::Confirmed->value)
        ->assertJsonPath('warnings', []);

    $booking->refresh();
    expect($booking->reference)->toStartWith('HTL-');

    $events = ChangeHistory::query()
        ->where('subject_type', 'booking')
        ->where('subject_id', $booking->id)
        ->orderBy('id')
        ->get();

    expect($events->pluck('event')->take(2)->all())->toBe(['payment.recorded', 'booking.status_changed']);
    expect($events[1]->actor_label)->toBe('System');
    expect($events[1]->reason)->toBe('Deposit settled · ANK-R-2026-0413-D01');
});

test('a balance payment takes confirmed to fully paid', function (): void {
    $booking = pendingCabin(['status' => BookingStatus::Confirmed]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 2660,
        ])
        ->assertCreated()
        ->assertJsonPath('booking.status', BookingStatus::Confirmed->value);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 23940,
        ])
        ->assertCreated()
        ->assertJsonPath('booking.status', BookingStatus::FullyPaid->value);
});

test('one payment covering deposit and balance produces two system transitions', function (): void {
    $booking = pendingCabin();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 26600,
        ])
        ->assertCreated()
        ->assertJsonPath('booking.status', BookingStatus::FullyPaid->value);

    $changed = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.status_changed')
        ->orderBy('id')
        ->get();

    expect($changed)->toHaveCount(2);
    expect($changed[0]->after['status'] ?? null)->toBe(BookingStatus::Confirmed->value);
    expect($changed[1]->after['status'] ?? null)->toBe(BookingStatus::FullyPaid->value);
    expect($changed[1]->after['what'] ?? '')->not->toContain('marked manually');
    expect($booking->fresh()->status)->toBe(BookingStatus::FullyPaid);
});

test('a wire is pledged and does not confirm until it settles', function (): void {
    $booking = pendingCabin();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::Wire->value,
            'amount' => 2660,
        ])
        ->assertCreated()
        ->assertJsonPath('status', PaymentStatus::AwaitingWire->value)
        ->assertJsonPath('booking.status', BookingStatus::PendingPayment->value)
        ->assertJsonPath('booking.paid', 0)
        ->assertJsonPath('booking.pledged', 2660);
});

test('an overpayment is recorded and warned', function (): void {
    $booking = pendingCabin(['status' => BookingStatus::Confirmed]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 30000,
        ])
        ->assertCreated()
        ->assertJsonPath('booking.paid', 30000)
        ->assertJsonPath('warnings.0', 'This takes the booking above its total by USD 3,400');
});

test('an overpayment warning compares against charges not the cruise total', function (): void {
    $booking = pendingCabin(['status' => BookingStatus::Confirmed]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'FLT',
            'qty' => 1,
        ])
        ->assertCreated();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 27020,
        ])
        ->assertCreated()
        ->assertJsonPath('warnings', []);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Balance->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 100,
        ])
        ->assertCreated()
        ->assertJsonPath('warnings.0', 'This takes the booking above its total by USD 100');
});

test('an explicit status is only accepted for a wire and refunds are refused', function (): void {
    $booking = pendingCabin();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 2660,
            'status' => PaymentStatus::Settled->value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Refund->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 100,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kind']);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});
