<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function indexPaymentBooking(User $owner, string $cabin, string $reference): Booking
{
    $departure = ReservationFixtures::anamaraDeparture();

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', $cabin)?->id,
        'owner_id' => $owner->id,
        'reference' => $reference,
    ]);
}

test('finance sees every payment and a sales exec only sees their own', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $owner = User::factory()->create(['role_id' => $role->id]);
    $other = User::factory()->create(['role_id' => $role->id]);
    $mine = indexPaymentBooking($owner, 'S1', 'ANK-2026-0401');
    $theirs = indexPaymentBooking($other, 'S2', 'ANK-2026-0402');

    Payment::factory()->create([
        'booking_id' => $mine->id,
        'reference' => 'ANK-2026-0401-D01',
        'paid_at' => '2026-07-02',
    ]);
    Payment::factory()->create([
        'booking_id' => $theirs->id,
        'reference' => 'ANK-2026-0402-D01',
        'paid_at' => '2026-07-03',
    ]);

    $this->actingAs($owner)
        ->getJson('/api/rms/payments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'ANK-2026-0401-D01')
        ->assertJsonPath('data.0.booking.display_reference', 'ANK-2026-0401')
        ->assertJsonPath('data.0.booking.client', $mine->contact->name)
        ->assertJsonPath('data.0.date', '2026-07-02');

    $this->actingAs(externalFinanceUser())
        ->getJson('/api/rms/payments')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('the ledger filters by date kind method status and q', function (): void {
    $owner = adminUser();
    $booking = indexPaymentBooking($owner, 'S1', 'ANK-2026-0403');

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::CardStripe,
        'status' => PaymentStatus::Settled,
        'reference' => 'ANK-2026-0403-D01',
        'paid_at' => '2026-07-02',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Balance,
        'method' => PaymentMethod::Wire,
        'status' => PaymentStatus::AwaitingWire,
        'reference' => 'ANK-2026-0403-B01',
        'paid_at' => '2026-08-19',
    ]);

    $this->actingAs($owner)
        ->getJson('/api/rms/payments?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'ANK-2026-0403-B01');

    $this->actingAs($owner)
        ->getJson('/api/rms/payments?kind=DEPOSIT')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'DEPOSIT');

    $this->actingAs($owner)
        ->getJson('/api/rms/payments?q=0403-B01')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($owner)
        ->getJson('/api/rms/payments?kind=NOT_A_KIND')
        ->assertStatus(422);
});

test('payments on a soft-deleted booking are omitted from the ledger', function (): void {
    $owner = adminUser();
    $booking = indexPaymentBooking($owner, 'S1', 'ANK-2026-0404');
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'reference' => 'ANK-2026-0404-D01',
    ]);

    $booking->delete();

    $this->actingAs($owner)
        ->getJson('/api/rms/payments')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the ledger query count does not grow with extra payments', function (): void {
    $actor = managerUser();
    $booking = indexPaymentBooking($actor, 'S1', 'ANK-2026-0405');
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'reference' => 'ANK-2026-0405-D01',
        'paid_at' => '2026-07-01',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/rms/payments')
        ->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($actor)
        ->getJson('/api/rms/payments')
        ->assertOk();
    $before = count(DB::getQueryLog());

    foreach (['D02', 'D03', 'D04'] as $suffix) {
        Payment::factory()->create([
            'booking_id' => $booking->id,
            'reference' => 'ANK-2026-0405-'.$suffix,
            'paid_at' => '2026-07-02',
        ]);
    }

    DB::flushQueryLog();
    $this->actingAs($actor)
        ->getJson('/api/rms/payments')
        ->assertOk()
        ->assertJsonCount(4, 'data');
    $after = count(DB::getQueryLog());

    expect($after)->toBe($before);
});

