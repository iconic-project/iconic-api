<?php

declare(strict_types=1);

use App\Actions\Departures\UpdateDeparture;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Exceptions\CabinUnavailableException;
use App\Models\Cabin;
use App\Models\CabinClaim;
use App\Models\CheckoutSession;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Services\Inventory\ClaimService;
use Database\Seeders\InventorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(InventorySeeder::class);

    config(['database.connections.mysql_lock' => config('database.connections.mysql')]);
    DB::purge('mysql_lock');
    DB::connection('mysql_lock')->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function onClaimConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function claimMysqlError(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

/**
 * @return array{departure: Departure, cabin: Cabin}
 */
function concurrencyCabin(): array
{
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $departure = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);

    return [
        'departure' => $departure,
        'cabin' => $property->cabins()->where('code', 'S1')->firstOrFail(),
    ];
}

test('two claimers of the same free cabin never deadlock or double-occupy', function (): void {
    ['departure' => $departure, 'cabin' => $cabin] = concurrencyCabin();
    $first = ClaimHolder::query()->create(['reference' => 'C1', 'name' => 'One']);
    $second = ClaimHolder::query()->create(['reference' => 'C2', 'name' => 'Two']);
    $observed = 'none';

    onClaimConnection('mysql', function () use ($departure, $cabin, $first): void {
        DB::beginTransaction();
        app(ClaimService::class)->claim($departure, collect([$cabin]), $first, ClaimKind::Block);
    });

    onClaimConnection('mysql_lock', function () use ($departure, $cabin, $second, &$observed): void {
        DB::beginTransaction();

        try {
            app(ClaimService::class)->claim($departure, collect([$cabin]), $second, ClaimKind::Block);
            expect(false)->toBeTrue('the second claim should not succeed while the first is open');
        } catch (CabinUnavailableException) {
            expect(false)->toBeTrue('the second claim should wait on the departure row (1205), not 409');
        } catch (QueryException $e) {
            $code = claimMysqlError($e);
            expect($code)->toBe(1205);
            $observed = (string) $code;
            DB::rollBack();
        }
    });

    onClaimConnection('mysql', function (): void {
        DB::commit();
    });

    expect(CabinClaim::query()->whereNull('released_at')->where('cabin_id', $cabin->id)->count())->toBe(1);
    expect($observed)->toBe('1205');
    fwrite(STDOUT, "free-cabin concurrency observed: {$observed}\n");
});

