<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CommissionAccrualStatus;
use App\Enums\MainChannel;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\CommissionPayout;
use App\Models\Role;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Commissions\Accrual;
use App\Support\Commissions\CommissionKpis;
use Carbon\Carbon;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the accrual list derives accrued blocked payable and cancelled', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $past = ReservationFixtures::anamaraDeparture('2026-06-07');

    $accruedId = test()->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
            'commission_pct' => 10,
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    $blockedId = test()->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
            'commission_pct' => 15,
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    $cancelled = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S3')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Cancelled,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $payable = Booking::factory()->create([
        'departure_id' => $past->id,
        'cabin_id' => $past->property->cabins->firstWhere('code', 'S1')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Completed,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $rows = $this->actingAs(adminUser())
        ->getJson('/api/rms/commissions')
        ->assertOk()
        ->json('data');

    $byId = collect($rows)->keyBy('booking_id');
    expect($byId[$accruedId]['status'])->toBe(CommissionAccrualStatus::EarnedOnCompletion->value);
    expect($byId[$blockedId]['status'])->toBe(CommissionAccrualStatus::Blocked->value);
    expect($byId[$cancelled->id]['status'])->toBe(CommissionAccrualStatus::Cancelled->value);
    expect($byId[$payable->id]['status'])->toBe(CommissionAccrualStatus::Payable->value);
    expect($byId[$payable->id]['payable_date'])->toBe('2026-07-14');

    $this->actingAs(adminUser())
        ->getJson('/api/rms/commissions?status='.CommissionAccrualStatus::Blocked->value)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.booking_id', $blockedId);

    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/rms/commissions')
        ->assertForbidden();
});

test('the payable date is thirty days after the return date', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S4')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Completed,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $rules = app(CurrentConfig::class)->businessRules();
    $payable = Accrual::payableDate($booking, $rules)->toDateString();

    expect($booking->departure->returnDate()->toDateString())->toBe('2027-11-21');
    expect($payable)->toBe('2027-12-21');
    expect($payable)->not->toBe('2027-12-14');

    Carbon::setTestNow(Carbon::parse('2027-12-20 18:00:00', 'UTC'));
    expect(Accrual::status($booking->fresh() ?? $booking, $rules))
        ->toBe(CommissionAccrualStatus::EarnedOnCompletion);

    Carbon::setTestNow(Carbon::parse('2027-12-21 18:00:00', 'UTC'));
    expect(Accrual::status($booking->fresh() ?? $booking, $rules))
        ->toBe(CommissionAccrualStatus::Payable);

    Carbon::setTestNow();
});

test('accrual status follows cancelled, blocked, paid, payable, then earned', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-12-21 18:00:00', 'UTC'));

    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');
    $rules = app(CurrentConfig::class)->businessRules();
    $cabin = fn (string $code): ?int => $departure->property->cabins->firstWhere('code', $code)?->id;

    $cancelled = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $cabin('S1'),
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Cancelled,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);
    CommissionPayout::query()->create([
        'booking_id' => $cancelled->id,
        'amount' => $cancelled->commissionAmount(),
        'paid_on' => '2027-12-21',
        'bank_reference' => 'CANCELLED-ROW',
    ]);

    $blocked = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $cabin('S2'),
        'agency_id' => $agency->id,
        'commission_pct' => 15,
        'commission_approved' => false,
        'status' => BookingStatus::Completed,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);
    CommissionPayout::query()->create([
        'booking_id' => $blocked->id,
        'amount' => 1,
        'paid_on' => '2027-12-21',
        'bank_reference' => 'BLOCKED-ROW',
    ]);

    $paid = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $cabin('S3'),
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Completed,
        'total' => 10000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);
    CommissionPayout::query()->create([
        'booking_id' => $paid->id,
        'amount' => $paid->commissionAmount(),
        'paid_on' => '2027-12-21',
        'bank_reference' => 'PAID-ROW',
    ]);

    $payable = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $cabin('S4'),
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Completed,
        'total' => 20000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $earned = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $cabin('S5'),
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 30000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    expect(Accrual::status($cancelled->fresh() ?? $cancelled, $rules))->toBe(CommissionAccrualStatus::Cancelled);
    expect(Accrual::status($blocked->fresh() ?? $blocked, $rules))->toBe(CommissionAccrualStatus::Blocked);
    expect(Accrual::status($paid->fresh() ?? $paid, $rules))->toBe(CommissionAccrualStatus::Paid);
    expect(Accrual::status($payable->fresh() ?? $payable, $rules))->toBe(CommissionAccrualStatus::Payable);
    expect(Accrual::status($earned->fresh() ?? $earned, $rules))->toBe(CommissionAccrualStatus::EarnedOnCompletion);

    $kpis = CommissionKpis::forApproved(null, null, $rules);
    $rows = Booking::query()->where('agency_id', $agency->id)->with('commissionPayout')->get();

    expect($kpis['commission_payable'])->toBe($payable->commissionAmount());
    expect($kpis['commission_paid'])->toBe($paid->commissionAmount() + $cancelled->commissionAmount() + 1);
    expect($kpis['commission_accrued'])->toBe(
        (int) $rows->filter(fn (Booking $row): bool => $row->commission_approved && ! $row->hasCommissionPayout())
            ->sum(fn (Booking $row): int => $row->commissionAmount()),
    );

    Carbon::setTestNow();
});
