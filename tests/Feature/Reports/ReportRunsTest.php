<?php

declare(strict_types=1);

use App\Enums\AgencyStatus;
use App\Enums\BookingStatus;
use App\Enums\GuestResponseSource;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Guest;
use App\Models\GuestResponse;
use App\Models\Payment;
use App\Models\ReportRun;
use App\Models\Role;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Reports\ReportDefinitions;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookings\ReservationFixtures;
use ZipArchive;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('each definition runs in every format, repeats, and carries no personal data', function (): void {
    $admin = adminUser();
    $agency = Agency::factory()->create([
        'name' => 'QX Agency Co',
        'contact' => 'QX-PERSON-CONTACT',
        'email' => 'qx-person@iconic.test',
        'status' => AgencyStatus::Approved,
    ]);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'reference' => 'ANK-RPT-0001',
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $admin->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'amount' => 2660,
        'paid_at' => '2027-11-08',
        'gateway_id' => 'pi_test_991',
    ]);
    $guest = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'QX-GUEST-FIRST',
        'last_name' => 'QX-GUEST-LAST',
        'dob' => '1971-02-09',
        'nationality' => 'GB',
        'passport_no' => 'QX-PASSPORT-991',
        'email' => 'qx-guest-991@example.com',
        'medical_note' => 'QX-MEDICAL-991',
        'dietary_note' => 'QX-DIETARY-991',
        'accessibility_note' => 'QX-ACCESS-991',
    ]);
    GuestResponse::query()->create([
        'guest_id' => $guest->id,
        'booking_id' => $booking->id,
        'score' => 9,
        'why' => 'QX-SURVEY-991',
        'source' => GuestResponseSource::GuestLink,
        'responded_at' => BusinessTime::calendarDay('2027-11-08')->addHours(12)->utc(),
    ]);

    $secrets = [
        'QX-PASSPORT-991',
        '1971-02-09',
        'qx-guest-991@example.com',
        'QX-MEDICAL-991',
        'QX-DIETARY-991',
        'QX-ACCESS-991',
        'QX-SURVEY-991',
        'QX-GUEST-FIRST',
        'QX-GUEST-LAST',
        'QX-PERSON-CONTACT',
        'qx-person@iconic.test',
    ];

    $firstCsv = null;

    foreach (ReportDefinitions::all() as $definition) {
        $run = postReport($admin, $definition->key);
        expect([
            'key' => $definition->key,
            'status' => $run['status'],
            'error' => $run['error'],
        ])->toBe([
            'key' => $definition->key,
            'status' => 'READY',
            'error' => null,
        ]);
        expect($run['rows'])->toBeInt();

        match ($definition->key) {
            'payments-received', 'gateway-reconciliation', 'revenue-monthly', 'agency-report' => expect($run['rows'])->toBe(1),
            'occupancy', 'commercial-summary', 'pipeline-summary' => expect($run['rows'])->toBeGreaterThan(0),
            default => expect($run['rows'])->toBeGreaterThanOrEqual(0),
        };

        foreach ($definition->formats as $format) {
            $download = test()->actingAs($admin)
                ->get('/api/rms/reports/runs/'.$run['id'].'/file/'.$format->value);
            $download->assertOk();
            $bytes = $download->streamedContent();
            expect($bytes)->not->toBe('');
            expect(match ($format->value) {
                'pdf' => str_starts_with($bytes, '%PDF'),
                'xlsx' => str_starts_with($bytes, 'PK'),
                'csv' => str_contains($bytes, "\n"),
                default => false,
            })->toBeTrue();

            $plain = reportPlainText($bytes, $format->value);

            foreach ($secrets as $secret) {
                expect(str_contains($plain, $secret))->toBeFalse();
            }

            if ($definition->key === 'payments-received' && $format->value === 'csv') {
                expect($plain)->toContain('ANK-RPT-0001');
                $firstCsv = $bytes;
            }

            if ($definition->key === 'gateway-reconciliation' && $format->value === 'csv') {
                expect($plain)->toContain('pi_test_991');
            }
        }
    }

    $repeat = postReport($admin, 'payments-received');
    $second = test()->actingAs($admin)
        ->get('/api/rms/reports/runs/'.$repeat['id'].'/file/csv')
        ->assertOk()
        ->streamedContent();

    expect($second)->toBe($firstCsv);

    $history = ChangeHistory::query()->where('event', 'report.downloaded')->first();
    expect($history)->not->toBeNull()
        ->and($history?->subject_type)->toBe('report_run');
});

