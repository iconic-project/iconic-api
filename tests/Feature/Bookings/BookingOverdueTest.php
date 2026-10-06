<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('isOverdue is false on the due day including 23:30 GALT and true the day after', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S4')?->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
        'deposit_pct' => 10,
        'balance_due_date_override' => '2026-08-14',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'amount' => 2660,
        'status' => PaymentStatus::Settled,
        'reference' => 'ANK-2026-0416-D01',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-08-13 23:30:00', 'Pacific/Galapagos'));
    expect($booking->fresh()?->isOverdue())->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-08-14 23:30:00', 'Pacific/Galapagos'));
    expect($booking->fresh()?->isOverdue())->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-08-15 00:30:00', 'Pacific/Galapagos'));
    $fresh = $booking->fresh();
    expect($fresh?->isOverdue())->toBeTrue();
    expect($fresh?->overdueDays())->toBe(1);
});

test('a pending payment booking is never overdue', function (): void {
    $booking = pendingCabin([
        'balance_due_date_override' => '2020-01-01',
    ]);

    expect($booking->isOverdue())->toBeFalse();
});

test('the overdue index filter and kpis use paidValues and sum at least two bookings', function (): void {
    $first = overdueCabin(['reference' => 'ANK-2026-0501', 'cabin_code' => 'S1']);
    $second = overdueCabin([
        'reference' => 'ANK-2026-0502',
        'cabin_code' => 'S2',
        'departure' => ReservationFixtures::anamaraDeparture('2028-02-06'),
    ]);
    Payment::factory()->create([
        'booking_id' => $second->id,
        'kind' => PaymentKind::Refund,
        'status' => PaymentStatus::Refunded,
        'amount' => -1000,
        'reference' => 'ANK-2026-0502-R01',
    ]);

    $current = overdueCabin([
        'reference' => 'ANK-2026-0503',
        'cabin_code' => 'S3',
        'balance_due_date_override' => BusinessTime::now()->toDateString(),
    ]);

    $admin = adminUser();

    $firstBalance = $this->actingAs($admin)
        ->getJson('/api/rms/bookings/'.$first->id)
        ->assertOk()
        ->assertJsonPath('overdue', true)
        ->json('balance');
    $secondBalance = $this->actingAs($admin)
        ->getJson('/api/rms/bookings/'.$second->id)
        ->assertOk()
        ->assertJsonPath('overdue', true)
        ->json('balance');
    $this->actingAs($admin)
        ->getJson('/api/rms/bookings/'.$current->id)
        ->assertOk()
        ->assertJsonPath('overdue', false);

    expect($secondBalance)->toBe($second->fresh()?->total - $second->depositAmount() + 1000);

    $index = $this->actingAs($admin)
        ->getJson('/api/rms/bookings?overdue=1')
        ->assertOk();

    $ids = collect($index->json('data'))->pluck('id')->all();
    expect($ids)->toContain($first->id, $second->id);
    expect($ids)->not->toContain($current->id);

    expect($index->json('meta.kpis.overdue_count'))->toBe(2);
    expect($index->json('meta.kpis.overdue_amount'))->toBe($firstBalance + $secondBalance);
});
