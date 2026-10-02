<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ConsentDocument;
use App\Models\Booking;
use App\Models\Consent;
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
});

test('a raw update and delete on consents fail', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'owner_id' => managerUser()->id,
    ]);

    $consent = Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Privacy,
    ]);

    expect(fn () => DB::table('consents')->where('id', $consent->id)->update(['version' => 'tampered']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('consents')->where('id', $consent->id)->delete())
        ->toThrow(QueryException::class);

    expect(Consent::query()->where('id', $consent->id)->exists())->toBeTrue();
});
