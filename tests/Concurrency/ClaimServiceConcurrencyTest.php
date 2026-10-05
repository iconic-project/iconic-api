<?php

declare(strict_types=1);

use App\Actions\Departures\UpdateDeparture;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Exceptions\CabinUnavailableException;
use App\Exceptions\RoomUnavailableException;
use App\Models\CheckoutSession;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Services\Inventory\ClaimService;
use App\Support\Stays\StayDates;
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

function concurrencyRoom(): Room
{
    return Property::query()->where('code', 'ANAMARA')->firstOrFail()->cabins()->where('code', 'S1')->firstOrFail();
}

/**
 * @return array{departure: Departure, cabin: Room}
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

test('two claimers of the same free room never deadlock or double-occupy (nights)', function (): void {
    $room = concurrencyRoom();
    $stay = StayDates::forNights('2028-04-02', 1);
    $first = ClaimHolder::query()->create(['reference' => 'C1', 'name' => 'One']);
    $second = ClaimHolder::query()->create(['reference' => 'C2', 'name' => 'Two']);
    $observed = 'none';

    onClaimConnection('mysql', function () use ($stay, $room, $first): void {
        DB::beginTransaction();
        app(ClaimService::class)->claim($stay, collect([$room]), $first, ClaimKind::Block);
    });

    onClaimConnection('mysql_lock', function () use ($stay, $room, $second, &$observed): void {
        DB::beginTransaction();

        try {
            app(ClaimService::class)->claim($stay, collect([$room]), $second, ClaimKind::Block);
            expect(false)->toBeTrue('the second claim should not succeed while the first is open');
        } catch (RoomUnavailableException) {
            expect(false)->toBeTrue('the second claim should wait on the room row (1205), not 409');
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

    expect(RoomNightClaim::query()->whereNull('released_at')->where('room_id', $room->id)->count())->toBe(1);
    expect($observed)->toBe('1205');
});

test('two overlapping stays on the last room: one wins and one is 409 (nights)', function (): void {
    $room = concurrencyRoom();
    $stay = StayDates::forNights('2028-05-01', 3);
    $first = ClaimHolder::query()->create(['reference' => 'OV-1', 'name' => 'One']);
    $second = ClaimHolder::query()->create(['reference' => 'OV-2', 'name' => 'Two']);

    DB::transaction(fn () => app(ClaimService::class)->claim($stay, collect([$room]), $first, ClaimKind::Block));

    expect(fn () => DB::transaction(fn () => app(ClaimService::class)->claim(
        StayDates::forNights('2028-05-02', 2),
        collect([$room]),
        $second,
        ClaimKind::Block,
    )))->toThrow(RoomUnavailableException::class);

    expect(RoomNightClaim::query()->whereNull('released_at')->where('room_id', $room->id)->count())->toBe(3);
    expect(RoomNightClaim::query()->where('holder_id', $second->id)->count())->toBe(0);
});

test('adjacent stays on the same room both succeed (nights)', function (): void {
    $room = concurrencyRoom();
    $first = ClaimHolder::query()->create(['reference' => 'AD-1', 'name' => 'One']);
    $second = ClaimHolder::query()->create(['reference' => 'AD-2', 'name' => 'Two']);

    DB::transaction(fn () => app(ClaimService::class)->claim(
        StayDates::forNights('2028-05-10', 2),
        collect([$room]),
        $first,
        ClaimKind::Block,
    ));
    DB::transaction(fn () => app(ClaimService::class)->claim(
        StayDates::forNights('2028-05-12', 2),
        collect([$room]),
        $second,
        ClaimKind::Block,
    ));

    expect(RoomNightClaim::query()->whereNull('released_at')->where('room_id', $room->id)->count())->toBe(4);
    expect(RoomNightClaim::query()->where('holder_id', $first->id)->whereNull('released_at')->count())->toBe(2);
    expect(RoomNightClaim::query()->where('holder_id', $second->id)->whereNull('released_at')->count())->toBe(2);
});

test('two claimers of the same expired hold never deadlock or double-occupy (nights)', function (): void {
    $room = concurrencyRoom();
    $stay = StayDates::forNights('2028-04-02', 1);
    $expired = ClaimHolder::query()->create(['reference' => 'OLD', 'name' => 'Old']);
    $first = ClaimHolder::query()->create(['reference' => 'N1', 'name' => 'New one']);
    $second = ClaimHolder::query()->create(['reference' => 'N2', 'name' => 'New two']);
    $observed = 'none';

    DB::transaction(function () use ($stay, $room, $expired): void {
        app(ClaimService::class)->claim(
            $stay,
            collect([$room]),
            $expired,
            ClaimKind::Hold,
            HoldType::Web,
            now()->addMinutes(20),
        );
    });

    RoomNightClaim::query()->where('holder_id', $expired->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    onClaimConnection('mysql', function () use ($stay, $room, $first): void {
        DB::beginTransaction();
        app(ClaimService::class)->claim($stay, collect([$room]), $first, ClaimKind::Block);
    });

    onClaimConnection('mysql_lock', function () use ($stay, $room, $second, &$observed): void {
        DB::beginTransaction();

        try {
            app(ClaimService::class)->claim($stay, collect([$room]), $second, ClaimKind::Block);
            expect(false)->toBeTrue('the second claim should not succeed while the first is open');
        } catch (RoomUnavailableException) {
            expect(false)->toBeTrue('the second claim should wait on the room row (1205), not 409');
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

    expect(RoomNightClaim::query()->whereNull('released_at')->where('room_id', $room->id)->count())->toBe(1);
    expect($observed)->toBe('1205');
});

test('a yacht claim holds the departure so a date change waits (nights)', function (): void {
    ['departure' => $departure, 'cabin' => $cabin] = concurrencyCabin();
    $holder = ClaimHolder::query()->create(['reference' => 'C-LOCK', 'name' => 'Claimer']);
    $observed = 'none';

    onClaimConnection('mysql', function () use ($departure, $cabin, $holder): void {
        DB::beginTransaction();
        app(ClaimService::class)->claim($departure->stayDates(), collect([$cabin]), $holder, ClaimKind::Block);
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
});

test('a date change holds the departure so a yacht claim waits (nights)', function (): void {
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
            app(ClaimService::class)->claim($departure->stayDates(), collect([$cabin]), $holder, ClaimKind::Block);
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
    expect(RoomNightClaim::query()->whereNull('released_at')->where('room_id', $cabin->id)->count())->toBe(0);
});

test('two converts of the same rooms in opposite stays never deadlock (nights)', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $s1 = $property->cabins()->where('code', 'S1')->firstOrFail();
    $s2 = $property->cabins()->where('code', 'S2')->firstOrFail();
    $fromA = ClaimHolder::query()->create(['reference' => 'FROM-A', 'name' => 'From A']);
    $toA = ClaimHolder::query()->create(['reference' => 'TO-A', 'name' => 'To A']);
    $fromB = ClaimHolder::query()->create(['reference' => 'FROM-B', 'name' => 'From B']);
    $toB = ClaimHolder::query()->create(['reference' => 'TO-B', 'name' => 'To B']);

    DB::transaction(function () use ($s1, $s2, $fromA, $fromB): void {
        $claims = app(ClaimService::class);
        $claims->claim(StayDates::forNights('2028-04-02', 1), collect([$s1, $s2]), $fromA, ClaimKind::Block);
        $claims->claim(StayDates::forNights('2028-04-09', 1), collect([$s2, $s1]), $fromB, ClaimKind::Block);
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
    expect(RoomNightClaim::query()->whereNull('released_at')->where('holder_id', $toA->id)->count())->toBe(2);
    expect(RoomNightClaim::query()->whereNull('released_at')->where('holder_id', $fromB->id)->count())->toBe(2);
});

test('submit convert versus a competing claim never frees the room (nights)', function (): void {
    $room = concurrencyRoom();
    $stay = StayDates::forNights('2028-04-02', 1);
    $property = $room->property;
    $departure = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);
    $session = CheckoutSession::factory()->create([
        'departure_id' => $departure->id,
        'cabins' => [['cabin_code' => $room->code, 'adults' => 2, 'children' => 0]],
    ]);
    $bookingHolder = ClaimHolder::query()->create(['reference' => 'REQ-1', 'name' => 'Request']);
    $competitor = ClaimHolder::query()->create(['reference' => 'RACE', 'name' => 'Racer']);
    $observed = 'none';

    DB::transaction(function () use ($stay, $room, $session): void {
        app(ClaimService::class)->claim(
            $stay,
            collect([$room]),
            $session,
            ClaimKind::Hold,
            HoldType::Web,
            now()->addMinutes(20),
        );
    });

    onClaimConnection('mysql', function () use ($session, $bookingHolder, $room): void {
        DB::beginTransaction();
        app(ClaimService::class)->convert(
            $session,
            $bookingHolder,
            ClaimKind::Hold,
            HoldType::Request,
            now()->addDays(2),
            collect([$room]),
        );
    });

    onClaimConnection('mysql_lock', function () use ($stay, $room, $competitor, &$observed): void {
        DB::beginTransaction();

        try {
            app(ClaimService::class)->claim($stay, collect([$room]), $competitor, ClaimKind::Block);
            expect(false)->toBeTrue('the competing claim should wait, not take a free room');
        } catch (RoomUnavailableException|CabinUnavailableException) {
            expect(false)->toBeTrue('the competing claim should wait on the room row (1205), not 409');
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

    $live = RoomNightClaim::query()->whereNull('released_at')->where('room_id', $room->id)->get();
    expect($live)->toHaveCount(1);
    expect($live->first()?->holder_id)->toBe($bookingHolder->id);
    expect($live->first()?->hold_type)->toBe(HoldType::Request);
    expect($observed)->toBe('1205');
});
