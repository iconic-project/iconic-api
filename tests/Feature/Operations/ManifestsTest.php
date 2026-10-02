<?php

declare(strict_types=1);

use App\Actions\Manifests\IssueManifest;
use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\ManifestKind;
use App\Enums\ManifestReason;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\Cabin;
use App\Models\ChangeHistory;
use App\Models\Delivery;
use App\Models\Departure;
use App\Models\Guest;
use App\Models\Manifest;
use App\Support\Alerts\AlertKeys;
use App\Support\Manifests\ManifestDue;
use App\Support\Manifests\ManifestFiles;
use App\Support\Manifests\ManifestPassenger;
use App\Support\Manifests\ManifestRoster;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Storage;
use LogicException;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the roster counts sold bookings and orders by cabin then position', function (): void {
    $departure = manifestDeparture();
    $early = manifestCabin($departure, 1, 'S1', 'Suite 01');
    $late = manifestCabin($departure, 2, 'S2', 'Suite 02');

    $second = manifestBooking($departure, BookingStatus::Confirmed, $early, 'ANK-2026-7002');
    $first = manifestBooking($departure, BookingStatus::FullyPaid, $early, 'ANK-2026-7001');
    $third = manifestBooking($departure, BookingStatus::OnBoard, $late, 'ANK-2026-7003');
    $charter = manifestBooking($departure, BookingStatus::OnHoldAgency, null, 'ANK-2026-7004', BookingType::Charter);
    $held = manifestBooking($departure, BookingStatus::Completed, manifestCabin($departure, 3, 'S3', 'Suite 03'), 'ANK-2026-7005');

    manifestBooking($departure, BookingStatus::Cancelled, manifestCabin($departure, 4, 'S4', 'Suite 04'), 'ANK-2026-7091');
    manifestBooking($departure, BookingStatus::Released, manifestCabin($departure, 5, 'S5', 'Suite 05'), 'ANK-2026-7092');
    manifestBooking($departure, BookingStatus::Requested, manifestCabin($departure, 6, 'S6', 'Suite 06'), 'ANK-2026-7093');

    manifestGuest($first, ['position' => 1, 'last_name' => 'Alpha', 'first_name' => 'Ann']);
    manifestGuest($second, ['position' => 2, 'last_name' => 'Beta', 'first_name' => 'Bea', 'is_lead' => false]);
    manifestGuest($third, ['position' => 1, 'last_name' => 'Gamma', 'first_name' => 'Gil']);
    manifestGuest($charter, ['position' => 1, 'last_name' => 'Delta', 'first_name' => 'Dee']);
    manifestGuest($held, ['position' => 1, 'last_name' => 'Epsilon', 'first_name' => 'Eve']);

    foreach ([BookingStatus::Cancelled, BookingStatus::Released, BookingStatus::Requested] as $status) {
        $booking = Booking::query()->where('departure_id', $departure->id)->where('status', $status)->firstOrFail();
        manifestGuest($booking, ['last_name' => 'Out', 'first_name' => $status->value]);
    }

    $names = ManifestRoster::passengers($departure)
        ->map(fn (ManifestPassenger $passenger): string => $passenger->guest->last_name)
        ->all();

    expect($names)->toBe(['Alpha', 'Beta', 'Gamma', 'Epsilon', 'Delta'])
        ->and(ManifestRoster::passengers($departure)->last()?->cabinLabel())->toBe('Full property');
});

