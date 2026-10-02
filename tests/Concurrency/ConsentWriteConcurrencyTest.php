<?php

declare(strict_types=1);

use App\Actions\Consents\RecordConsent;
use App\Enums\BookingStatus;
use App\Enums\ConsentDocument;
use App\Enums\ConsentSource;
use App\Models\Booking;
use App\Models\Consent;
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
function onConsentWriteConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function consentWriteMysqlError(QueryException $e): int
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

test('two concurrent staff records produce one row and wait 1205 never 1213', function (): void {
    $actor = adminUser();
    Auth::login($actor);
    $departure = ReservationFixtures::anamaraDeparture('2028-05-14');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'owner_id' => $actor->id,
    ]);
    $observed = 'none';

    onConsentWriteConnection('mysql', function () use ($booking, $actor): void {
        DB::beginTransaction();
        app(RecordConsent::class)->handle(
            $booking,
            ConsentDocument::Terms,
            ConsentSource::Staff,
            howObtained: 'first call',
            actor: $actor,
        );
    });

    onConsentWriteConnection('mysql_lock', function () use ($booking, $actor, &$observed): void {
        Auth::login($actor);

        try {
            app(RecordConsent::class)->handle(
                $booking,
                ConsentDocument::Terms,
                ConsentSource::Staff,
                howObtained: 'second call',
                actor: $actor,
            );
            expect(false)->toBeTrue('the second consent write should wait on the booking lock (1205)');
        } catch (QueryException $e) {
            $code = consentWriteMysqlError($e);
            expect($code)->toBe(1205);
            expect($code)->not->toBe(1213);
            $observed = (string) $code;
        }
    });

    onConsentWriteConnection('mysql', function (): void {
        DB::commit();
    });

    onConsentWriteConnection('mysql_lock', function () use ($booking, $actor): void {
        app(RecordConsent::class)->handle(
            $booking,
            ConsentDocument::Terms,
            ConsentSource::Staff,
            howObtained: 'second call',
            actor: $actor,
        );
    });

    expect($observed)->toBe('1205');
    expect(Consent::query()->where('booking_id', $booking->id)->count())->toBe(1);
});
