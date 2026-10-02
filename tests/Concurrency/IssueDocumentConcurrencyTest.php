<?php

declare(strict_types=1);

use App\Actions\Documents\IssueDocument;
use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Models\Booking;
use App\Models\Document;
use App\Support\Documents\Snapshots\SnapshotFactory;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function onIssueDocumentConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function issueDocumentMysqlError(QueryException $e): int
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

test('two issues on the same booking wait 1205 never 1213 and keep distinct numbers', function (): void {
    $actor = adminUser();
    $departure = ReservationFixtures::anamaraDeparture('2028-07-09');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-0702',
        'total' => 26600,
        'deposit_pct' => 10,
    ]);

    $observed = 'none';

    onIssueDocumentConnection('mysql', function () use ($booking, $actor): void {
        DB::beginTransaction();
        app(IssueDocument::class)->handle(
            $booking,
            DocumentKind::Invoice,
            SnapshotFactory::build($booking, DocumentKind::Invoice),
            actor: $actor,
        );
    });

    onIssueDocumentConnection('mysql_lock', function () use ($booking, $actor, &$observed): void {
        try {
            app(IssueDocument::class)->handle(
                $booking,
                DocumentKind::FinalInvoice,
                SnapshotFactory::build($booking, DocumentKind::FinalInvoice),
                actor: $actor,
            );
            expect(false)->toBeTrue('the second issue should wait on the held locks (1205)');
        } catch (QueryException $e) {
            $code = issueDocumentMysqlError($e);
            expect($code)->toBe(1205);
            expect($code)->not->toBe(1213);
            $observed = (string) $code;
        }
    });

    onIssueDocumentConnection('mysql', function (): void {
        DB::commit();
    });

    onIssueDocumentConnection('mysql_lock', function () use ($booking, $actor): void {
        app(IssueDocument::class)->handle(
            $booking,
            DocumentKind::FinalInvoice,
            SnapshotFactory::build($booking, DocumentKind::FinalInvoice),
            actor: $actor,
        );
    });

    expect($observed)->toBe('1205');
    expect(Document::query()->where('booking_id', $booking->id)->count())->toBe(2);
    expect(Document::query()->where('booking_id', $booking->id)->pluck('number')->unique()->count())->toBe(2);
});