test('due dates follow fit or charter and the list status follows live completeness', function (): void {
    galapagos('2026-06-19');

    $fit = manifestDeparture('2026-07-05');
    $fitBooking = manifestBooking($fit, BookingStatus::Confirmed, manifestCabin($fit, 1, 'F1', 'Suite 01'), 'ANK-2026-7101');
    manifestGuest($fitBooking, ['passport_no' => null, 'insurance_declared' => false]);

    $charter = manifestDeparture('2026-07-05');
    $charterBooking = manifestBooking($charter, BookingStatus::Confirmed, null, 'ANK-2026-7102', BookingType::Charter);
    manifestGuest($charterBooking, ['passport_no' => null, 'insurance_declared' => false]);

    $ready = manifestDeparture('2026-07-12');
    $readyBooking = manifestBooking($ready, BookingStatus::Confirmed, manifestCabin($ready, 1, 'R1', 'Suite 01'), 'ANK-2026-7103');
    manifestGuest($readyBooking);

    $list = fn (): array => $this->actingAs(salesExecUser())
        ->getJson('/api/rms/manifests?from=2026-07-01&to=2026-07-31')
        ->assertOk()
        ->json('data');

    $rows = collect($list())->keyBy('reference');

    expect($rows[$fit->reference]['dpng_due'])->toBe('2026-06-20')
        ->and($rows[$fit->reference]['dpng_offset_days'])->toBe(15)
        ->and($rows[$fit->reference]['captain_due'])->toBe('2026-06-28')
        ->and($rows[$fit->reference]['captain_offset_days'])->toBe(7)
        ->and($rows[$fit->reference]['charter'])->toBeFalse()
        ->and($rows[$fit->reference]['status'])->toBe('1 PASSENGER PENDING')
        ->and($rows[$charter->reference]['dpng_due'])->toBe('2026-06-05')
        ->and($rows[$charter->reference]['dpng_offset_days'])->toBe(30)
        ->and($rows[$charter->reference]['charter'])->toBeTrue()
        ->and($rows[$charter->reference]['status'])->toBe('OVERDUE DATA')
        ->and($rows[$ready->reference]['status'])->toBe('READY');

    galapagos('2026-06-20');
    expect(collect($list())->keyBy('reference')[$fit->reference]['status'])->toBe('OVERDUE DATA');

    galapagos('2026-07-06');
    expect(collect($list())->keyBy('reference')[$fit->reference]['status'])->toBe('OVERDUE DATA');

    assertNoSensitiveFields(
        $this->actingAs(salesExecUser())->getJson('/api/rms/manifests?from=2026-07-01&to=2026-07-31'),
    );
});

test('an omitted manifest bound is unbounded and a reversed pair is rejected', function (): void {
    $early = manifestDeparture('2026-07-05');
    $earlyBooking = manifestBooking($early, BookingStatus::Confirmed, manifestCabin($early, 1, 'E1', 'Suite 01'), 'ANK-2026-7111');
    manifestGuest($earlyBooking);

    $late = manifestDeparture('2026-08-16');
    $lateBooking = manifestBooking($late, BookingStatus::Confirmed, manifestCabin($late, 1, 'L1', 'Suite 01'), 'ANK-2026-7112');
    manifestGuest($lateBooking);

    $refs = function (string $query): array {
        return collect(
            $this->actingAs(salesExecUser())
                ->getJson('/api/rms/manifests'.$query)
                ->assertOk()
                ->json('data'),
        )->pluck('reference')->all();
    };

    expect($refs('?from=2026-07-01&to=2026-07-31'))->toBe([$early->reference])
        ->and($refs('?from=2026-08-01'))->toBe([$late->reference])
        ->and($refs('?to=2026-07-31'))->toBe([$early->reference])
        ->and($refs(''))->toBe([$early->reference, $late->reference]);

    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/manifests?from=2026-08-01&to=2026-07-01')
        ->assertUnprocessable();
});

test('the daily job issues first once until the departure date and then only resolves', function (): void {
    $departure = manifestDeparture('2026-07-05');
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'J1', 'Suite 01'), 'ANK-2026-7201');
    manifestGuest($booking, ['passport_no' => null, 'email' => 'ada@example.com']);

    galapagos('2026-06-20');
    Artisan::call('iconic:manifests-due');
    Artisan::call('iconic:manifests-due');

    expect(manifestCount($departure, ManifestKind::Dpng))->toBe(1)
        ->and(manifestCount($departure, ManifestKind::Captain))->toBe(0)
        ->and(Manifest::query()->where('departure_id', $departure->id)->value('reason'))->toBe(ManifestReason::First);

    $alert = Alert::query()->where('base_key', AlertKeys::manifestData($departure->id))->first();
    expect($alert)->not->toBeNull()
        ->and($alert?->kind)->toBe(AlertKind::ManifestDataOverdue)
        ->and($alert?->resolved_at)->toBeNull()
        ->and(Delivery::query()->where('kind', DeliveryKind::DataChaser)->count())->toBe(1)
        ->and(Delivery::query()->where('idempotency_key', 'chase:'.$booking->id.':'.$departure->id)->value('status'))
        ->toBe(DeliveryStatus::Sent);

    $history = ChangeHistory::query()->where('event', 'manifest.generated')->first();
    expect($history?->actor_label)->toBe('System · manifests')
        ->and($history?->after['passport_no'] ?? null)->toBeNull();

    galapagos('2026-07-05');
    Artisan::call('iconic:manifests-due');

    expect($alert?->fresh()?->resolution)->toBe('Departure sailed')
        ->and(manifestCount($departure, ManifestKind::Captain))->toBe(1)
        ->and(Delivery::query()->where('kind', DeliveryKind::DataChaser)->count())->toBe(1);

    $versions = Manifest::query()->where('departure_id', $departure->id)->count();
    galapagos('2026-07-06');
    Artisan::call('iconic:manifests-due');

    expect(Manifest::query()->where('departure_id', $departure->id)->count())->toBe($versions)
        ->and(Delivery::query()->where('kind', DeliveryKind::DataChaser)->count())->toBe(1)
        ->and(Alert::query()->where('kind', AlertKind::ManifestDataOverdue)->whereNull('resolved_at')->count())->toBe(0);
});

