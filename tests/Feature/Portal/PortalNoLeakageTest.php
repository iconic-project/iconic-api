<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\MainChannel;
use App\Models\Booking;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @return list<int>
 */
function walkIntegers(mixed $value): array
{
    $found = [];
    $walk = function (mixed $value) use (&$walk, &$found): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $walk($item);
            }

            return;
        }

        if (is_int($value)) {
            $found[] = $value;
        }
    };
    $walk($value);

    return $found;
}

/**
 * @return list<string>
 */
function walkKeys(mixed $value): array
{
    $found = [];
    $walk = function (mixed $value) use (&$walk, &$found): void {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $found[] = $key;
            }
            $walk($item);
        }
    };
    $walk($value);

    return $found;
}

test('no portal response leaks a public rate, sensitive guest fields, or a payment/document key', function (): void {
    $agency = approvedAgency(['commission_pct' => 10]);
    $user = agencyUser([], $agency);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');

    $booking = Booking::factory()->create([
        'reference' => 'ANK-2026-6001',
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 10000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'is_lead' => true,
        'first_name' => 'Mariana',
        'last_name' => 'Castellanos',
        'email' => 'mariana@example.test',
        'dob' => '1980-01-01',
        'nationality' => 'EC',
        'passport_no' => 'X1234567',
    ]);

    $rates = app(CurrentConfig::class)->rates();
    $publicRates = [];
    foreach ($rates->years as $year) {
        $publicRates[] = $year->suitePp;
        $publicRates[] = $year->ownerPp;
        $publicRates[] = $year->charterWeek;
    }

    $alwaysForbidden = ['payments', 'guests', 'documents'];

    // /me legitimately shows the signed-in agency user's own email — the "no bare
    // email key" check only applies where a leaked GUEST email would be the risk.
    $endpoints = [
        '/api/portal/me' => $alwaysForbidden,
        '/api/portal/rates' => $alwaysForbidden,
        '/api/portal/availability' => [...$alwaysForbidden, 'email'],
        '/api/portal/bookings' => [...$alwaysForbidden, 'email'],
        '/api/portal/commissions' => [...$alwaysForbidden, 'email'],
        '/api/portal/sales-materials' => $alwaysForbidden,
    ];

    foreach ($endpoints as $uri => $forbiddenKeys) {
        $response = $this->actingAs($user, 'agency')
            ->withHeaders(portalHeaders())
            ->getJson($uri)
            ->assertOk();

        assertNoSensitiveFields($response);

        $json = $response->json();

        $leakedIntegers = array_intersect(walkIntegers($json), $publicRates);
        expect($leakedIntegers)->toBe([], "public rate leaked in {$uri}");

        $leakedKeys = array_intersect(walkKeys($json), $forbiddenKeys);
        expect($leakedKeys)->toBe([], "forbidden key leaked in {$uri}");
    }
});
