<?php

declare(strict_types=1);

use App\Models\Agency;
use App\Models\Booking;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    adminUser();
});

test('a staff agency booking keeps commission and has no marketing touch', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture('2027-12-12');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'owner_id' => adminUser()->id,
    ]);

    expect($booking->utm_first)->toBeNull();
    expect($booking->utm_last)->toBeNull();
    expect($booking->agency_id)->toBe($agency->id);
    expect($booking->commission_pct)->toBe(10);

    expect(fn () => DB::table('bookings')->where('id', $booking->id)->update([
        'utm_last' => json_encode(['source' => 'google']),
    ]))->toThrow(QueryException::class);
});
