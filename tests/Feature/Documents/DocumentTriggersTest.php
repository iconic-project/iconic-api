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
use LogicException;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Storage::disk('documents')->deleteDirectory('/');
});

function issuedProofDocument(): Document
{
    $departure = ReservationFixtures::anamaraDeparture('2028-06-18');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
    ]);

    return app(IssueDocument::class)->handle(
        $booking,
        DocumentKind::Summary,
        SnapshotFactory::build($booking, DocumentKind::Summary),
        actor: adminUser(),
    );
}

test('a raw delete on documents fails', function (): void {
    $document = issuedProofDocument();

    expect(fn () => DB::table('documents')->where('id', $document->id)->delete())
        ->toThrow(QueryException::class);
});

test('a raw update on documents fails', function (): void {
    $document = issuedProofDocument();

    expect(fn () => DB::table('documents')->where('id', $document->id)->update(['reason' => 'changed']))
        ->toThrow(QueryException::class);
});

test('the model refuses update and delete', function (): void {
    $document = issuedProofDocument();

    expect(fn () => $document->update(['reason' => 'changed']))
        ->toThrow(LogicException::class);
    expect(fn () => $document->delete())
        ->toThrow(LogicException::class);
});
