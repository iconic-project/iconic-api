<?php

declare(strict_types=1);

use App\Enums\OverdueDecision;
use App\Models\ChangeHistory;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('flag overdue writes one marker per episode and never changes status', function (): void {
    $booking = overdueCabin(['reference' => 'ANK-2026-0520', 'cabin_code' => 'S7']);
    $status = $booking->status;

    Artisan::call('iconic:flag-overdue');
    Artisan::call('iconic:flag-overdue');

    expect($booking->fresh()?->status)->toBe($status);
    expect(ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.overdue_flagged')
        ->count())->toBe(1);

    $newDue = BusinessTime::now()->addDays(10)->toDateString();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/overdue-decision', [
            'decision' => OverdueDecision::Extend->value,
            'reason' => 'Extension for the episode test',
            'new_due_date' => $newDue,
        ])
        ->assertOk()
        ->assertJsonPath('overdue', false);

    $this->travelTo(CarbonImmutable::parse($newDue.' 12:00:00', 'Pacific/Galapagos')->addDay());

    Artisan::call('iconic:flag-overdue');

    expect(ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.overdue_flagged')
        ->count())->toBe(2);
    expect($booking->fresh()?->status->value)->toBe($status->value);
});

test('the overdue fixture command only runs locally and sets an override', function (): void {
    $booking = overdueCabin([
        'reference' => 'ANK-2026-0018',
        'cabin_code' => 'S8',
        'balance_due_date_override' => null,
    ]);

    Artisan::call('iconic:set-overdue-fixture', ['reference' => 'ANK-2026-0018']);

    expect($booking->fresh()?->balance_due_date_override?->toDateString())
        ->toBe(BusinessTime::now()->subDay()->toDateString());
});
