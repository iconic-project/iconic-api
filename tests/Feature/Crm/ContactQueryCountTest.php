<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Contact;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the crm contacts list query count does not grow with extra contacts and bookings', function (): void {
    $actor = managerUser();
    $first = Contact::factory()->create();
    $departure = ReservationFixtures::anamaraDeparture('2027-12-19');
    Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'contact_id' => $first->id,
        'owner_id' => $actor->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($actor)->getJson('/api/crm/contacts')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($actor)->getJson('/api/crm/contacts')->assertOk();
    $before = count(DB::getQueryLog());

    foreach (['S2', 'S3', 'S4'] as $index => $cabin) {
        $contact = Contact::factory()->create();
        $extra = ReservationFixtures::anamaraDeparture('2028-01-0'.($index + 2));
        Booking::factory()->create([
            'departure_id' => $extra->id,
            'cabin_id' => $extra->property->cabins->firstWhere('code', $cabin)?->id,
            'contact_id' => $contact->id,
            'owner_id' => $actor->id,
            'status' => BookingStatus::Confirmed,
        ]);
    }

    DB::flushQueryLog();
    $this->actingAs($actor)->getJson('/api/crm/contacts')->assertOk();
    $after = count(DB::getQueryLog());

    expect($after)->toBe($before);
});
