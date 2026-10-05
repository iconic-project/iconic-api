<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Enums\ReleaseReason;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Models\RoomNightClaim;
use App\Services\Inventory\LegacyDepartureClaims;
use Database\Seeders\InventorySeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(InventorySeeder::class);
});

test('the job releases expired holds in batches and writes hold.expired', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $departure = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-08-06',
    ]);
    $expired = ClaimHolder::query()->create(['reference' => 'EXP-1', 'name' => 'Expired']);
    $live = ClaimHolder::query()->create(['reference' => 'LIVE-1', 'name' => 'Live']);
    $s1 = $property->cabins()->where('code', 'S1')->firstOrFail();
    $s2 = $property->cabins()->where('code', 'S2')->firstOrFail();

    DB::transaction(function () use ($departure, $s1, $s2, $expired, $live): void {
        $service = app(LegacyDepartureClaims::class);
        $service->claim($departure, collect([$s1]), $expired, ClaimKind::Hold, HoldType::Web, now()->addMinutes(20));
        $service->claim($departure, collect([$s2]), $live, ClaimKind::Hold, HoldType::Agency, now()->addDay());
    });

    RoomNightClaim::query()->where('holder_id', $expired->id)->update(['expires_at' => now()->subMinute()]);

    $this->artisan('inventory:release-expired-holds')->assertSuccessful();

    expect(RoomNightClaim::query()->where('holder_id', $expired->id)->value('release_reason'))->toBe(ReleaseReason::Expired);
    expect(RoomNightClaim::query()->where('holder_id', $live->id)->value('released_at'))->toBeNull();
    expect(ChangeHistory::query()->where('event', 'hold.expired')->where('subject_id', $expired->id)->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'hold.expired')->value('actor_id'))->toBeNull();
    expect(ChangeHistory::query()->where('event', 'hold.expired')->value('actor_label'))->toBe('System');
});

test('the release command is scheduled every minute without overlapping', function (): void {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(
        fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'inventory:release-expired-holds'),
    );

    expect($event)->not->toBeNull();
    expect($event?->expression)->toBe('* * * * *');
    expect($event?->withoutOverlapping)->toBeTrue();
});
