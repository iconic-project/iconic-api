<?php

declare(strict_types=1);

use App\Enums\BookingSegment;
use App\Enums\CabinCategory;
use App\Enums\OfferChannel;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Departure;
use App\Models\Offer;
use App\Models\Yacht;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Offers\OfferFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    OfferFixtures::west();
    OfferFixtures::north();
});

test('applicableTo matches channel cabin itinerary and both windows', function (): void {
    $west = ReservationFixtures::anamaraDeparture('2027-11-14');
    $west->itinerary()->associate(OfferFixtures::west());
    $west->save();

    $offer = Offer::factory()->live()->create([
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'booking_from' => '2027-01-01',
        'booking_to' => '2027-12-31',
        'travel_from' => '2027-11-01',
        'travel_to' => '2027-11-30',
    ]);

    $hits = Offer::applicableTo($west, CabinCategory::Suite, BookingSegment::D2C, '2027-06-15');

    expect($hits->pluck('id')->all())->toBe([$offer->id]);

    expect(Offer::applicableTo($west, CabinCategory::Owner, BookingSegment::D2C, '2027-06-15'))->toBeEmpty();
    expect(Offer::applicableTo($west, CabinCategory::Suite, BookingSegment::B2B, '2027-06-15'))->toBeEmpty();
    expect(Offer::applicableTo($west, CabinCategory::Suite, BookingSegment::D2C, '2026-12-31'))->toBeEmpty();

    $north = Departure::factory()->create([
        'yacht_id' => Yacht::query()->where('code', 'ANATIVA')->firstOrFail()->id,
        'itinerary_id' => OfferFixtures::north()->id,
        'date' => '2027-11-14',
    ]);

    expect(Offer::applicableTo($north, CabinCategory::Suite, BookingSegment::D2C, '2027-06-15'))->toBeEmpty();
});

test('applicableTo never returns a festive paused or expired offer', function (): void {
    $festive = ReservationFixtures::anamaraDeparture('2027-12-19', festive: true);
    $open = ReservationFixtures::anamaraDeparture('2027-11-14');
    $open->itinerary()->associate(OfferFixtures::west());
    $open->save();

    Offer::factory()->live()->create([
        'code' => 'OPEN10',
        'itinerary_codes' => ['WEST'],
        'cabin_types' => [CabinCategory::Suite->value],
    ]);

    expect(Offer::applicableTo($festive, CabinCategory::Suite, BookingSegment::D2C, '2027-06-15'))->toBeEmpty();

    $paused = Offer::factory()->paused()->create([
        'code' => 'HOLD10',
        'itinerary_codes' => ['WEST'],
        'cabin_types' => [CabinCategory::Suite->value],
    ]);

    expect(Offer::applicableTo($open, CabinCategory::Suite, BookingSegment::D2C, '2027-06-15')->pluck('id'))
        ->not->toContain($paused->id);

    Offer::factory()->live()->create([
        'code' => 'OLD10',
        'itinerary_codes' => ['WEST'],
        'cabin_types' => [CabinCategory::Suite->value],
        'travel_to' => '2020-01-01',
        'status' => OfferStatus::Live,
    ]);

    expect(Offer::applicableTo($open, CabinCategory::Suite, BookingSegment::D2C, '2027-06-15')->pluck('code'))
        ->not->toContain('OLD10');
});

test('a promo code applies only when the code is given and is case-insensitive', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');
    $departure->itinerary()->associate(OfferFixtures::west());
    $departure->save();

    Offer::factory()->live()->promo()->create([
        'code' => 'ICONIC10',
        'type' => OfferType::Percent,
        'value' => 10,
        'itinerary_codes' => ['WEST'],
        'cabin_types' => [CabinCategory::Suite->value],
    ]);

    expect(Offer::applicableTo($departure, CabinCategory::Suite, BookingSegment::D2C, '2027-06-15'))->toBeEmpty();

    $hits = Offer::applicableTo($departure, CabinCategory::Suite, BookingSegment::D2C, '2027-06-15', 'iconic10');

    expect($hits->pluck('code')->all())->toBe(['ICONIC10']);
});

test('all-channel offers match both d2c and b2b', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');
    $departure->itinerary()->associate(OfferFixtures::west());
    $departure->save();

    Offer::factory()->live()->create([
        'channel' => OfferChannel::All,
        'itinerary_codes' => ['WEST'],
        'cabin_types' => [CabinCategory::Suite->value],
    ]);

    expect(Offer::applicableTo($departure, CabinCategory::Suite, BookingSegment::D2C, '2027-06-15'))->toHaveCount(1);
    expect(Offer::applicableTo($departure, CabinCategory::Suite, BookingSegment::B2B, '2027-06-15'))->toHaveCount(1);
});

test('applicableTo judges derived expiry against the booking date, not today', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');
    $departure->itinerary()->associate(OfferFixtures::west());
    $departure->save();

    Offer::factory()->live()->create([
        'code' => 'LASTDAY',
        'channel' => OfferChannel::D2C,
        'itinerary_codes' => ['WEST'],
        'cabin_types' => [CabinCategory::Suite->value],
        'booking_from' => '2026-09-20',
        'booking_to' => '2026-09-20',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00', BusinessTime::zone()));

    expect(Offer::applicableTo($departure, CabinCategory::Suite, BookingSegment::D2C, '2026-09-21'))->toBeEmpty();
    expect(Offer::applicableTo($departure, CabinCategory::Suite, BookingSegment::D2C, '2026-09-20')->pluck('code')->all())
        ->toBe(['LASTDAY']);
});
