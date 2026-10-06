<?php

declare(strict_types=1);

use App\Enums\AgencyStatus;
use App\Enums\AgencyUserStatus;
use App\Enums\BookingStatus;
use App\Enums\MainChannel;
use App\Models\Agency;
use App\Models\AgencyUser;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\CommissionPayout;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use App\Support\Agencies\PortalPreview;
use App\Support\Rounding;
use Carbon\Carbon;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('finance records one immutable payout and the kpis move', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-12-21 18:00:00', 'UTC'));

    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::CheckedOut,
        'total' => 23275,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);
    $amount = $booking->commissionAmount();
    expect($amount)->toBe(2328);

    $before = [
        'status' => $booking->status->value,
        'total' => $booking->total,
        'commission_pct' => $booking->commission_pct,
        'commission_approved' => $booking->commission_approved,
    ];

    $this->actingAs(managerUser())
        ->postJson('/api/rms/commissions/'.$booking->id.'/payout', [
            'amount' => $amount,
            'paid_on' => '2027-12-21',
            'bank_reference' => 'WIRE-100',
        ])
        ->assertForbidden();

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/commissions/'.$booking->id.'/payout', [
            'amount' => $amount,
            'paid_on' => '2027-12-21',
            'bank_reference' => 'WIRE-100',
        ])
        ->assertForbidden();

    $this->actingAs(externalFinanceUser())
        ->postJson('/api/rms/commissions/'.$booking->id.'/payout', [
            'amount' => $amount - 1,
            'paid_on' => '2027-12-21',
            'bank_reference' => 'WIRE-SHORT',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    $early = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S2')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 10000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $this->actingAs(externalFinanceUser())
        ->postJson('/api/rms/commissions/'.$early->id.'/payout', [
            'amount' => $early->commissionAmount(),
            'paid_on' => '2027-12-21',
            'bank_reference' => 'TOO-SOON',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking');

    $this->actingAs(externalFinanceUser())
        ->postJson('/api/rms/commissions/'.$booking->id.'/payout', [
            'amount' => $amount,
            'paid_on' => '2027-12-21',
            'bank_reference' => 'WIRE-100',
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'PAID')
        ->assertJsonPath('payout.amount', $amount)
        ->assertJsonPath('payout.paid_on', '2027-12-21')
        ->assertJsonPath('payout.bank_reference', 'WIRE-100');

    $fresh = $booking->fresh() ?? $booking;
    expect($fresh->status->value)->toBe($before['status']);
    expect($fresh->total)->toBe($before['total']);
    expect($fresh->commission_pct)->toBe($before['commission_pct']);
    expect($fresh->commission_approved)->toBe($before['commission_approved']);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/commissions/'.$booking->id.'/payout', [
            'amount' => $amount,
            'paid_on' => '2027-12-22',
            'bank_reference' => 'WIRE-AGAIN',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking');

    $payout = CommissionPayout::query()->where('booking_id', $booking->id)->firstOrFail();

    expect(fn () => DB::table('commission_payouts')->where('id', $payout->id)->update(['amount' => 1]))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::table('commission_payouts')->where('id', $payout->id)->delete())
        ->toThrow(QueryException::class, 'append-only');

    expect(ChangeHistory::query()->where('event', 'booking.commission_paid')->where('subject_id', $booking->id)->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'agency.commission_paid')->where('subject_id', $agency->id)->count())->toBe(1);

    $kpis = $this->actingAs(managerUser())
        ->getJson('/api/rms/agencies')
        ->assertOk()
        ->json('meta.kpis');

    expect($kpis['commission_paid'])->toBe($amount);
    expect($kpis['commission_payable'])->toBe(0);
    expect($kpis['commission_accrued'])->toBe($early->commissionAmount());

    Mail::assertNothingSent();
});

test('agency users are created without email and approval moves them', function (): void {
    $pending = Agency::factory()->pending()->create();
    $approved = Agency::factory()->create();

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/agencies/'.$pending->id.'/users', [
            'name' => 'Pat',
            'email' => 'pat@andes.test',
        ])
        ->assertForbidden();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/agencies/'.$pending->id.'/users', [
            'name' => 'Pat',
            'email' => 'pat@andes.test',
        ])
        ->assertCreated()
        ->assertJsonPath('users.0.status', AgencyUserStatus::InviteOnApproval->value);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/agencies/'.$pending->id.'/users', [
            'name' => 'Pat again',
            'email' => 'pat@andes.test',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->actingAs(managerUser())
        ->postJson('/api/rms/agencies/'.$approved->id.'/users', [
            'name' => 'Pat',
            'email' => 'pat@andes.test',
        ])
        ->assertCreated()
        ->assertJsonPath('users.0.status', AgencyUserStatus::InviteOnPortalLaunch->value);

    $user = AgencyUser::query()->where('agency_id', $pending->id)->firstOrFail();

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/agencies/'.$approved->id.'/users/'.$user->id, [
            'status' => AgencyUserStatus::Disabled->value,
        ])
        ->assertNotFound();

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/agencies/'.$pending->id.'/users/'.$user->id, [
            'name' => 'Patricia',
            'status' => AgencyUserStatus::Disabled->value,
        ])
        ->assertOk()
        ->assertJsonPath('users.0.name', 'Patricia')
        ->assertJsonPath('users.0.status', AgencyUserStatus::Disabled->value);

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/agencies/'.$pending->id.'/users/'.$user->id, [
            'status' => AgencyUserStatus::InviteOnApproval->value,
        ])
        ->assertUnprocessable();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/agencies/'.$pending->id.'/decide', [
            'decision' => AgencyStatus::Approved->value,
        ])
        ->assertOk()
        ->assertJsonPath('users.0.status', AgencyUserStatus::InviteOnPortalLaunch->value);

    expect(ChangeHistory::query()->where('event', 'agency.user_created')->count())->toBe(2);
    expect(ChangeHistory::query()->where('event', 'agency.user_updated')->count())->toBe(1);
    Mail::assertNothingSent();
});

