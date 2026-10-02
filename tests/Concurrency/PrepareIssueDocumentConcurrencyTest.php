<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Actions\Payments\RecordPayment;
use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Payment;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function onPrepareIssueConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function prepareIssueMysqlError(QueryException $e): int
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

afterEach(function (): void {
    Storage::disk('documents')->deleteDirectory('/');
});

test('a payment recorded while PrepareIssueDocument holds the lock waits 1205 never 1213', function (): void {
    $actor = adminUser();
    Auth::login($actor);
    $departure = ReservationFixtures::anamaraDeparture('2028-10-15');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S8')?->id,
        'status' => BookingStatus::Confirmed,
        'owner_id' => $actor->id,
        'total' => 26600,
        'deposit_pct' => 10,
    ]);
    $observed = 'none';

    onPrepareIssueConnection('mysql', function () use ($booking, $actor): void {
        DB::beginTransaction();
        app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, actor: $actor);
    });

    onPrepareIssueConnection('mysql_lock', function () use ($booking, $actor, &$observed): void {
        Auth::login($actor);

        try {
            app(RecordPayment::class)->handle($booking, [
                'kind' => PaymentKind::Balance,
                'method' => PaymentMethod::CardStripe,
                'amount' => 500,
            ], $actor);
            expect(false)->toBeTrue('the payment should wait on the held locks (1205)');
        } catch (QueryException $e) {
            $code = prepareIssueMysqlError($e);
            expect($code)->toBe(1205);
            expect($code)->not->toBe(1213);
            $observed = (string) $code;
        }
    });

    onPrepareIssueConnection('mysql', function (): void {
        DB::commit();
    });

    onPrepareIssueConnection('mysql_lock', function () use ($booking, $actor): void {
        app(RecordPayment::class)->handle($booking, [
            'kind' => PaymentKind::Balance,
            'method' => PaymentMethod::CardStripe,
            'amount' => 500,
        ], $actor);
    });

    expect($observed)->toBe('1205');
    $document = Document::query()->where('booking_id', $booking->id)->first();
    expect($document)->not->toBeNull();
    expect($document?->snapshot['totals']['paid'] ?? null)->toBe(0);
    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(1);
});
