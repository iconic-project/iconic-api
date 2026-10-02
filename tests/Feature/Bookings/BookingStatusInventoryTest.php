<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Models\Booking;
use App\Models\CabinClaim;
use App\Services\Inventory\ClaimService;
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

test('holdsInventory is false only for released and cancelled statuses', function (): void {
    $holding = [
        BookingStatus::Requested,
        BookingStatus::PendingPayment,
        BookingStatus::Confirmed,
        BookingStatus::FullyPaid,
        BookingStatus::OnBoard,
        BookingStatus::Completed,
        BookingStatus::Overdue,
        BookingStatus::OnHoldAgency,
        BookingStatus::Waitlisted,
    ];

    foreach ($holding as $status) {
        expect($status->holdsInventory())->toBeTrue($status->value);
    }

    expect(BookingStatus::Released->holdsInventory())->toBeFalse();
    expect(BookingStatus::Cancelled->holdsInventory())->toBeFalse();
    expect(BookingStatus::CancelledPostpaid->holdsInventory())->toBeFalse();
});

test('a cancelled factory booking has no active claim and a confirmed one can', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $cabin = $departure->property->cabins->firstWhere('code', 'S1');

    $cancelled = Booking::factory()->cancelled()->create([
        'departure_id' => $departure->id,
        'room_id' => $cabin?->id,
    ]);
    expect($cancelled->status->holdsInventory())->toBeFalse();
    expect($cancelled->claims()->whereNull('released_at')->count())->toBe(0);

    $confirmed = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $cabin?->id,
        'status' => BookingStatus::Confirmed,
    ]);

    DB::transaction(function () use ($departure, $cabin, $confirmed): void {
        app(ClaimService::class)->claim($departure, collect([$cabin]), $confirmed, ClaimKind::Booking);
    });

    expect(CabinClaim::query()->where('holder_id', $confirmed->id)->whereNull('released_at')->count())->toBe(1);
});
