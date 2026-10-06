<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Actions\Extras\AddBookingExtra;
use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Payment;
use App\Services\Documents\DocumentHtml;
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

afterEach(function (): void {
    Storage::disk('documents')->deleteDirectory('/');
});

function endpointBooking(?int $ownerId = null): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-10-01');

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S5')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-1001',
        'total' => 26600,
        'deposit_pct' => 10,
        'owner_id' => $ownerId ?? adminUser()->id,
    ]);
}

test('staff can preview issue list download and reprint an issued version from its snapshot', function (): void {
    $actor = adminUser();
    $booking = endpointBooking($actor->id);

    $html = $this->actingAs($actor)
        ->get('/api/rms/bookings/'.$booking->id.'/documents/INVOICE/html')
        ->assertOk();
    expect($html->headers->get('content-type'))->toContain('text/html');
    $html->assertSee('BOOKING CONFIRMATION & INVOICE');
    expect($html->getContent())->toContain('data:font/ttf;base64');
    expect($html->getContent())->toContain('font-family: "Oswald"');

    $issued = $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/documents/INVOICE/issue')
        ->assertCreated()
        ->json();

    expect($issued['kind'])->toBe('INVOICE');
    expect($issued['version'])->toBe(1);
    $v1Sha = $issued['file_sha256'];

    $list = $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/documents')
        ->assertOk()
        ->json('data');
    expect($list)->toHaveCount(1);

    $file = $this->actingAs($actor)
        ->get('/api/rms/documents/'.$issued['id'].'/file')
        ->assertOk();
    expect($file->headers->get('content-type'))->toBe('application/pdf');
    expect($file->getContent())->toStartWith('%PDF');

    app(AddBookingExtra::class)->handle($booking, ['code' => 'SPA', 'qty' => 1, 'rate_usd' => 100], $actor);

    $v2 = $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/documents/INVOICE/issue', ['reason' => 'Extra added'])
        ->assertCreated()
        ->json();
    expect($v2['version'])->toBe(3);
    expect($v2['number'])->toBe($issued['number']);
    expect($v2['file_sha256'])->not->toBe($v1Sha);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/documents/INVOICE/issue')
        ->assertUnprocessable();

    $v1Html = $this->actingAs($actor)
        ->get('/api/rms/documents/'.$issued['id'].'/html')
        ->assertOk()
        ->getContent();
    expect($v1Html)->not->toContain('Spa treatment');

    $v2Html = $this->actingAs($actor)
        ->get('/api/rms/documents/'.$v2['id'].'/html')
        ->assertOk()
        ->getContent();
    expect($v2Html)->toContain('Spa treatment');

    $v1File = Storage::disk('documents')->get($booking->id.'/INVOICE-v1.pdf');
    expect(hash('sha256', (string) $v1File))->toBe($v1Sha);
});

test('a voucher without a transfer extra is refused', function (): void {
    $actor = adminUser();
    $booking = endpointBooking($actor->id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/documents/VOUCHER/issue')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kind');
});

test('own-records blocks issue and allows preview when the viewer can see the booking', function (): void {
    $mateo = managerUser(['name' => 'Mateo R.']);
    $lucia = salesExecUser(['name' => 'Lucia B.']);
    $booking = endpointBooking($mateo->id);

    $this->actingAs($lucia)
        ->get('/api/rms/bookings/'.$booking->id.'/documents/INVOICE/html')
        ->assertOk();

    $this->actingAs($lucia)
        ->postJson('/api/rms/bookings/'.$booking->id.'/documents/INVOICE/issue')
        ->assertForbidden();

    $this->actingAs($mateo)
        ->get('/api/rms/bookings/'.$booking->id.'/documents/INVOICE/html')
        ->assertOk();
});

test('html endpoints inline fonts and the PDF renderer does not', function (): void {
    $actor = adminUser();
    $booking = endpointBooking($actor->id);
    $snapshot = SnapshotFactory::build($booking, DocumentKind::Summary);
    $pdfHtml = DocumentHtml::render(DocumentKind::Summary, $snapshot, false);

    expect($pdfHtml)->not->toContain('data:font/ttf;base64');

    $browser = $this->actingAs($actor)
        ->get('/api/rms/bookings/'.$booking->id.'/documents/SUMMARY/html')
        ->assertOk()
        ->getContent();
    expect($browser)->toContain('data:font/ttf;base64');
    expect($browser)->toContain('font-family: "Archivo"');
    expect($browser)->toContain('font-family: "IBM Plex Mono"');
});

test('a receipt can be previewed and issued for a settled positive payment', function (): void {
    $actor = adminUser();
    $booking = endpointBooking($actor->id);
    $payment = Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => 2660,
        'reference' => 'ANK-2026-1001-D01',
    ]);

    $this->actingAs($actor)
        ->get('/api/rms/bookings/'.$booking->id.'/receipts/'.$payment->id.'/html')
        ->assertOk()
        ->assertSee('PAYMENT CONFIRMATION', false);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/documents/RECEIPT/issue', [
            'payment_id' => $payment->id,
        ])
        ->assertCreated()
        ->assertJsonPath('kind', 'RECEIPT');
});

test('PrepareIssueDocument writes history as document.issued', function (): void {
    $actor = adminUser();
    $booking = endpointBooking($actor->id);

    app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, actor: $actor);

    expect(ChangeHistory::query()->where('event', 'document.issued')->count())->toBe(1);
});
