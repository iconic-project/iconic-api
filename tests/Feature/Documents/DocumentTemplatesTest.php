<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Actions\Extras\AddBookingExtra;
use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Services\Documents\DocumentHtml;
use App\Services\Documents\PdfRenderer;
use App\Support\Documents\Snapshots\SnapshotFactory;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function assertDocumentHtmlSnapshot(string $name, string $html): void
{
    $path = dirname(__DIR__).'/__snapshots__/'.$name.'.html';

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    if (! is_file($path)) {
        file_put_contents($path, $html);
    }

    expect($html)->toBe((string) file_get_contents($path));
}

function templateBooking(): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-11-05');
    $departure->itinerary->update([
        'name' => 'Western Realm',
        'long_description' => 'Isabela and Fernandina — the wild western edge.',
        'card_description' => 'The wild western edge.',
        'day_plan' => [['Day 1', 'Embark at SCY.'], ['Day 8', 'Return to SCY.']],
        'nights' => 7,
        'days' => 8,
        'embark' => 'San Cristóbal (SCY)',
        'disembark' => 'San Cristóbal (SCY)',
    ]);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S7')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-1101',
        'total' => 26600,
        'deposit_pct' => 10,
        'billing_address' => '845 Ocean Drive, Miami, FL 33139, USA',
        'billing_email' => 'd.harrison@example.test',
        'billing_phone' => '+1 305 555 0198',
    ]);

    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Daniel',
        'last_name' => 'Harrison',
        'email' => 'd.harrison@example.test',
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'Claire',
        'last_name' => 'Whitfield',
    ]);

    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 2], adminUser());

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => 2660,
        'paid_at' => '2026-07-02',
        'reference' => 'ANK-2026-1101-D01',
    ]);

    return $booking->fresh(['departure.itinerary', 'departure.property', 'guests', 'extras', 'payments', 'contact']);
}

/**
 * @return array<string, DocumentKind>
 */
function templateKinds(): array
{
    return [
        'invoice' => DocumentKind::Invoice,
        'final' => DocumentKind::FinalInvoice,
        'summary' => DocumentKind::Summary,
        'voucher' => DocumentKind::Voucher,
        'pre-arrival' => DocumentKind::PreArrival,
        'wire' => DocumentKind::WireInstructions,
    ];
}

foreach (templateKinds() as $name => $kind) {
    test('the '.$name.' template HTML snapshot and PDF start with %PDF', function () use ($kind): void {
        $booking = templateBooking();
        $snapshot = SnapshotFactory::build($booking, $kind);
        $html = DocumentHtml::render($kind, $snapshot, false);

        assertDocumentHtmlSnapshot($kind->value, $html);
        expect(app(PdfRenderer::class)->render($html))->toStartWith('%PDF');
    });
}

test('the receipt template HTML snapshot and PDF start with %PDF', function (): void {
    $booking = templateBooking();
    $payment = $booking->payments->first();
    $snapshot = SnapshotFactory::build($booking, DocumentKind::Receipt, $payment);
    $html = DocumentHtml::render(DocumentKind::Receipt, $snapshot, false);

    assertDocumentHtmlSnapshot('RECEIPT', $html);
    expect(app(PdfRenderer::class)->render($html))->toStartWith('%PDF');
});

test('an invoice PDF for a booking with extras has a recorded page count', function (): void {
    $booking = templateBooking();
    $document = app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, system: true);
    $bytes = Storage::disk('documents')->get($document->file_path) ?? '';
    preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes, $matches);
    $pages = count($matches[0]);

    expect($pages)->toBe(2);
});
