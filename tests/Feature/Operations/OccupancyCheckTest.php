<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\BusinessRuleVersion;
use App\Models\Property;
use App\Models\Room;
use App\Services\Config\ConfigPublisher;
use App\Services\Inventory\ClaimService;
use App\Support\Alerts\AlertKeys;
use App\Support\BusinessTime;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
    Carbon::setTestNow(Carbon::parse('2020-01-05 18:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('one run of five low nights is one alert, and a gap splits two pairs into two alerts', function (): void {
    expect(BusinessTime::now()->toDateString())->toBe('2020-01-05');

    [$property, $rooms, $holder] = occupancyHouse();

    sellNights($rooms, $holder, '2020-01-06', '2020-01-11', 3);
    sellNights($rooms, $holder, '2020-01-11', '2020-01-12', 4);
    sellNights($rooms, $holder, '2020-01-12', '2020-01-14', 3);
    sellNights($rooms, $holder, '2020-01-14', '2020-01-15', 4);
    sellNights($rooms, $holder, '2020-01-15', '2020-01-17', 3);
    sellNights($rooms, $holder, '2020-01-17', '2020-04-05', 4);
    sellNights($rooms, $holder, '2020-04-05', '2020-04-06', 3);

    Artisan::call('iconic:occupancy-check');

    $open = Alert::query()->where('kind', AlertKind::LowOccupancy)->whereNull('resolved_at')->orderBy('base_key')->get();

    expect($open)->toHaveCount(3)
        ->and($open->pluck('base_key')->all())->toBe([
            AlertKeys::occupancyRun($property->id, '2020-01-06', '2020-01-10'),
            AlertKeys::occupancyRun($property->id, '2020-01-12', '2020-01-13'),
            AlertKeys::occupancyRun($property->id, '2020-01-15', '2020-01-16'),
        ])
        ->and($open->first()?->sentence)->toContain('30%')
        ->and($open->first()?->sentence)->toContain('5 nights');

    sellNights($rooms->slice(3, 1)->values(), $holder, '2020-01-06', '2020-01-11', 1);
    Artisan::call('iconic:occupancy-check');

    $five = Alert::query()->where('base_key', AlertKeys::occupancyRun($property->id, '2020-01-06', '2020-01-10'))->first();

    expect($five?->resolution)->toBe('the run is no longer below the threshold')
        ->and(Alert::query()->where('kind', AlertKind::LowOccupancy)->whereNull('resolved_at')->count())->toBe(2);
});

test('a minimum of three nights drops a run of two', function (): void {
    [$property, $rooms, $holder] = occupancyHouse();
    publishMinConsecutiveNights(3);

    sellNights($rooms, $holder, '2020-01-06', '2020-01-11', 3);
    sellNights($rooms, $holder, '2020-01-11', '2020-01-12', 4);
    sellNights($rooms, $holder, '2020-01-12', '2020-01-14', 3);
    sellNights($rooms, $holder, '2020-01-14', '2020-04-05', 4);

    Artisan::call('iconic:occupancy-check');

    expect(Alert::query()->where('kind', AlertKind::LowOccupancy)->pluck('base_key')->all())
        ->toBe([AlertKeys::occupancyRun($property->id, '2020-01-06', '2020-01-10')]);
});

/**
 * @return array{0: Property, 1: Collection<int, Room>, 2: Booking}
 */
function occupancyHouse(): array
{
    $property = Property::factory()->create();
    $rooms = collect(range(1, 10))->map(fn (int $number): Room => Room::factory()->create([
        'property_id' => $property->id,
        'code' => 'S'.$number,
        'label' => 'Suite '.$number,
        'sort' => $number,
    ]));
    $holder = Booking::factory()->create([
        'property_id' => $property->id,
        'room_id' => $rooms->first()?->id,
        'check_in' => '2020-01-06',
        'check_out' => '2020-04-06',
        'nights' => StayDates::of('2020-01-06', '2020-04-06')->nights(),
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2020-'.str_pad((string) $property->id, 4, '0', STR_PAD_LEFT),
    ]);

    return [$property, $rooms, $holder];
}

/**
 * @param  Collection<int, Room>  $rooms
 */
function sellNights(Collection $rooms, Booking $holder, string $from, string $to, int $sold): void
{
    $cursor = CarbonImmutable::parse($from);
    $end = CarbonImmutable::parse($to);
    $taken = $rooms->take($sold)->values();

    while ($cursor->lessThan($end)) {
        $chunkEnd = $cursor->addDays(30);
        if ($chunkEnd->greaterThan($end)) {
            $chunkEnd = $end;
        }

        $stay = StayDates::of($cursor->toDateString(), $chunkEnd->toDateString());
        DB::transaction(fn () => app(ClaimService::class)->claim($stay, $taken, $holder, ClaimKind::Booking));
        $cursor = $chunkEnd;
    }
}

function publishMinConsecutiveNights(int $nights): void
{
    $current = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    $document = $current->document;
    $document['alerts']['low_occupancy_min_consecutive_nights'] = $nights;

    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $document,
        $current->version,
        'test minimum',
        null,
    );
}
