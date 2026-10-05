<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateReservation;
use App\Enums\ClaimKind;
use App\Enums\ItineraryStatus;
use App\Exceptions\CabinUnavailableException;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Models\RoomNightClaim;
use App\Models\User;
use App\Support\Inventory\DepartureLocks;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function onReservationConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function reservationMysqlError(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);

    config(['database.connections.mysql_lock' => config('database.connections.mysql')]);
    DB::purge('mysql_lock');
    DB::connection('mysql_lock')->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

/**
 * @return array{departure: Departure, actor: User}
 */
function reservationConcurrencySetup(): array
{
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $departure = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);

    return [
        'departure' => $departure,
        'actor' => managerUser(),
    ];
}

test('two creates on the same departure and different cabins wait 1205 never 1213', function (): void {
    ['departure' => $departure, 'actor' => $actor] = reservationConcurrencySetup();
    $observed = 'none';

    onReservationConnection('mysql', function () use ($departure): void {
        DB::beginTransaction();
        DepartureLocks::lock($departure->id);
    });

    onReservationConnection('mysql_lock', function () use ($departure, $actor, &$observed): void {
        Auth::login($actor);

        try {
            app(CreateReservation::class)->handle(
                ReservationFixtures::createPayload($departure, [
                    'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
                ]),
                $actor,
            );
            expect(false)->toBeTrue('the second create should wait on the departure row (1205)');
        } catch (CabinUnavailableException) {
            expect(false)->toBeTrue('the second create should wait (1205), not 409');
        } catch (QueryException $e) {
            $code = reservationMysqlError($e);
            expect($code)->toBe(1205);
            $observed = (string) $code;
        }
    });

    onReservationConnection('mysql', function (): void {
        DB::rollBack();
    });

    expect($observed)->toBe('1205');
    expect(Booking::query()->count())->toBe(0);
});

test('two creates on the same cabin: second is 409 after the first commits', function (): void {
    ['departure' => $departure, 'actor' => $actor] = reservationConcurrencySetup();

    Auth::login($actor);
    app(CreateReservation::class)->handle(ReservationFixtures::createPayload($departure), $actor);

    try {
        app(CreateReservation::class)->handle(
            ReservationFixtures::createPayload($departure, [
                'client' => ['name' => 'Second', 'email' => 'second@iconic.test'],
            ]),
            $actor,
        );
        expect(false)->toBeTrue('the second create should be 409');
    } catch (CabinUnavailableException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }

    expect(Booking::query()->count())->toBe(1);
    expect(RoomNightClaim::query()->whereNull('released_at')->where('kind', ClaimKind::Booking)->pluck('room_id')->unique())->toHaveCount(1);
});
