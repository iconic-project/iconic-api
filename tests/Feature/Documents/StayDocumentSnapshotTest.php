<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Actions\Extras\AddBookingExtra;
use App\Enums\BookingStatus;
use App\Enums\ConfigKind;
use App\Enums\DocumentKind;
use App\Enums\DocumentPlanKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Events\StayModified;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Documents\DocumentPlan;
use App\Support\Documents\Snapshots\SnapshotFactory;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00', BusinessTime::zone()));
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    publishStayRates();
    Mail::fake();
});

afterEach(function (): void {
    Storage::disk('documents')->deleteDirectory('/');
});

test('each document kind snapshots a stay that crosses two seasons and a city tax', function (): void {
    $booking = stayDocumentBooking();
    $invoice = SnapshotFactory::build($booking, DocumentKind::Invoice);

    expect($invoice)->not->toHaveKey('cruise');
    expect($invoice['stay']['property'])->toBe('Hotel Demo');
    expect($invoice['stay']['address'])->toBe('1 Demo Street, Demo City, 00000');
    expect($invoice['stay']['phone'])->toBe('+1-555-0100');
    expect($invoice['stay']['room_type'])->toBe('Standard Double');
    expect($invoice['stay']['room'])->toBe(['code' => '101', 'label' => 'Room 101']);
    expect($invoice['stay']['check_in'])->toBe('Monday, March 30, 2026');
    expect($invoice['stay']['check_in_time'])->toBe('from 15:00');
    expect($invoice['stay']['check_out'])->toBe('Thursday, April 2, 2026');
    expect($invoice['stay']['check_out_time'])->toBe('until 11:00');
    expect($invoice['stay']['nights'])->toBe(3);
    expect($invoice['stay']['party']['adults'])->toBe(2);
    expect($invoice['stay']['party']['children'])->toBe(0);
    expect($invoice['stay']['rate_plan'])->toBe('Best available');
    expect($invoice['stay']['meal_plan'])->toBe('RO');
    expect($invoice['stay']['nights_by_season'])->toBe([
        ['season' => 'LOW', 'name' => 'Low', 'nights' => 2, 'amount' => 200],
        ['season' => 'SHOULDER', 'name' => 'Shoulder', 'nights' => 1, 'amount' => 150],
    ]);
    expect($invoice['stay']['taxes']['charged'])->toBe([
        ['concept' => 'City tax · 3 nights', 'qty' => '', 'rate' => null, 'amount' => 30],
    ]);
    expect($invoice['stay']['taxes']['information'])->toBe([
        ['concept' => 'Tourism fee', 'qty' => '', 'rate' => null, 'amount' => 5],
    ]);
    expect($invoice['totals']['vessel'])->toBe(350);
    expect($invoice['totals']['charges_total'])->toBe($booking->chargesTotal());
    expect($invoice['totals']['charges_total'])->toBe(350);
    expect($invoice['schedule']['cancellation'])->toContain('≥120 days: 5% penalty');

    $summary = SnapshotFactory::build($booking, DocumentKind::Summary);
    $final = SnapshotFactory::build($booking, DocumentKind::FinalInvoice);
    expect($summary['stay']['nights_by_season'])->toBe($invoice['stay']['nights_by_season']);
    expect($final['stay']['taxes']['charged'][0]['amount'])->toBe(30);

    $preArrival = SnapshotFactory::build($booking, DocumentKind::PreArrival);
    expect($preArrival)->not->toHaveKey('day_plan');
    expect($preArrival['document']['kind'])->toBe(DocumentKind::PreArrival->value);
    expect($preArrival['document']['title'])->toBe('BEFORE YOU ARRIVE');
    expect($preArrival['stay']['check_in'])->toBe('Monday, March 30, 2026');

    $legacy = SnapshotFactory::build($booking, DocumentKind::Pretrip);
    expect($legacy['document']['kind'])->toBe(DocumentKind::Pretrip->value);
    expect($legacy['stay']['nights'])->toBe(3);

    $wire = SnapshotFactory::build($booking, DocumentKind::WireInstructions);
    expect($wire['document']['kind'])->toBe(DocumentKind::WireInstructions->value);

    $payment = Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => 105,
        'paid_at' => '2026-03-01',
        'reference' => 'STAY-DOC-D01',
    ]);
    $receipt = SnapshotFactory::build($booking->fresh() ?? $booking, DocumentKind::Receipt, $payment);
    expect($receipt['document']['kind'])->toBe(DocumentKind::Receipt->value);

    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 1], adminUser());
    $voucher = SnapshotFactory::build($booking->fresh() ?? $booking, DocumentKind::Voucher);
    expect($voucher['arrival'])->toBe('30 Mar 2026');
});