test('ledger kpis reuse paidValues overdue and Accrual and pending includes overdue', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $sales = User::factory()->create(['role_id' => $role->id]);
    $other = managerUser();
    $agency = Agency::factory()->create(['commission_pct' => 10]);

    $overdue = overdueCabin([
        'reference' => 'ANK-2026-0601',
        'cabin_code' => 'S1',
        'owner_id' => $sales->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
    ]);
    $pending = pendingCabin([
        'reference' => 'ANK-2026-0602',
        'owner_id' => $sales->id,
        'room_id' => ReservationFixtures::anamaraDeparture()->property->rooms->firstWhere('code', 'S2')?->id,
    ]);
    $confirmed = Booking::factory()->create([
        'room_id' => ReservationFixtures::anamaraDeparture()->property->rooms->firstWhere('code', 'S3')?->id,
        'owner_id' => $sales->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-0603',
        'total' => 26600,
        'deposit_pct' => 10,
    ]);
    Payment::factory()->create([
        'booking_id' => $confirmed->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => $confirmed->depositAmount(),
        'reference' => 'ANK-2026-0603-D01',
        'paid_at' => '2026-07-02',
    ]);
    $theirs = indexPaymentBooking($other, 'S4', 'ANK-2026-0604');
    Payment::factory()->create([
        'booking_id' => $theirs->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => $theirs->depositAmount(),
        'reference' => 'ANK-2026-0604-D01',
        'paid_at' => '2026-07-03',
    ]);
    Payment::factory()->create([
        'booking_id' => $pending->id,
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::Wire,
        'status' => PaymentStatus::AwaitingWire,
        'amount' => $pending->depositAmount(),
        'reference' => 'ANK-2026-0602-D01',
        'paid_at' => '2026-07-04',
    ]);

    $overdueBalance = $overdue->fresh()?->balance();
    $pendingBalance = $pending->fresh()?->balance();
    $confirmedBalance = $confirmed->fresh()?->balance();
    expect($overdueBalance)->toBeInt()->toBeGreaterThan(0);
    expect($pendingBalance)->toBeInt()->toBeGreaterThan(0);
    expect($confirmedBalance)->toBeInt()->toBeGreaterThan(0);

    $config = app(CurrentConfig::class);
    $terms = $config->rates()->terms;

    $own = $this->actingAs($sales)
        ->getJson('/api/rms/payments')
        ->assertOk();

    expect($own->json('meta.kpis.collected'))->toBe(
        $overdue->depositAmount() + $confirmed->depositAmount(),
    );
    expect($own->json('meta.kpis.deposits'))->toBe(
        $overdue->depositAmount() + $confirmed->depositAmount(),
    );
    expect($own->json('meta.kpis.pending'))->toBe($overdueBalance + $pendingBalance + $confirmedBalance);
    expect($own->json('meta.kpis.pending'))->toBeGreaterThan($own->json('meta.kpis.overdue_amount'));
    expect($own->json('meta.kpis.pending_count'))->toBe(3);
    expect($own->json('meta.kpis.overdue_count'))->toBe(1);
    expect($own->json('meta.kpis.overdue_amount'))->toBe($overdueBalance);
    expect($own->json('meta.kpis.commission_accrued'))->toBe($overdue->fresh()?->commissionAmount());
    expect($own->json('meta.kpis.cabin_deposit_pct'))->toBe($terms->cabinDepositPct);
    expect($own->json('meta.kpis.charter_deposit_pct'))->toBe($terms->charterDepositPct);
    expect($own->json('meta.kpis.cabin_balance_days'))->toBe($terms->cabinBalanceDays);
    expect($own->json('meta.kpis.commission_payable_days'))->toBe(
        $config->businessRules()->commission->payableDaysAfterCheckOut,
    );
    expect($own->json('meta.kpis.commission_cap_pct'))->toBe($config->businessRules()->commission->capPct);
    expect($own->json('meta.kpis.wire_window_hours'))->toBe($config->businessRules()->payments->wireWindowHours);

    $pendingList = $this->actingAs($sales)
        ->getJson('/api/rms/bookings?pending_payment=1')
        ->assertOk();
    expect($own->json('meta.kpis.pending_count'))->toBe($pendingList->json('meta.total'));

    $pendingWindow = $this->actingAs($sales)
        ->getJson('/api/rms/payments?from=2026-01-01&to=2029-12-31')
        ->assertOk();
    $pendingWindowList = $this->actingAs($sales)
        ->getJson('/api/rms/bookings?pending_payment=1&from=2026-01-01&to=2029-12-31')
        ->assertOk();
    expect($pendingWindow->json('meta.kpis.pending_count'))->toBe($pendingWindowList->json('meta.total'));

    $finance = $this->actingAs(externalFinanceUser())
        ->getJson('/api/rms/payments')
        ->assertOk();

    expect($finance->json('meta.kpis.collected'))->toBe(
        $own->json('meta.kpis.collected') + $theirs->depositAmount(),
    );
    expect($finance->json('meta.kpis.pending_count'))->toBe(4);

    $financePending = $this->actingAs(externalFinanceUser())
        ->getJson('/api/rms/bookings?pending_payment=1')
        ->assertOk();
    expect($finance->json('meta.kpis.pending_count'))->toBe($financePending->json('meta.total'));
});

test('pending_payment lists owing bookings and excludes requested and paid-off', function (): void {
    $admin = adminUser();
    $owing = pendingCabin(['reference' => 'ANK-2026-0610']);
    $requested = Booking::factory()->create([
        'room_id' => ReservationFixtures::anamaraDeparture()->property->rooms->firstWhere('code', 'S2')?->id,
        'owner_id' => $admin->id,
        'status' => BookingStatus::Requested,
        'reference' => 'ANK-2026-0611',
        'total' => 26600,
    ]);
    $paid = Booking::factory()->create([
        'room_id' => ReservationFixtures::anamaraDeparture()->property->rooms->firstWhere('code', 'S3')?->id,
        'owner_id' => $admin->id,
        'status' => BookingStatus::FullyPaid,
        'reference' => 'ANK-2026-0612',
        'total' => 26600,
        'deposit_pct' => 10,
    ]);
    Payment::factory()->create([
        'booking_id' => $paid->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => $paid->total,
        'reference' => 'ANK-2026-0612-D01',
    ]);

    $index = $this->actingAs($admin)
        ->getJson('/api/rms/bookings?pending_payment=1')
        ->assertOk();

    $ids = collect($index->json('data'))->pluck('id')->all();
    expect($ids)->toContain($owing->id);
    expect($ids)->not->toContain($requested->id, $paid->id);
});

test('a role without panel.rms cannot list payments', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelCrm],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/rms/payments')
        ->assertForbidden();
});
