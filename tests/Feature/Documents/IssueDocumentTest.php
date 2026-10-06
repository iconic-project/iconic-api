<?php

declare(strict_types=1);

use App\Actions\Documents\IssueDocument;
use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Document;
use App\Models\Payment;
use App\Support\BusinessTime;
use App\Support\Documents\Snapshots\SnapshotFactory;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\Bookings\ReservationFixtures;

function documentBooking(): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-06-11');

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-0701',
        'total' => 26600,
        'deposit_pct' => 10,
    ]);
}

function documentSnapshot(Booking $booking, DocumentKind $kind = DocumentKind::Invoice, ?Payment $payment = null): array
{
    return SnapshotFactory::build($booking, $kind, $payment);
}

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Storage::disk('documents')->deleteDirectory('/');
});

test('IssueDocument stores a real PDF, sha and snapshot', function (): void {
    $booking = documentBooking();
    $actor = adminUser();
    $snapshot = documentSnapshot($booking);

    $document = app(IssueDocument::class)->handle(
        $booking,
        DocumentKind::Invoice,
        $snapshot,
        actor: $actor,
    );

    expect($document->kind)->toBe(DocumentKind::Invoice);
    expect($document->version)->toBe(1);
    expect($document->number)->toBe('INV-'.BusinessTime::year(now()).'-0001');
    expect($document->snapshot['document']['number'] ?? null)->toBe($document->number);
    expect($document->snapshot['document']['version'] ?? null)->toBe(1);
    expect($document->snapshot['totals']['vessel'] ?? null)->toBe(26600);
    expect($document->reason)->toBeNull();
    expect($document->issued_by)->toBe($actor->id);
    expect($document->file_path)->toBe($booking->id.'/INVOICE-v1.pdf');
    expect($document->file_sha256)->toHaveLength(64);

    $bytes = Storage::disk('documents')->get($document->file_path);
    expect($bytes)->toStartWith('%PDF');
    expect(hash('sha256', (string) $bytes))->toBe($document->file_sha256);

    $history = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'document.issued')
        ->first();

    expect($history)->not->toBeNull();
    expect($history?->after['number'] ?? null)->toBe('INV-'.BusinessTime::year(now()).'-0001');
    expect($history?->context['what'] ?? null)->toBe('Booking Confirmation & Invoice issued — v1');
});

test('version 1 draws a number that version 2 keeps, and a final invoice gets its own', function (): void {
    $booking = documentBooking();
    $issuer = app(IssueDocument::class);

    $v1 = $issuer->handle($booking, DocumentKind::Invoice, documentSnapshot($booking), actor: adminUser());
    $v2 = $issuer->handle(
        $booking,
        DocumentKind::Invoice,
        documentSnapshot($booking),
        reason: 'Extra added',
        actor: adminUser(),
    );
    $final = $issuer->handle($booking, DocumentKind::FinalInvoice, documentSnapshot($booking, DocumentKind::FinalInvoice), actor: adminUser());

    expect($v1->number)->toBe('INV-'.BusinessTime::year(now()).'-0001');
    expect($v2->number)->toBe('INV-'.BusinessTime::year(now()).'-0001');
    expect($v2->version)->toBe(2);
    expect($v2->reason)->toBe('Extra added');
    expect($final->number)->toBe('INV-'.BusinessTime::year(now()).'-0002');
    expect($final->version)->toBe(1);
    expect(Storage::disk('documents')->exists($v1->file_path))->toBeTrue();
    expect(Storage::disk('documents')->exists($v2->file_path))->toBeTrue();
    expect($v1->file_sha256)->not->toBe($v2->file_sha256);

    $history = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'document.issued')
        ->where('after->version', 2)
        ->first();

    expect($history?->context['what'] ?? null)->toBe('Booking Confirmation & Invoice issued — v2: Extra added');
});

test('version 2 without a reason is refused', function (): void {
    $booking = documentBooking();
    $issuer = app(IssueDocument::class);
    $issuer->handle($booking, DocumentKind::Invoice, documentSnapshot($booking), actor: adminUser());

    expect(fn () => $issuer->handle($booking, DocumentKind::Invoice, documentSnapshot($booking), actor: adminUser()))
        ->toThrow(ValidationException::class);
});

test('a rollback after the file is written leaves no row and no file', function (): void {
    $booking = documentBooking();

    Document::creating(function (): void {
        throw new RuntimeException('force rollback');
    });

    expect(fn () => app(IssueDocument::class)->handle(
        $booking,
        DocumentKind::Invoice,
        documentSnapshot($booking),
        actor: adminUser(),
    ))->toThrow(RuntimeException::class, 'force rollback');

    expect(Document::query()->count())->toBe(0);
    expect(Storage::disk('documents')->allFiles())->toBe([]);
});

test('a second receipt for the same payment returns the existing document', function (): void {
    $booking = documentBooking();
    $payment = Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => 2660,
        'reference' => 'ANK-2026-0701-D01',
    ]);
    $issuer = app(IssueDocument::class);

    $first = $issuer->handle(
        $booking,
        DocumentKind::Receipt,
        documentSnapshot($booking, DocumentKind::Receipt, $payment),
        payment: $payment,
        actor: adminUser(),
    );
    $second = $issuer->handle(
        $booking,
        DocumentKind::Receipt,
        documentSnapshot($booking, DocumentKind::Receipt, $payment),
        payment: $payment,
        actor: adminUser(),
    );

    expect($second->id)->toBe($first->id);
    expect(Document::query()->where('kind', DocumentKind::Receipt)->count())->toBe(1);
});

test('a deposit and a balance payment issue two receipts and two files', function (): void {
    $booking = documentBooking();
    $deposit = Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => 2660,
        'reference' => 'ANK-2026-0701-D01',
    ]);
    $balance = Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Balance,
        'amount' => 23940,
        'reference' => 'ANK-2026-0701-B01',
    ]);
    $issuer = app(IssueDocument::class);

    $depositReceipt = $issuer->handle(
        $booking,
        DocumentKind::Receipt,
        documentSnapshot($booking, DocumentKind::Receipt, $deposit),
        payment: $deposit,
        actor: adminUser(),
    );
    $balanceReceipt = $issuer->handle(
        $booking,
        DocumentKind::Receipt,
        documentSnapshot($booking, DocumentKind::Receipt, $balance),
        payment: $balance,
        actor: adminUser(),
    );

    expect($depositReceipt->id)->not->toBe($balanceReceipt->id);
    expect($depositReceipt->version)->toBe(1);
    expect($balanceReceipt->version)->toBe(1);
    expect($depositReceipt->file_path)->toBe($booking->id.'/RECEIPT-'.$deposit->id.'-v1.pdf');
    expect($balanceReceipt->file_path)->toBe($booking->id.'/RECEIPT-'.$balance->id.'-v1.pdf');
    expect(Document::query()->where('kind', DocumentKind::Receipt)->count())->toBe(2);
    expect(Storage::disk('documents')->exists($depositReceipt->file_path))->toBeTrue();
    expect(Storage::disk('documents')->exists($balanceReceipt->file_path))->toBeTrue();
});