test('two claimers of the same expired hold never deadlock or double-occupy', function (): void {
    ['departure' => $departure, 'cabin' => $cabin] = concurrencyCabin();
    $expired = ClaimHolder::query()->create(['reference' => 'OLD', 'name' => 'Old']);
    $first = ClaimHolder::query()->create(['reference' => 'N1', 'name' => 'New one']);
    $second = ClaimHolder::query()->create(['reference' => 'N2', 'name' => 'New two']);
    $observed = 'none';

    DB::transaction(function () use ($departure, $cabin, $expired): void {
        app(ClaimService::class)->claim(
            $departure,
            collect([$cabin]),
            $expired,
            ClaimKind::Hold,
            HoldType::Web,
            now()->addMinutes(20),
        );
    });

    CabinClaim::query()->where('holder_id', $expired->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    onClaimConnection('mysql', function () use ($departure, $cabin, $first): void {
        DB::beginTransaction();
        app(ClaimService::class)->claim($departure, collect([$cabin]), $first, ClaimKind::Block);
    });

    onClaimConnection('mysql_lock', function () use ($departure, $cabin, $second, &$observed): void {
        DB::beginTransaction();

        try {
            app(ClaimService::class)->claim($departure, collect([$cabin]), $second, ClaimKind::Block);
            expect(false)->toBeTrue('the second claim should not succeed while the first is open');
        } catch (CabinUnavailableException) {
            expect(false)->toBeTrue('the second claim should wait on the departure row (1205), not 409');
        } catch (QueryException $e) {
            $code = claimMysqlError($e);
            expect($code)->toBe(1205);
            $observed = (string) $code;
            DB::rollBack();
        }
    });

    onClaimConnection('mysql', function (): void {
        DB::commit();
    });

    expect(CabinClaim::query()->whereNull('released_at')->where('cabin_id', $cabin->id)->count())->toBe(1);
    expect($observed)->toBe('1205');
    fwrite(STDOUT, "expired-hold concurrency observed: {$observed}\n");
});

test('a claim holds the departure so a date change waits', function (): void {
    ['departure' => $departure, 'cabin' => $cabin] = concurrencyCabin();
    $holder = ClaimHolder::query()->create(['reference' => 'C-LOCK', 'name' => 'Claimer']);
    $observed = 'none';

    onClaimConnection('mysql', function () use ($departure, $cabin, $holder): void {
        DB::beginTransaction();
        app(ClaimService::class)->claim($departure, collect([$cabin]), $holder, ClaimKind::Block);
    });

    onClaimConnection('mysql_lock', function () use ($departure, &$observed): void {
        try {
            app(UpdateDeparture::class)->handle($departure, ['date' => '2028-04-09']);
            expect(false)->toBeTrue('the date change should wait on the departure row');
        } catch (QueryException $e) {
            $code = claimMysqlError($e);
            expect($code)->toBe(1205);
            $observed = (string) $code;
        }
    });

    onClaimConnection('mysql', function (): void {
        DB::commit();
    });

    expect($observed)->toBe('1205');
    expect($departure->fresh()?->date->toDateString())->toBe('2028-04-02');
    fwrite(STDOUT, "claim-then-date-change concurrency observed: {$observed}\n");
});

test('a date change holds the departure so a claim waits', function (): void {
    ['departure' => $departure, 'cabin' => $cabin] = concurrencyCabin();
    $holder = ClaimHolder::query()->create(['reference' => 'C-WAIT', 'name' => 'Waiter']);
    $observed = 'none';

    onClaimConnection('mysql', function () use ($departure): void {
        DB::beginTransaction();
        app(UpdateDeparture::class)->handle($departure, ['date' => '2028-04-09']);
    });

    onClaimConnection('mysql_lock', function () use ($departure, $cabin, $holder, &$observed): void {
        DB::beginTransaction();

        try {
            app(ClaimService::class)->claim($departure, collect([$cabin]), $holder, ClaimKind::Block);
            expect(false)->toBeTrue('the claim should wait on the departure row');
        } catch (QueryException $e) {
            $code = claimMysqlError($e);
            expect($code)->toBe(1205);
            $observed = (string) $code;
            DB::rollBack();
        }
    });

    onClaimConnection('mysql', function (): void {
        DB::commit();
    });

    expect($observed)->toBe('1205');
    expect(CabinClaim::query()->whereNull('released_at')->where('cabin_id', $cabin->id)->count())->toBe(0);
    fwrite(STDOUT, "date-change-then-claim concurrency observed: {$observed}\n");
});

test('two converts spanning two departures in opposite order never deadlock', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $itinerary = Itinerary::factory()->create(['status' => ItineraryStatus::Published]);
    $departureX = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-02',
    ]);
    $departureY = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-09',
    ]);
    $s1 = $property->cabins()->where('code', 'S1')->firstOrFail();
    $s2 = $property->cabins()->where('code', 'S2')->firstOrFail();

    $fromA = ClaimHolder::query()->create(['reference' => 'FROM-A', 'name' => 'From A']);
    $toA = ClaimHolder::query()->create(['reference' => 'TO-A', 'name' => 'To A']);
    $fromB = ClaimHolder::query()->create(['reference' => 'FROM-B', 'name' => 'From B']);
    $toB = ClaimHolder::query()->create(['reference' => 'TO-B', 'name' => 'To B']);

    DB::transaction(function () use ($departureX, $departureY, $s1, $s2, $fromA, $fromB): void {
        $claims = app(ClaimService::class);
        $claims->claim($departureY, collect([$s1]), $fromA, ClaimKind::Block);
        $claims->claim($departureX, collect([$s1]), $fromA, ClaimKind::Block);
        $claims->claim($departureX, collect([$s2]), $fromB, ClaimKind::Block);
        $claims->claim($departureY, collect([$s2]), $fromB, ClaimKind::Block);
    });

    $observed = 'none';

    onClaimConnection('mysql', function () use ($fromA, $toA): void {
        DB::beginTransaction();
        app(ClaimService::class)->convert($fromA, $toA, ClaimKind::Block);
    });

    onClaimConnection('mysql_lock', function () use ($fromB, $toB, &$observed): void {
        DB::beginTransaction();

        try {
            app(ClaimService::class)->convert($fromB, $toB, ClaimKind::Block);
            expect(false)->toBeTrue('the second convert should wait, not succeed while the first is open');
        } catch (QueryException $e) {
            $code = claimMysqlError($e);
            expect($code)->not->toBe(1213);
            expect($code)->toBe(1205);
            $observed = (string) $code;
            DB::rollBack();
        }
    });

    onClaimConnection('mysql', function (): void {
        DB::commit();
    });

    expect($observed)->toBe('1205');
    expect(CabinClaim::query()->whereNull('released_at')->where('holder_id', $toA->id)->count())->toBe(2);
    expect(CabinClaim::query()->whereNull('released_at')->where('holder_id', $fromB->id)->count())->toBe(2);
    fwrite(STDOUT, "convert-opposite-order concurrency observed: {$observed}\n");
});

