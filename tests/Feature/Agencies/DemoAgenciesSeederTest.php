<?php

declare(strict_types=1);

use App\Enums\AgencyStatus;
use App\Enums\AgencyUserStatus;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Models\Agency;
use App\Models\Booking;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoAgenciesSeeder;
use Database\Seeders\DemoBookingsSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoInventorySeeder::class);
    $this->seed(DemoBookingsSeeder::class);
});

test('demo agencies seed is idempotent and attaches the prototype fixtures', function (): void {
    $this->seed(DemoAgenciesSeeder::class);
    $this->seed(DemoAgenciesSeeder::class);

    expect(Agency::query()->count())->toBe(3);

    $blue = Agency::query()->where('reference', 'AG-001')->firstOrFail();
    expect($blue->status)->toBe(AgencyStatus::Approved);
    expect($blue->commission_pct)->toBe(10);
    expect($blue->users()->firstOrFail()->status)->toBe(AgencyUserStatus::Active);

    $seven = Booking::query()->where('reference', 'ANK-2026-0007')->firstOrFail();
    expect($seven->agency_id)->toBe($blue->id);
    expect($seven->commission_pct)->toBe(10);
    expect($seven->commission_approved)->toBeTrue();

    $pending = Agency::query()->where('reference', 'AG-003')->firstOrFail();
    expect($pending->status)->toBe(AgencyStatus::Pending);
    expect($pending->users()->firstOrFail()->status)->toBe(AgencyUserStatus::InviteOnApproval);

    $held = Booking::query()->where('reference', 'ANK-2026-0021')->firstOrFail();
    expect($held->status)->toBe(BookingStatus::OnHoldAgency);
    expect($held->commission_pct)->toBe(15);
    expect($held->commission_approved)->toBeFalse();
    expect($held->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->pluck('room_id')->unique())->toHaveCount(1);
});