test('a missed day before sailing still issues first once', function (): void {
    $departure = manifestDeparture('2026-07-05');
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'M1', 'Suite 01'), 'ANK-2026-7202');
    manifestGuest($booking);

    galapagos('2026-06-25');
    Artisan::call('iconic:manifests-due');
    Artisan::call('iconic:manifests-due');

    expect(manifestCount($departure, ManifestKind::Dpng))->toBe(1)
        ->and(Manifest::query()->where('departure_id', $departure->id)->where('kind', ManifestKind::Dpng)->value('reason'))
        ->toBe(ManifestReason::First);
});

test('a sailed departure gets no first, no alert and no chaser', function (): void {
    $departure = manifestDeparture('2026-06-01');
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'S1', 'Suite 01'), 'ANK-2026-7203');
    manifestGuest($booking, ['passport_no' => null, 'email' => 'late@example.com']);

    galapagos('2026-07-06');
    Artisan::call('iconic:manifests-due');

    expect(Manifest::query()->where('departure_id', $departure->id)->count())->toBe(0)
        ->and(Alert::query()->where('base_key', AlertKeys::manifestData($departure->id))->count())->toBe(0)
        ->and(Delivery::query()->where('kind', DeliveryKind::DataChaser)->count())->toBe(0);
});

test('the job on the departure date issues a missed first without an alert or a chaser', function (): void {
    $departure = manifestDeparture('2026-07-05');
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'D1', 'Suite 01'), 'ANK-2026-7204');
    manifestGuest($booking, ['passport_no' => null, 'email' => 'sail@example.com']);

    galapagos('2026-07-05');
    Artisan::call('iconic:manifests-due');

    expect(manifestCount($departure, ManifestKind::Dpng))->toBe(1)
        ->and(Alert::query()->where('kind', AlertKind::ManifestDataOverdue)->count())->toBe(0)
        ->and(Delivery::query()->where('kind', DeliveryKind::DataChaser)->count())->toBe(0);
});

test('generating and downloading need guests view sensitive and post still works after sailing', function (): void {
    $departure = manifestDeparture('2026-06-01');
    $booking = manifestBooking($departure, BookingStatus::FullyPaid, manifestCabin($departure, 1, 'P1', 'Suite 01'), 'ANK-2026-7301');
    manifestGuest($booking);

    galapagos('2026-07-06');

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/DPNG')
        ->assertForbidden();

    $created = $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/DPNG')
        ->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('data.reason', ManifestReason::Requested->value);

    $manifestId = $created->json('data.id');

    $this->actingAs(salesExecUser())
        ->get('/api/rms/departures/'.$departure->id.'/manifests/'.$manifestId.'/file/pdf')
        ->assertForbidden();

    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/departures/'.$departure->id.'/manifests')
        ->assertOk()
        ->assertJsonPath('data.0.reason', ManifestReason::Requested->value);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/DPNG')
        ->assertOk()
        ->assertJsonPath('created', false)
        ->assertJsonPath('message', 'This manifest is unchanged.');

    expect(manifestCount($departure, ManifestKind::Dpng))->toBe(1);
});

test('a changed passport issues passenger change and a dietary note does not change the dpng hash', function (): void {
    $departure = manifestDeparture();
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'C1', 'Suite 01'), 'ANK-2026-7302');
    $guest = manifestGuest($booking, ['dietary_note' => 'none']);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/DPNG')
        ->assertCreated();
    $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/CAPTAIN')
        ->assertCreated();

    $guest->dietary_note = 'no shellfish';
    $guest->save();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/DPNG')
        ->assertOk()
        ->assertJsonPath('created', false);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/CAPTAIN')
        ->assertCreated()
        ->assertJsonPath('data.reason', ManifestReason::PassengerChange->value);

    $guest->passport_no = 'ZZ999999';
    $guest->save();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/DPNG')
        ->assertCreated()
        ->assertJsonPath('data.reason', ManifestReason::PassengerChange->value)
        ->assertJsonPath('data.version', 2);
});