test('plan dates follow check-in and an issued document stays immutable when the stay changes', function (): void {
    $booking = stayDocumentBooking();
    $issued = app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, system: true);
    $frozen = $issued->snapshot;

    $plan = app(DocumentPlan::class)->for($booking->fresh() ?? $booking, adminUser());
    $preArrival = collect($plan)->first(fn ($row) => $row->kind === DocumentPlanKind::PreArrival);
    $voucher = collect($plan)->first(fn ($row) => $row->kind === DocumentPlanKind::Voucher);

    expect($preArrival?->trigger)->toBe('T−45');
    expect($preArrival?->date)->toBe('2026-02-13');
    expect($voucher?->date)->toBeNull();

    Event::fake([StayModified::class]);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-04-01',
            'check_out' => '2026-04-03',
            'reason' => 'Move the arrival',
        ])
        ->assertOk()
        ->assertJsonPath('stay.nights', 2);

    Event::assertDispatched(StayModified::class);

    $issued->refresh();
    expect(stayDocumentCanonical($issued->snapshot))->toBe(stayDocumentCanonical($frozen));
    expect($issued->version)->toBe(1);

    $fresh = $booking->fresh() ?? $booking;
    $again = app(DocumentPlan::class)->for($fresh, adminUser());
    $moved = collect($again)->first(fn ($row) => $row->kind === DocumentPlanKind::PreArrival);
    expect($moved?->date)->toBe('2026-02-15');

    $reissued = Document::query()
        ->where('booking_id', $booking->id)
        ->where('kind', DocumentKind::Invoice)
        ->orderByDesc('version')
        ->first();

    expect($reissued?->id)->not->toBe($issued->id);
    expect($reissued?->snapshot['stay']['check_in'] ?? null)->toBe('Wednesday, April 1, 2026');
    expect($issued->snapshot['stay']['check_in'])->toBe('Monday, March 30, 2026');
});

test('document templates do not mention embarkation, itinerary, cabin or yacht', function (): void {
    $roots = [
        resource_path('views/documents'),
        resource_path('views/mail/documents'),
    ];
    $forbidden = '/embarkation|itinerary|cabin|yacht/i';

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            expect($contents)->not->toMatch($forbidden);
        }
    }
});

function stayDocumentCanonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $list = array_is_list($value);
    $mapped = array_map(stayDocumentCanonical(...), $value);

    if (! $list) {
        ksort($mapped);
    }

    return $mapped;
}

function stayDocumentBooking(): Booking
{
    $property = Property::query()->firstOrFail();
    $property->update([
        'name' => 'Hotel Demo',
        'address_line_1' => '1 Demo Street',
        'address_line_2' => null,
        'city' => 'Demo City',
        'postcode' => '00000',
        'country' => null,
        'phone' => '+1-555-0100',
    ]);

    $rules = app(CurrentConfig::class)->businessRules()->toArray();
    $rules['taxes'] = [
        [
            'code' => 'CITY',
            'label' => 'City tax',
            'basis' => 'PER_NIGHT',
            'amount' => 10,
            'child_exempt_under_age' => null,
            'charged' => true,
            'shown_in_price_panel' => true,
        ],
        [
            'code' => 'TOUR',
            'label' => 'Tourism fee',
            'basis' => 'PER_STAY',
            'amount' => 5,
            'child_exempt_under_age' => null,
            'charged' => false,
            'shown_in_price_panel' => true,
        ],
    ];
    $version = app(CurrentConfig::class)->version(ConfigKind::BusinessRules);
    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $rules,
        $version->version,
        'STAY-DOC-'.uniqid(),
        adminUser(),
    );
    app(CurrentConfig::class)->forget(ConfigKind::BusinessRules);

    $roomType = RoomType::query()->where('code', 'STD')->firstOrFail();
    $room = Room::query()->create([
        'property_id' => $roomType->property_id,
        'room_type_id' => $roomType->id,
        'code' => '101',
        'label' => 'Room 101',
        'sort' => 1,
        'status' => RoomStatus::Active,
    ]);

    $actor = managerUser();
    $id = test()->actingAs($actor)
        ->postJson('/api/rms/bookings', [
            'check_in' => '2026-03-30',
            'check_out' => '2026-04-02',
            'rooms' => [[
                'room_type' => 'STD',
                'room_id' => $room->id,
                'adults' => 2,
                'child_ages' => [],
            ]],
            'client' => [
                'name' => 'Stay Guest',
                'email' => 'stay-doc-'.uniqid().'@iconic.test',
            ],
            'main_channel' => 'D2C',
            'channel_of_origin' => 'Hotel Booking Engine',
            'expected_total' => 350,
        ])
        ->assertCreated()
        ->json('bookings.0.id');

    $booking = Booking::query()->findOrFail($id);
    $booking->status = BookingStatus::Confirmed;
    $booking->save();

    return $booking->refresh();
}