test('submit convert versus a competing claim never frees the cabin', function (): void {
    ['departure' => $departure, 'cabin' => $cabin] = concurrencyCabin();
    $session = CheckoutSession::factory()->create([
        'departure_id' => $departure->id,
        'cabins' => [['cabin_code' => $cabin->code, 'adults' => 2, 'children' => 0]],
    ]);
    $bookingHolder = ClaimHolder::query()->create(['reference' => 'REQ-1', 'name' => 'Request']);
    $competitor = ClaimHolder::query()->create(['reference' => 'RACE', 'name' => 'Racer']);
    $observed = 'none';

    DB::transaction(function () use ($departure, $cabin, $session): void {
        app(ClaimService::class)->claim(
            $departure,
            collect([$cabin]),
            $session,
            ClaimKind::Hold,
            HoldType::Web,
            now()->addMinutes(20),
        );
    });

    onClaimConnection('mysql', function () use ($session, $bookingHolder, $cabin): void {
        DB::beginTransaction();
        app(ClaimService::class)->convert(
            $session,
            $bookingHolder,
            ClaimKind::Hold,
            HoldType::Request,
            now()->addDays(2),
            collect([$cabin]),
        );
    });

    onClaimConnection('mysql_lock', function () use ($departure, $cabin, $competitor, &$observed): void {
        DB::beginTransaction();

        try {
            app(ClaimService::class)->claim($departure, collect([$cabin]), $competitor, ClaimKind::Block);
            expect(false)->toBeTrue('the competing claim should wait, not take a free cabin');
        } catch (CabinUnavailableException) {
            expect(false)->toBeTrue('the competing claim should wait on the departure row (1205), not 409');
        } catch (QueryException $e) {
            $code = claimMysqlError($e);
            expect($code)->toBe(1205);
            $observed = (string) $code;
            DB::rollBack();
        }
    });

    onClaimConnection('mysql', function (): void {
        DB::commit();
    });

    $live = CabinClaim::query()->whereNull('released_at')->where('cabin_id', $cabin->id)->get();
    expect($live)->toHaveCount(1);
    expect($live->first()?->holder_id)->toBe($bookingHolder->id);
    expect($live->first()?->hold_type)->toBe(HoldType::Request);
    expect($observed)->toBe('1205');
    fwrite(STDOUT, "submit-convert concurrency observed: {$observed}\n");
});