test('csv and xlsx cells match the pdf table including missing and a dash', function (): void {
    $departure = manifestDeparture();
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'X1', 'Suite 01'), 'ANK-2026-7401');
    manifestGuest($booking, [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'passport_no' => null,
        'dob' => null,
        'insurance_declared' => false,
    ]);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/DPNG')
        ->assertCreated();

    $manifest = Manifest::query()->where('departure_id', $departure->id)->where('kind', ManifestKind::Dpng)->firstOrFail();
    $passengers = ManifestRoster::passengers($departure);
    $html = app(ManifestFiles::class)->html($departure, ManifestKind::Dpng, $passengers, ManifestDue::forDeparture($departure, $passengers));

    expect($html)->toContain('Age')
        ->and($html)->toContain('MISSING')
        ->and($html)->toContain('—')
        ->and($html)->toContain('class="miss"')
        ->and($html)->not->toContain('Age at departure');

    $pdf = Storage::disk('manifests')->get($manifest->pdf_path);
    expect(is_string($pdf) && str_starts_with($pdf, '%PDF'))->toBeTrue();

    $csv = csvRows((string) Storage::disk('manifests')->get($manifest->csv_path));
    $xlsx = xlsxRows(Storage::disk('manifests')->path((string) $manifest->xlsx_path));
    $expected = [ManifestPassenger::dpngHeaders(), $passengers->firstOrFail()->dpngCells()];

    expect($csv)->toBe($expected)
        ->and($xlsx)->toBe($expected)
        ->and($expected[1])->toContain('MISSING')
        ->and($expected[1])->toContain('—');

    $captain = $this->actingAs(managerUser())
        ->postJson('/api/rms/departures/'.$departure->id.'/manifests/CAPTAIN')
        ->assertCreated()
        ->json('data.id');

    $this->actingAs(managerUser())
        ->get('/api/rms/departures/'.$departure->id.'/manifests/'.$captain.'/file/csv')
        ->assertNotFound();

    $download = $this->actingAs(managerUser())
        ->get('/api/rms/departures/'.$departure->id.'/manifests/'.$manifest->id.'/file/csv');
    $download->assertOk();

    $downloaded = ChangeHistory::query()->where('event', 'manifest.downloaded')->first();
    expect($downloaded?->actor_id)->not->toBeNull()
        ->and($downloaded?->after['format'] ?? null)->toBe('csv')
        ->and($downloaded?->after['version'] ?? null)->toBe(1);
});

test('a chaser is sent once per booking and not on or after the departure date', function (): void {
    $departure = manifestDeparture('2026-07-05');
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'H1', 'Suite 01'), 'ANK-2026-7501');
    manifestGuest($booking, ['passport_no' => null, 'email' => 'chase@example.com']);
    $complete = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 2, 'H2', 'Suite 02'), 'ANK-2026-7502');
    manifestGuest($complete, ['email' => 'done@example.com']);

    galapagos('2026-06-10');
    Artisan::call('iconic:manifests-due');
    Artisan::call('iconic:manifests-due');

    expect(Delivery::query()->where('kind', DeliveryKind::DataChaser)->count())->toBe(1)
        ->and(Delivery::query()->where('idempotency_key', 'chase:'.$booking->id.':'.$departure->id)->count())->toBe(1)
        ->and(Delivery::query()->where('booking_id', $complete->id)->where('kind', DeliveryKind::DataChaser)->count())->toBe(0);

    galapagos('2026-07-05');
    Artisan::call('iconic:manifests-due');
    galapagos('2026-07-06');
    Artisan::call('iconic:manifests-due');

    expect(Delivery::query()->where('kind', DeliveryKind::DataChaser)->count())->toBe(1);
});

test('a booking with no address records one blocked chaser and is not sent later', function (): void {
    $departure = manifestDeparture('2026-07-05');
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'B1', 'Suite 01'), 'ANK-2026-7503');
    $guest = manifestGuest($booking, ['passport_no' => null, 'email' => null]);
    $booking->contact->forceFill(['email' => null])->save();
    $booking->forceFill(['billing_email' => null])->save();
    $guest->forceFill(['email' => null])->save();

    galapagos('2026-06-10');
    Artisan::call('iconic:manifests-due');

    $guest->email = 'later@example.com';
    $guest->save();
    Artisan::call('iconic:manifests-due');

    $delivery = Delivery::query()->where('idempotency_key', 'chase:'.$booking->id.':'.$departure->id)->get();
    expect($delivery)->toHaveCount(1)
        ->and($delivery->first()?->status)->toBe(DeliveryStatus::Blocked);
});