test('the portal preview shows net rates and only that agency', function (): void {
    $rates = app(CurrentConfig::class)->rates();
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $other = Agency::factory()->create(['commission_pct' => 12]);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');

    $booking = Booking::factory()->create([
        'reference' => 'ANK-2026-8801',
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 10000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'is_lead' => true,
        'first_name' => 'Mariana',
        'last_name' => 'Castellanos',
    ]);
    $otherBooking = Booking::factory()->create([
        'reference' => 'ANK-2026-8802',
        'room_id' => $departure->property->rooms->firstWhere('code', 'S2')?->id,
        'agency_id' => $other->id,
        'commission_pct' => 12,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 50000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/agencies/'.$agency->id.'/portal-preview')
        ->assertForbidden();

    $response = $this->actingAs(managerUser())
        ->getJson('/api/rms/agencies/'.$agency->id.'/portal-preview')
        ->assertOk()
        ->assertJsonPath('sales_materials.note', PortalPreview::MATERIALS_NOTE)
        ->assertJsonPath('sales_materials.items', PortalPreview::MATERIALS)
        ->assertJsonPath('bookings.0.reference', 'ANK-2026-8801')
        ->assertJsonPath('bookings.0.lead_guest', 'Mariana Castellanos')
        ->assertJsonPath('bookings.0.departure_date', '2027-11-14')
        ->assertJsonPath('bookings.0.net_due', Rounding::halfUp($booking->fresh()?->balance() * 90 / 100))
        ->assertJsonPath('commissions.0.rate', 10)
        ->assertJsonPath('commissions.0.commission_amount', $booking->commissionAmount())
        ->assertJsonPath('commissions.0.payable_date', '2027-12-21')
        ->assertJsonPath('commissions.0.status', 'EARNED_ON_COMPLETION')
        ->assertJsonCount(1, 'bookings')
        ->assertJsonCount(1, 'commissions');

    $json = $response->json();
    $encoded = json_encode($json);
    expect($encoded)->toBeString();
    expect($encoded)->not->toContain($otherBooking->reference);

    $public = [];
    foreach ($rates->roomRates as $rate) {
        $public[] = $rate->nightly;
    }

    $found = [];
    $walk = function (mixed $value) use (&$walk, &$found, $public): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $walk($item);
            }

            return;
        }

        if (is_int($value) && in_array($value, $public, true)) {
            $found[] = $value;
        }
    };
    $walk($json);
    expect($found)->toBe([]);

    $net = $json['net_rates'];
    expect($net)->toBe([]);
});
