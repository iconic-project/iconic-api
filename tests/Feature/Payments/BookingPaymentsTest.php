<?php

declare(strict_types=1);

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the booking ledger is newest first and exposes can_mark_wire', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $owner = salesExecUser();
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $owner->id,
        'reference' => 'ANK-2026-0410',
    ]);

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::Wire,
        'status' => PaymentStatus::AwaitingWire,
        'reference' => 'ANK-2026-0410-D01',
        'paid_at' => '2026-07-01',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Balance,
        'method' => PaymentMethod::CardStripe,
        'status' => PaymentStatus::Settled,
        'reference' => 'ANK-2026-0410-B01',
        'paid_at' => '2026-08-19',
    ]);

    $this->actingAs($owner)
        ->getJson('/api/rms/bookings/'.$booking->id.'/payments')
        ->assertOk()
        ->assertJsonPath('data.0.reference', 'ANK-2026-0410-B01')
        ->assertJsonPath('data.0.can_mark_wire', false)
        ->assertJsonPath('data.1.reference', 'ANK-2026-0410-D01')
        ->assertJsonPath('data.1.can_mark_wire', false)
        ->assertJsonPath('data.1.date', '2026-07-01');

    $this->actingAs(externalFinanceUser())
        ->getJson('/api/rms/bookings/'.$booking->id.'/payments')
        ->assertOk()
        ->assertJsonPath('data.1.can_mark_wire', true);
});

test('another owner cannot read the booking ledger', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $owner = User::factory()->create(['role_id' => $role->id]);
    $other = User::factory()->create(['role_id' => $role->id]);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $owner->id,
    ]);

    $this->actingAs($other)
        ->getJson('/api/rms/bookings/'.$booking->id.'/payments')
        ->assertForbidden();
});

test('a soft-deleted booking payments route is 404', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => adminUser()->id,
    ]);
    $id = $booking->id;
    $booking->delete();

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$id.'/payments')
        ->assertNotFound();
});

test('the booking show exposes real paid pledged and payments_count', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'total' => 26600,
        'deposit_pct' => 10,
        'owner_id' => adminUser()->id,
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'amount' => 2660,
        'status' => PaymentStatus::Settled,
        'reference' => 'ANK-2026-0411-D01',
    ]);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('paid', 2660)
        ->assertJsonPath('pledged', 0)
        ->assertJsonPath('balance', 23940)
        ->assertJsonPath('payments_count', 1);
});
