<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateStayReservation;
use App\Actions\Bookings\DeleteBooking;
use App\Actions\Bookings\TransitionBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
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
function onBookingMutationConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function bookingMutationMysqlError(QueryException $e): int
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

test('a held transition vs delete waits 1205 never 1213', function (): void {
    $actor = adminUser();
    Auth::login($actor);
    $departure = ReservationFixtures::anamaraDeparture('2026-12-21');
    $created = app(CreateStayReservation::class)->handle(
        ReservationFixtures::createPayload($departure),
        $actor,
    );
    $booking = $created->bookings->firstOrFail();
    $observed = 'none';

    onBookingMutationConnection('mysql', function () use ($booking, $actor): void {
        DB::beginTransaction();
        app(TransitionBooking::class)->handle($booking, ['to' => BookingStatus::Confirmed->value], $actor);
    });

    onBookingMutationConnection('mysql_lock', function () use ($booking, $actor, &$observed): void {
        Auth::login($actor);

        try {
            app(DeleteBooking::class)->handle($booking, ['reason' => 'Race'], $actor);
            expect(false)->toBeTrue('the delete should wait on the held locks (1205)');
        } catch (QueryException $e) {
            $code = bookingMutationMysqlError($e);
            expect($code)->toBe(1205);
            $observed = (string) $code;
        }
    });

    onBookingMutationConnection('mysql', function (): void {
        DB::rollBack();
    });

    expect($observed)->toBe('1205');
    expect(Booking::query()->find($booking->id))->not->toBeNull();
    expect(Booking::query()->findOrFail($booking->id)->status)->toBe(BookingStatus::PendingPayment);
});