test('captain files purge at the medical date and dpng files at the passport date', function (): void {
    $departure = manifestDeparture('2026-06-01');
    $booking = manifestBooking($departure, BookingStatus::Confirmed, manifestCabin($departure, 1, 'T1', 'Suite 01'), 'ANK-2026-7601');
    $guest = manifestGuest($booking, ['medical_note' => 'seasick', 'dietary_note' => 'none']);

    app(IssueManifest::class)->first($departure, ManifestKind::Dpng);
    app(IssueManifest::class)->first($departure, ManifestKind::Captain);

    $booking->forceFill(['status' => BookingStatus::Cancelled])->save();
    expect(ManifestRoster::passengers($departure))->toHaveCount(0);

    $captain = Manifest::query()->where('kind', ManifestKind::Captain)->firstOrFail();
    expect(fn () => DB::table('manifests')->where('id', $captain->id)->update(['passengers' => 9]))
        ->toThrow(QueryException::class);
    expect(fn () => $captain->forceFill(['passengers' => 9])->save())->toThrow(LogicException::class);

    galapagos('2026-09-07');
    Artisan::call('iconic:retention', ['--dry-run' => true]);
    expect(Artisan::output())->toContain('Would purge 1 CAPTAIN file(s) and 0 DPNG file(s).');
    expect($captain->fresh()?->pdf_path)->not->toBeNull();

    Artisan::call('iconic:retention');
    $captain->refresh();
    $dpng = Manifest::query()->where('kind', ManifestKind::Dpng)->firstOrFail();

    expect($captain->purged_at)->not->toBeNull()
        ->and($captain->pdf_path)->toBeNull()
        ->and($dpng->pdf_path)->not->toBeNull()
        ->and($dpng->csv_path)->not->toBeNull()
        ->and($dpng->xlsx_path)->not->toBeNull()
        ->and($guest->fresh()?->medical_note)->toBeNull()
        ->and($guest->fresh()?->passport_no)->not->toBeNull()
        ->and(Manifest::query()->count())->toBe(2);

    galapagos('2028-06-09');
    Artisan::call('iconic:retention', ['--dry-run' => true]);
    expect(Artisan::output())->toContain('Would purge 0 CAPTAIN file(s) and 3 DPNG file(s).');

    Artisan::call('iconic:retention');
    $dpng->refresh();

    expect($dpng->purged_at)->not->toBeNull()
        ->and($dpng->pdf_path)->toBeNull()
        ->and($dpng->csv_path)->toBeNull()
        ->and($dpng->xlsx_path)->toBeNull()
        ->and(Manifest::query()->count())->toBe(2);

    $this->actingAs(managerUser())
        ->get('/api/rms/departures/'.$departure->id.'/manifests/'.$dpng->id.'/file/pdf')
        ->assertNotFound();
});

test('the crm has no manifest route', function (): void {
    $hit = collect(Router::getRoutes())->contains(
        fn (Route $route): bool => str_contains(strtolower($route->uri()), 'crm')
            && str_contains(strtolower($route->uri()), 'manifest'),
    );

    expect($hit)->toBeFalse();
});

function manifestDeparture(string $date = '2026-07-05'): Departure
{
    return Departure::factory()->create(['date' => $date]);
}

function manifestCabin(Departure $departure, int $sort, string $code, string $label): Cabin
{
    return Cabin::factory()->create([
        'property_id' => $departure->property_id,
        'sort' => $sort,
        'code' => $code,
        'label' => $label,
    ]);
}

function manifestBooking(
    Departure $departure,
    BookingStatus $status,
    ?Cabin $cabin,
    string $reference,
    BookingType $type = BookingType::Cabin,
): Booking {
    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $type === BookingType::Charter ? null : $cabin?->id,
        'type' => $type,
        'status' => $status,
        'reference' => $reference,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function manifestGuest(Booking $booking, array $overrides = []): Guest
{
    return Guest::factory()->create(array_merge([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'dob' => '1980-01-02',
        'nationality' => 'GB',
        'passport_no' => 'P1234567',
        'passport_expiry' => '2030-01-01',
        'insurance_declared' => true,
        'email' => 'ada@example.com',
    ], $overrides));
}

function galapagos(string $date): void
{
    Carbon::setTestNow(CarbonImmutable::parse($date.' 18:00:00', 'UTC'));
}

function manifestCount(Departure $departure, ManifestKind $kind): int
{
    return Manifest::query()->where('departure_id', $departure->id)->where('kind', $kind)->count();
}

/**
 * @return list<list<string|null>>
 */
function csvRows(string $csv): array
{
    $handle = fopen('php://temp', 'r+');
    expect($handle)->not->toBeFalse();
    fwrite($handle, $csv);
    rewind($handle);
    $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

/**
 * @return list<list<mixed>>
 */
function xlsxRows(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();

    return $rows;
}
