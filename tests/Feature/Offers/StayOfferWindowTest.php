<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Models\Offer;
use App\Models\RoomType;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\StayQuoteInput;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayReservationQuote;
use App\Support\Stays\StayDates;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(HotelSeeder::class);
});

function quoteStayWindow(string $roomType, string $checkIn, string $checkOut): StayReservationQuote
{
    $type = RoomType::query()->where('code', $roomType)->firstOrFail();
    $result = app(StayQuoter::class)->quote($type, new StayQuoteInput(
        StayDates::of($checkIn, $checkOut),
        $roomType,
        2,
        [],
        'BAR',
    ));

    expect($result)->toBeInstanceOf(StayReservationQuote::class);

    return $result;
}

test('a percent offer discounts only the nights inside the stay window', function (): void {
    Offer::factory()->live()->create([
        'code' => 'PEAK10',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'stay_from' => '2026-12-21',
        'stay_to' => '2026-12-22',
        'price_line' => 'Two nights −10%',
        'badge' => 'TWO NIGHTS',
        'show_on_card' => true,
        'combinable' => true,
    ]);

    $quoted = quoteStayWindow('STD', '2026-12-21', '2026-12-25');
    $nights = $quoted->quote->nightLines;

    expect($nights)->toHaveCount(4);
    expect($nights[0]->discount)->toBe(25);
    expect($nights[1]->discount)->toBe(25);
    expect($nights[2]->discount)->toBe(0);
    expect($nights[3]->discount)->toBe(0);
    expect(collect($quoted->quote->lines)->firstWhere('code', 'PEAK10')?->amount)->toBe(-50);
    expect($quoted->quote->total)->toBe(1000);
});

test('min nights and room type scope skip the offer', function (): void {
    Offer::factory()->live()->create([
        'code' => 'LONG5',
        'type' => OfferType::Percent,
        'value' => 10,
        'stay_from' => '2026-12-21',
        'stay_to' => '2026-12-24',
        'min_nights' => 5,
        'price_line' => 'Long stay',
    ]);
    Offer::factory()->live()->create([
        'code' => 'TWNONLY',
        'type' => OfferType::Percent,
        'value' => 10,
        'stay_from' => '2026-12-21',
        'stay_to' => '2026-12-24',
        'applies_to_room_types' => ['TWN'],
        'price_line' => 'Twin only',
    ]);

    $quoted = quoteStayWindow('STD', '2026-12-21', '2026-12-25');

    expect(collect($quoted->quote->lines)->pluck('code'))->not->toContain('LONG5');
    expect(collect($quoted->quote->lines)->pluck('code'))->not->toContain('TWNONLY');
    expect($quoted->quote->total)->toBe(1050);

    $twin = quoteStayWindow('TWN', '2026-12-21', '2026-12-25');

    expect(collect($twin->quote->lines)->firstWhere('code', 'TWNONLY')?->amount)->toBe(-93);
});

test('the combined cap trims the stay discount and the night lines', function (): void {
    Offer::factory()->live()->create([
        'code' => 'HALF',
        'type' => OfferType::Percent,
        'value' => 50,
        'stay_from' => '2026-12-21',
        'stay_to' => '2026-12-24',
        'price_line' => 'Half off',
        'combinable' => true,
    ]);

    $document = app(CurrentConfig::class)->businessRules()->toArray();
    $document['discounts']['max_total_discount_pct'] = 15;
    $current = app(CurrentConfig::class)->version(ConfigKind::BusinessRules);

    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $document,
        $current->version,
        'CAP-15',
        adminUser(),
    );

    $quoted = quoteStayWindow('STD', '2026-12-21', '2026-12-25');
    $line = collect($quoted->quote->lines)->firstWhere('code', 'HALF');
    $discount = 0;

    foreach ($quoted->quote->nightLines as $night) {
        $discount += $night->discount;
    }

    expect($line?->amount)->toBe(-158);
    expect($line?->label())->toContain('reduced to the maximum discount');
    expect($discount)->toBe(158);
    expect($quoted->quote->total)->toBe(892);
});

test('the engine promo check uses the stay window, min nights and room type', function (): void {
    Offer::factory()->live()->promo()->create([
        'code' => 'STAY10',
        'channel' => OfferChannel::D2C,
        'stay_from' => '2026-12-22',
        'stay_to' => '2026-12-23',
        'min_nights' => 2,
        'applies_to_room_types' => ['STD'],
        'price_line' => 'Stay 10',
    ]);

    $this->postJson('/api/engine/promo/check', [
        'code' => 'STAY10',
        'check_in' => '2026-12-21',
        'check_out' => '2026-12-25',
        'room_type' => 'STD',
    ])->assertOk()->assertJson([
        'valid' => true,
        'line' => 'Stay 10',
        'applies_to' => ['STD'],
    ]);

    $this->postJson('/api/engine/promo/check', [
        'code' => 'STAY10',
        'check_in' => '2026-12-21',
        'check_out' => '2026-12-22',
        'room_type' => 'STD',
    ])->assertOk()->assertJsonPath('valid', false);

    $this->postJson('/api/engine/promo/check', [
        'code' => 'STAY10',
        'check_in' => '2026-12-21',
        'check_out' => '2026-12-25',
        'room_type' => 'TWN',
    ])->assertOk()->assertJsonPath('valid', false);
});

test('the calendar from price carries the offer pill and the discounted night', function (): void {
    Offer::factory()->live()->create([
        'code' => 'LOW10',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'stay_from' => '2026-12-21',
        'stay_to' => '2026-12-21',
        'badge' => 'LOW 10',
        'show_on_card' => true,
        'price_line' => 'Low −10%',
        'combinable' => true,
    ]);

    $night = collect($this->getJson('/api/engine/calendar?from=2026-12&months=1&adults=2&children=0')
        ->assertOk()
        ->json('nights'))
        ->firstWhere('night', '2026-12-21');

    expect($night['from_price'])->toBe(198);
    expect($night['offers'])->toBe(['LOW 10']);

    $later = collect($this->getJson('/api/engine/calendar?from=2026-12&months=1&adults=2&children=0')
        ->assertOk()
        ->json('nights'))
        ->firstWhere('night', '2026-12-22');

    expect($later['from_price'])->toBe(220);
    expect($later['offers'])->toBe([]);
});
