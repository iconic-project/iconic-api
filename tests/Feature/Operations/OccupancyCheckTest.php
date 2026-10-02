<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\CabinCategory;
use App\Enums\ClaimKind;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\Cabin;
use App\Models\CabinClaim;
use App\Models\Departure;
use App\Models\Yacht;
use App\Support\Alerts\AlertKeys;
use App\Support\BusinessTime;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
    Carbon::setTestNow(Carbon::parse('2020-01-05 18:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('occupancy alerts sit below the percent and inside the day window, and a sailed departure resolves', function (): void {
    expect(BusinessTime::now()->toDateString())->toBe('2020-01-05');

    $yacht = Yacht::factory()->create();
    $cabins = collect(range(1, 10))->map(fn (int $number): Cabin => Cabin::factory()->create([
        'yacht_id' => $yacht->id,
        'code' => 'S'.$number,
        'label' => 'Suite '.$number,
        'category' => CabinCategory::Suite,
        'sort' => $number,
    ]));
    $holder = Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2020-0001',
    ]);

    $low = occupiedDeparture($yacht, $cabins, $holder, '2020-02-02', 3);
    $exact = occupiedDeparture($yacht, $cabins, $holder, '2020-02-09', 4);
    $boundary = occupiedDeparture($yacht, $cabins, $holder, '2020-04-04', 3);
    $outside = occupiedDeparture($yacht, $cabins, $holder, '2020-04-05', 3);

    Artisan::call('iconic:occupancy-check');

    $lowAlert = Alert::query()->where('base_key', AlertKeys::occupancy($low->id))->first();

    expect($lowAlert?->kind)->toBe(AlertKind::LowOccupancy)
        ->and($lowAlert?->sentence)->toContain('30%')
        ->and(Alert::query()->where('base_key', AlertKeys::occupancy($exact->id))->exists())->toBeFalse()
        ->and(Alert::query()->where('base_key', AlertKeys::occupancy($boundary->id))->whereNull('resolved_at')->exists())->toBeTrue()
        ->and(Alert::query()->where('base_key', AlertKeys::occupancy($outside->id))->exists())->toBeFalse();

    $low->date = '2020-01-05';
    $low->save();
    Artisan::call('iconic:occupancy-check');

    expect($lowAlert?->fresh()?->resolution)->toBe('occupancy is no longer below the threshold, or the departure has sailed');
});

/**
 * @param  Collection<int, Cabin>  $cabins
 */
function occupiedDeparture(Yacht $yacht, Collection $cabins, Booking $holder, string $date, int $sold): Departure
{
    $departure = Departure::factory()->create([
        'yacht_id' => $yacht->id,
        'date' => $date,
        'reference' => 'DEP-'.str_replace('-', '', $date),
    ]);

    foreach ($cabins->take($sold) as $cabin) {
        CabinClaim::query()->create([
            'departure_id' => $departure->id,
            'cabin_id' => $cabin->id,
            'holder_type' => $holder->getMorphClass(),
            'holder_id' => $holder->id,
            'kind' => ClaimKind::Booking,
        ]);
    }

    return $departure;
}