test('report permissions follow the definition, and a purged file is gone', function (): void {
    $crm = User::factory()->create([
        'role_id' => Role::factory()->create(['permissions' => [Permission::PanelCrm]])->id,
    ]);
    $desk = User::factory()->create([
        'role_id' => Role::factory()->create(['permissions' => [Permission::PanelRms]])->id,
    ]);
    $agencies = User::factory()->create([
        'role_id' => Role::factory()->create([
            'permissions' => [Permission::PanelRms, Permission::AgenciesManage],
        ])->id,
    ]);

    test()->getJson('/api/rms/reports')->assertUnauthorized();
    test()->actingAs($crm)->getJson('/api/rms/reports')->assertForbidden();

    $registry = test()->actingAs($desk)->getJson('/api/rms/reports')->assertOk()->json('data');
    expect($registry)->toHaveCount(10);
    $allowed = collect($registry)->mapWithKeys(fn (array $row): array => [$row['key'] => $row['allowed']]);
    expect($allowed['commercial-summary'])->toBeTrue()
        ->and($allowed['occupancy'])->toBeTrue()
        ->and($allowed['pipeline-summary'])->toBeTrue()
        ->and($allowed['payments-received'])->toBeFalse()
        ->and($allowed['agency-report'])->toBeFalse();

    test()->actingAs($desk)->postJson('/api/rms/reports/payments-received/runs', reportWindow())->assertForbidden();
    test()->actingAs($desk)->postJson('/api/rms/reports/agency-report/runs', reportWindow())->assertForbidden();
    test()->actingAs($agencies)->postJson('/api/rms/reports/payments-received/runs', reportWindow())->assertForbidden();
    test()->actingAs($desk)->postJson('/api/rms/reports/missing/runs', reportWindow())->assertNotFound();
    test()->actingAs($desk)->postJson('/api/rms/reports/commercial-summary/runs', [])->assertStatus(422);

    $summary = postReport($desk, 'commercial-summary');
    expect($summary['status'])->toBe('READY');

    $agencyRun = postReport($agencies, 'agency-report');
    expect($agencyRun['status'])->toBe('READY');

    $listed = test()->actingAs($desk)->getJson('/api/rms/reports/runs')->assertOk()->json('data');
    expect(collect($listed)->pluck('definition_key'))->toContain('commercial-summary')
        ->not->toContain('agency-report');

    $run = ReportRun::query()->findOrFail($summary['id']);
    $run->forceFill(['generated_at' => BusinessTime::now()->subDays(100)])->save();

    test()->artisan('iconic:retention')->assertSuccessful();

    $run->refresh();
    expect($run->purged_at)->not->toBeNull()
        ->and($run->pdf_path)->toBeNull()
        ->and(ReportRun::query()->findOrFail($agencyRun['id'])->purged_at)->toBeNull();

    test()->actingAs($desk)
        ->get('/api/rms/reports/runs/'.$run->id.'/file/pdf')
        ->assertNotFound();
});

/**
 * @return array<string, mixed>
 */
function postReport(User $actor, string $key): array
{
    /** @var TestResponse $response */
    $response = test()->actingAs($actor)->postJson('/api/rms/reports/'.$key.'/runs', reportWindow());
    $response->assertCreated();

    /** @var array<string, mixed> $run */
    $run = $response->json('data');

    return $run;
}

/**
 * @return array{from: string, to: string}
 */
function reportWindow(): array
{
    return ['from' => '2027-11-01', 'to' => '2027-11-30'];
}

function reportPlainText(string $bytes, string $format): string
{
    if ($format === 'xlsx') {
        $tmp = tempnam(sys_get_temp_dir(), 'report');

        if ($tmp === false) {
            return $bytes;
        }

        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $text = $bytes;

        if ($zip->open($tmp) === true) {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $contents = $zip->getFromIndex($index);

                if (is_string($contents)) {
                    $text .= $contents;
                }
            }

            $zip->close();
        }

        unlink($tmp);

        return $text;
    }

    if ($format !== 'pdf') {
        return $bytes;
    }

    $text = $bytes;

    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches) === 1 || $matches[1] !== []) {
        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);

            if ($inflated === false) {
                $inflated = @gzinflate($stream);
            }

            if (is_string($inflated)) {
                $text .= $inflated;
            }
        }
    }

    return $text;
}
