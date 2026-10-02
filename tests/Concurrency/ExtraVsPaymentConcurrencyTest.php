<?php

declare(strict_types=1);

use App\Actions\Extras\AddBookingExtra;
use App\Actions\Payments\RecordPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\Payment;
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
function onExtraPaymentConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function extraPaymentMysqlError(QueryException $e): int
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

test('adding an extra and recording a payment on the same booking wait 1205 never 1213', function (): void {
    $actor = adminUser();
    Auth::login($actor);
    $departure = ReservationFixtures::anamaraDeparture('2028-08-06');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'owner_id' => $actor->id,
        'total' => 26600,
        'deposit_pct' => 10,
    ]);
    $observed = 'none';

    onExtraPaymentConnection('mysql', function () use ($booking, $actor): void {
        DB::beginTransaction();
        app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 1], $actor);
    });

    onExtraPaymentConnection('mysql_lock', function () use ($booking, $actor, &$observed): void {
        Auth::login($actor);

        try {
            app(RecordPayment::class)->handle($booking, [
                'kind' => PaymentKind::Balance,
                'method' => PaymentMethod::CardStripe,
                'amount' => 500,
            ], $actor);
            expect(false)->toBeTrue('the payment should wait on the held locks (1205)');
        } catch (QueryException $e) {
            $code = extraPaymentMysqlError($e);
            expect($code)->toBe(1205);
            expect($code)->not->toBe(1213);
            $observed = (string) $code;
        }
    });

    onExtraPaymentConnection('mysql', function (): void {
        DB::commit();
    });

    onExtraPaymentConnection('mysql_lock', function () use ($booking, $actor): void {
        app(RecordPayment::class)->handle($booking, [
            'kind' => PaymentKind::Balance,
            'method' => PaymentMethod::CardStripe,
            'amount' => 500,
        ], $actor);
    });

    expect($observed)->toBe('1205');
    expect(BookingExtra::query()->where('booking_id', $booking->id)->count())->toBe(1);
    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(1);
});
