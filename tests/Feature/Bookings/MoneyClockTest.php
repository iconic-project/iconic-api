<?php

declare(strict_types=1);

use App\Actions\Refunds\CreateRefundRequest;
use App\Enums\BookingStatus;
use App\Enums\CommissionAccrualStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Agencies\AgencyBookingWindow;
use App\Support\BusinessTime;
use App\Support\Commissions\Accrual;
use App\Support\Payments\WireWindow;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-02-01 12:00:00', BusinessTime::zone()));
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    publishStayRates();
});

test('a thursday arrival measures balance, reminders, and a short lead from check-in', function (): void {
    $booking = moneyClockStay();
    $rules = app(CurrentConfig::class)->businessRules();
    $due = $booking->balanceDueDate();

    expect($booking->stay()->checkIn()->toDateString())->toBe('2026-02-05')
        ->and($due->toDateString())->toBe('2026-01-15')
        ->and($due->subDays($rules->payments->balanceReminderDays[0])->toDateString())->toBe('2025-12-25')
        ->and($due->subDays($rules->payments->balanceReminderDays[1])->toDateString())->toBe('2026-01-08')
        ->and($booking->cruiseOutstanding())->toBe(100);

    $payment = Payment::factory()->create([
        'booking_id' => $booking->id,
        'status' => PaymentStatus::AwaitingWire,
        'amount' => 100,
    ]);

    expect(WireWindow::endsAtFor($payment)?->equalTo(
        CarbonImmutable::instance($payment->created_at)->addHours($rules->payments->wireWindowHours),
    ))->toBeTrue();
});

test('a pay-at-hotel stay is not overdue before check-out', function (): void {
    $booking = moneyClockStay();
    $booking->deposit_pct = 0;
    $booking->balance_days = 0;
    $booking->status = BookingStatus::Confirmed;
    $booking->save();

    $this->travelTo(CarbonImmutable::parse('2026-02-04 12:00:00', BusinessTime::zone()));
    expect($booking->fresh()?->isOverdue())->toBeFalse();
    $this->artisan('iconic:flag-overdue')->assertSuccessful();
    expect(ChangeHistory::query()->where('event', 'booking.overdue_flagged')->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-02-05 12:00:00', BusinessTime::zone()));
    expect($booking->fresh()?->isOverdue())->toBeFalse();
    expect($booking->fresh()?->balanceDueDate()->toDateString())->toBe('2026-02-05');

    $this->travelTo(CarbonImmutable::parse('2026-02-06 00:30:00', BusinessTime::zone()));
    expect($booking->fresh()?->isOverdue())->toBeTrue();
    $this->artisan('iconic:flag-overdue')->assertSuccessful();
    expect(ChangeHistory::query()->where('event', 'booking.overdue_flagged')->count())->toBe(1);
});

test('cancellation days run to check-in on the rate plan set', function (): void {
    $booking = moneyClockStay();
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'status' => PaymentStatus::Settled,
        'amount' => 100,
    ]);
    $this->travelTo(CarbonImmutable::parse('2025-10-01 12:00:00', BusinessTime::zone()));

    $request = app(CreateRefundRequest::class)->handle($booking->fresh() ?? $booking, $booking->owner);

    expect($request?->days_before_departure)->toBe(127)
        ->and($request?->penalty_pct)->toBe(5)
        ->and($request?->penalty_amount)->toBe(5)
        ->and($request?->band_source)->toBe('CABIN');
});

test('commission is payable thirty days after check-out and a no-show does not become payable', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $checkedOut = moneyClockStay();
    $checkedOut->agency_id = $agency->id;
    $checkedOut->commission_pct = 10;
    $checkedOut->commission_approved = true;
    $checkedOut->status = BookingStatus::CheckedOut;
    $checkedOut->save();

    $noShow = moneyClockStay();
    $noShow->agency_id = $agency->id;
    $noShow->commission_pct = 10;
    $noShow->commission_approved = true;
    $noShow->status = BookingStatus::NoShow;
    $noShow->save();

    $rules = app(CurrentConfig::class)->businessRules();
    expect(Accrual::payableDate($checkedOut->fresh() ?? $checkedOut, $rules)->toDateString())->toBe('2026-03-08');

    $this->travelTo(CarbonImmutable::parse('2026-03-07 12:00:00', BusinessTime::zone()));
    expect(Accrual::status($checkedOut->fresh() ?? $checkedOut, $rules))->toBe(CommissionAccrualStatus::EarnedOnCompletion);

    $this->travelTo(CarbonImmutable::parse('2026-03-08 12:00:00', BusinessTime::zone()));
    expect(Accrual::status($checkedOut->fresh() ?? $checkedOut, $rules))->toBe(CommissionAccrualStatus::Payable);
    expect(Accrual::status($noShow->fresh() ?? $noShow, $rules))->toBe(CommissionAccrualStatus::EarnedOnCompletion);
});

test('an agency window filters on check-in', function (): void {
    $booking = moneyClockStay();

    expect(AgencyBookingWindow::inRange(collect([$booking]), '2026-02-05', '2026-02-05'))->toHaveCount(1);
    expect(AgencyBookingWindow::inRange(collect([$booking]), '2026-02-06', null))->toHaveCount(0);
    expect($booking->departure_id)->toBeNull();
});

function moneyClockStay(): Booking
{
    $type = RoomType::query()->where('code', 'STD')->firstOrFail();
    Room::query()->create([
        'property_id' => $type->property_id,
        'room_type_id' => $type->id,
        'code' => 'M'.substr(uniqid(), -6),
        'label' => 'Money clock',
        'sort' => 1,
        'status' => RoomStatus::Active,
    ]);

    $id = test()->actingAs(managerUser())
        ->postJson('/api/rms/bookings', [
            'check_in' => '2026-02-05',
            'check_out' => '2026-02-06',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
                'child_ages' => [],
            ]],
            'client' => [
                'name' => 'Thursday Guest',
                'email' => 'thu-'.uniqid().'@iconic.test',
            ],
            'main_channel' => 'D2C',
            'channel_of_origin' => 'Hotel Booking Engine',
            'expected_total' => 100,
        ])
        ->assertCreated()
        ->json('bookings.0.id');

    return Booking::query()->findOrFail($id);
}
