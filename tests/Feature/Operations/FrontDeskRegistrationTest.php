<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ChangeHistory;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the front desk lists arrivals, in house and departures for a date', function (): void {
    $manager = managerUser();
    $arrival = deskBooking($manager->id, '2027-11-07', '2027-11-10', BookingStatus::Confirmed, 'ANK-2027-8101');
    $inHouse = deskBooking($manager->id, '2027-11-05', '2027-11-09', BookingStatus::InHouse, 'ANK-2027-8102');
    $departure = deskBooking($manager->id, '2027-11-04', '2027-11-07', BookingStatus::Confirmed, 'ANK-2027-8103');

    $response = $this->actingAs($manager)
        ->getJson('/api/rms/front-desk?date=2027-11-07')
        ->assertOk();

    expect(collect($response->json('arrivals'))->pluck('reference')->all())->toBe([$arrival->reference]);
    expect(collect($response->json('in_house'))->pluck('reference')->all())->toBe([$inHouse->reference]);
    expect(collect($response->json('departures'))->pluck('reference')->all())->toBe([$departure->reference]);
});

test('registration export follows the configured fields, hides sensitive columns, and records no values', function (): void {
    $manager = managerUser();
    $booking = deskBooking($manager->id, '2027-11-07', '2027-11-10', BookingStatus::Confirmed, 'ANK-2027-8201');
    deskBooking($manager->id, '2027-11-07', '2027-11-10', BookingStatus::Cancelled, 'ANK-2027-8202');

    $this->actingAs($manager)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'dob' => '1980-01-01',
            'nationality' => 'GB',
            'passport_no' => 'C4F7K8Q1R',
        ])
        ->assertCreated();

    $csv = $this->actingAs($manager)
        ->get('/api/rms/front-desk/registration?date=2027-11-07&format=csv')
        ->assertOk();

    expect((string) $csv->headers->get('content-type'))->toContain('text/csv');

    $managerRows = csvTable($csv->getContent());

    expect($managerRows[0])->toBe([
        'full_name',
        'nationality',
        'dob',
        'document_number',
        'check_in',
        'check_out',
    ]);
    expect($managerRows[1])->toBe([
        'Ada Lovelace',
        'GB',
        '1980-01-01',
        'C4F7K8Q1R',
        '2027-11-07',
        '2027-11-10',
    ]);
    expect($managerRows)->toHaveCount(2);

    $exec = salesExecUser();
    $hidden = $this->actingAs($exec)
        ->get('/api/rms/front-desk/registration?date=2027-11-07&format=csv')
        ->assertOk();
    $hiddenRows = csvTable($hidden->getContent());

    expect($hiddenRows[0])->toBe(['full_name', 'check_in', 'check_out']);
    expect($hiddenRows[1][0])->toBe('Ada Lovelace');
    expect(implode(',', $hiddenRows[1]))->not->toContain('C4F7K8Q1R');
    expect(implode(',', $hiddenRows[0]))->not->toContain('nationality');

    $history = ChangeHistory::query()->where('event', 'registration.exported')->latest('id')->first();
    expect($history)->not->toBeNull()
        ->and($history?->actor_id)->toBe($exec->id)
        ->and($history?->subject_type)->toBe('property')
        ->and($history?->after['date'] ?? null)->toBe('2027-11-07')
        ->and($history?->after['format'] ?? null)->toBe('CSV')
        ->and($history?->after['guest_count'] ?? null)->toBe(1)
        ->and(json_encode($history?->after))->not->toContain('C4F7K8Q1R')
        ->and(json_encode($history?->after))->not->toContain('1980-01-01');

    $pdf = $this->actingAs($manager)
        ->get('/api/rms/front-desk/registration?date=2027-11-07&format=pdf')
        ->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF');

    $document = businessRulesDocument();
    $document['registration']['fields'] = ['full_name', 'room'];
    $document['registration']['formats'] = ['CSV'];

    $this->actingAs(adminUser())
        ->postJson('/api/rms/business-rules/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'HQ9-EXPORT',
        ])
        ->assertCreated();

    $narrow = csvTable($this->actingAs($manager)
        ->get('/api/rms/front-desk/registration?date=2027-11-07&format=csv')
        ->assertOk()
        ->getContent());

    expect($narrow[0])->toBe(['full_name', 'room']);
    expect($narrow[1][0])->toBe('Ada Lovelace');
    expect($narrow[1][1])->not->toBe('');

    $this->actingAs($manager)
        ->getJson('/api/rms/front-desk/registration?date=2027-11-07&format=pdf')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('format');
});

function deskBooking(int $ownerId, string $checkIn, string $checkOut, BookingStatus $status, string $reference): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $room = $departure->property->cabins->firstWhere('code', 'S1');

    return Booking::factory()->create([
        'reference' => $reference,
        'departure_id' => $departure->id,
        'property_id' => $departure->property_id,
        'room_id' => $room?->id,
        'owner_id' => $ownerId,
        'status' => $status,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
    ]);
}

/**
 * @return list<list<string>>
 */
function csvTable(string $csv): array
{
    $rows = [];

    foreach (preg_split("/\r\n|\n|\r/", trim($csv)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }

        $rows[] = str_getcsv($line);
    }

    return $rows;
}
