<?php

declare(strict_types=1);

use App\Actions\Guests\AddGuest;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Guest;
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
function onGuestWriteConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function guestWriteMysqlError(QueryException $e): int
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

test('two guest writes on one booking wait 1205 never 1213', function (): void {
    $actor = adminUser();
    Auth::login($actor);
    $departure = ReservationFixtures::anamaraDeparture('2028-05-14');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::PendingPayment,
        'owner_id' => $actor->id,
    ]);
    $observed = 'none';

    onGuestWriteConnection('mysql', function () use ($booking, $actor): void {
        DB::beginTransaction();
        app(AddGuest::class)->handle($booking, ['first_name' => 'First'], $actor);
    });

    onGuestWriteConnection('mysql_lock', function () use ($booking, $actor, &$observed): void {
        Auth::login($actor);

        try {
            app(AddGuest::class)->handle($booking, ['first_name' => 'Second'], $actor);
            expect(false)->toBeTrue('the second guest write should wait on the held locks (1205)');
        } catch (QueryException $e) {
            $code = guestWriteMysqlError($e);
            expect($code)->toBe(1205);
            expect($code)->not->toBe(1213);
            $observed = (string) $code;
        }
    });

    onGuestWriteConnection('mysql', function (): void {
        DB::commit();
    });

    onGuestWriteConnection('mysql_lock', function () use ($booking, $actor): void {
        app(AddGuest::class)->handle($booking, ['first_name' => 'Second'], $actor);
    });

    expect($observed)->toBe('1205');
    expect(Guest::query()->where('booking_id', $booking->id)->count())->toBe(2);
});
