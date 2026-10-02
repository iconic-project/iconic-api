<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateReservation;
use App\Enums\BookingSegment;
use App\Enums\BookingStatus;
use App\Enums\CabinCategory;
use App\Enums\ConfigKind;
use App\Enums\MainChannel;
use App\Enums\OfferChannel;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Offer;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\QuoteLine;
use App\Services\Pricing\ReservationQuote;
use App\Services\Pricing\ReservationQuoter;
use App\Support\Bookings\SoldOn;
use App\Support\BusinessTime;
use App\Support\Offers\PromoCode;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Offers\OfferFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function westDeparture(string $date = '2027-11-07', bool $festive = false): Departure
{
    $departure = ReservationFixtures::anamaraDeparture($date, $festive);
    $departure->update([
        'itinerary_id' => OfferFixtures::west()->id,
        'festive' => $festive,
    ]);

    return $departure->fresh(['yacht.cabins', 'itinerary']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function livePriceOffer(array $overrides = []): Offer
{
    return Offer::factory()->live()->create(array_merge([
        'type' => OfferType::Percent,
        'value' => 12,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'is_promo_code' => false,
        'price_line' => 'Last cabins −12%',
        'name' => 'Last cabins',
        'code' => 'LAST12',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $input
 */
function quoteWest(array $input = []): ReservationQuote
{
    $departure = $input['departure'] ?? westDeparture();
    unset($input['departure']);

    return app(ReservationQuoter::class)->quote(array_merge(
        ReservationFixtures::quotePayload($departure, [
            ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
        ]),
        $input,
    ), $departure);
}

function lineAmount(ReservationQuote $quote, string $code): ?int
{
    $priced = $quote->parties[0]->quote;

    if ($priced === null) {
        return null;
    }

    foreach ($priced->lines as $line) {
        if ($line->code === $code) {
            return $line->amount;
        }
    }

    return null;
}

function lineLabel(ReservationQuote $quote, string $code): ?string
{
    $priced = $quote->parties[0]->quote;

    if ($priced === null) {
        return null;
    }

    foreach ($priced->lines as $line) {
        if ($line->code === $code) {
            return $line->label;
        }
    }

    return null;
}

/*
 * Prototype walkthrough (2 adults, Suite 2027, −12 %, ICONIC10).
 * CabinPricer step-1 = 26,600. Rounding::halfUp matches Math.round.
 * later: −12% = 3,192 → 23,408; ICONIC10 10% of 23,408 = 2,341 → 21,067; deposit 2,107.
 * online: −12% = 3,192 → 23,408; −5% = 1,170 → 22,238; ICONIC10 10% of 22,238 = 2,224 → 20,014; deposit 2,001.
 */
test('the prototype walkthrough lines and totals for both paths', function (): void {
    livePriceOffer();
    Offer::factory()->live()->promo()->create([
        'code' => 'ICONIC10',
        'name' => 'Iconic welcome',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'price_line' => 'Iconic welcome −10%',
    ]);

    $later = quoteWest(['promo_code' => 'ICONIC10']);
    expect($later->total())->toBe(21067);
    expect($later->deposit())->toBe(2107);
    expect(lineAmount($later, 'LAST12'))->toBe(-3192);
    expect(lineAmount($later, 'ICONIC10'))->toBe(-2341);
    expect(lineAmount($later, 'online_deposit'))->toBeNull();

    $online = quoteWest(['promo_code' => 'ICONIC10', 'online_deposit' => true]);
    expect($online->total())->toBe(20014);
    expect($online->deposit())->toBe(2001);
    expect(lineAmount($online, 'LAST12'))->toBe(-3192);
    expect(lineAmount($online, 'online_deposit'))->toBe(-1170);
    expect(lineAmount($online, 'ICONIC10'))->toBe(-2224);
    expect(lineLabel($online, 'online_deposit'))->toBe('Online deposit advantage −5%');
});

test('the online advantage is calculated before the promo code', function (): void {
    livePriceOffer();
    Offer::factory()->live()->promo()->create([
        'code' => 'ICONIC10',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'price_line' => 'Iconic welcome −10%',
    ]);

    $quote = quoteWest(['promo_code' => 'ICONIC10', 'online_deposit' => true]);
    $codes = array_map(
        fn (QuoteLine $line): string => $line->code,
        $quote->parties[0]->quote?->lines ?? [],
    );

    expect(array_search('online_deposit', $codes, true))
        ->toBeLessThan((int) array_search('ICONIC10', $codes, true));
    expect(lineAmount($quote, 'ICONIC10'))->toBe(-2224);
});

test('a festive departure refuses every discount including EARLY500', function (): void {
    $departure = westDeparture('2027-12-19', true);
    livePriceOffer(['itinerary_codes' => ['WEST', 'FEST']]);
    Offer::factory()->live()->promo()->create([
        'code' => 'EARLY500',
        'type' => OfferType::Amount,
        'value' => 500,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'price_line' => 'Early booking −USD 500 pp',
    ]);

    $quote = quoteWest([
        'departure' => $departure,
        'promo_code' => 'EARLY500',
        'online_deposit' => true,
    ]);

    expect($quote->total())->toBe(28100);
    expect(lineAmount($quote, 'LAST12'))->toBeNull();
    expect(lineAmount($quote, 'EARLY500'))->toBeNull();
    expect(lineAmount($quote, 'online_deposit'))->toBeNull();

    $check = PromoCode::check(
        'EARLY500',
        $departure,
        CabinCategory::Suite,
        BookingSegment::D2C,
        SoldOn::today(),
    );
    expect($check['valid'])->toBeFalse();
    expect($check['reason'])->toBe('This code does not apply to festive departures');
});

test('a non-combinable pair keeps the larger saving and explains', function (): void {
    livePriceOffer(['combinable' => false, 'name' => 'Shoulder season', 'code' => 'SHOULDER15', 'value' => 15, 'price_line' => 'Shoulder season −15%']);
    Offer::factory()->live()->promo()->create([
        'code' => 'ICONIC10',
        'name' => 'Iconic welcome',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'price_line' => 'Iconic welcome −10%',
    ]);

    $quote = quoteWest(['promo_code' => 'ICONIC10']);

    expect(lineAmount($quote, 'SHOULDER15'))->toBe(-3990);
    expect(lineAmount($quote, 'ICONIC10'))->toBeNull();
    expect($quote->warnings)->toContain('ICONIC10 cannot be combined with Shoulder season — the larger discount was kept');
    expect($quote->total())->toBe(22610);
});

test('the cap trims the last discount applied', function (): void {
    livePriceOffer();
    $document = businessRulesDocument();
    $document['discounts']['max_total_discount_pct'] = 15;
    $current = app(CurrentConfig::class)->version(ConfigKind::BusinessRules);

    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $document,
        $current->version,
        'CAP-15',
        adminUser(),
    );

    $quote = quoteWest(['online_deposit' => true]);

    expect(lineAmount($quote, 'LAST12'))->toBe(-3192);
    expect(lineAmount($quote, 'online_deposit'))->toBe(-798);
    expect(lineLabel($quote, 'online_deposit'))->toContain('reduced to the maximum discount');
    expect($quote->total())->toBe(22610);
});

test('a CREDIT offer adds a zero line and the deposit stays on the discounted cruise total', function (): void {
    livePriceOffer();
    Offer::factory()->live()->create([
        'code' => 'OPENING-27',
        'name' => 'Opening season credit',
        'type' => OfferType::Credit,
        'value' => 500,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => false,
        'price_line' => 'Opening season credit — on-board ancillaries',
    ]);

    $quote = quoteWest();

    expect(lineAmount($quote, 'OPENING-27'))->toBe(0);
    expect(lineLabel($quote, 'OPENING-27'))->toBe('Opening season credit — on-board ancillaries');
    expect(lineAmount($quote, 'LAST12'))->toBe(-3192);
    expect($quote->total())->toBe(23408);
    expect($quote->deposit())->toBe(2341);
});

test('publishing the online-deposit rule at 7 updates the line label and amount', function (): void {
    $document = businessRulesDocument();
    $document['discounts']['online_deposit_discount_pct'] = 7;
    $current = app(CurrentConfig::class)->version(ConfigKind::BusinessRules);

    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $document,
        $current->version,
        'ADV-7',
        adminUser(),
    );

    $quote = quoteWest(['online_deposit' => true]);

    expect(lineLabel($quote, 'online_deposit'))->toBe('Online deposit advantage −7%');
    expect(lineAmount($quote, 'online_deposit'))->toBe(-1862);
    expect($quote->total())->toBe(24738);
});

test('PromoCode::check uses the prototype wording', function (): void {
    $departure = westDeparture();
    Offer::factory()->live()->promo()->create([
        'code' => 'ICONIC10',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
    ]);

    $ok = PromoCode::check('iconic10', $departure, CabinCategory::Suite, BookingSegment::D2C, SoldOn::today());
    expect($ok['valid'])->toBeTrue();
    expect($ok['reason'])->toBeNull();

    $bad = PromoCode::check('NOPE', $departure, CabinCategory::Suite, BookingSegment::D2C, SoldOn::today());
    expect($bad['valid'])->toBeFalse();
    expect($bad['reason'])->toBe('This code is not valid');
});

test('staff quotes default to D2C and never take a promo or the online advantage', function (): void {
    livePriceOffer();
    Offer::factory()->live()->create([
        'code' => 'TRADE12',
        'type' => OfferType::Percent,
        'value' => 12,
        'channel' => OfferChannel::B2B,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'price_line' => 'Trade −12%',
        'show_on_card' => false,
        'show_on_departures' => false,
    ]);

    $departure = westDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($departure, [
            ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
        ]))
        ->assertOk()
        ->assertJsonPath('total', 23408);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', array_merge(
            ReservationFixtures::quotePayload($departure, [
                ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
            ]),
            ['main_channel' => MainChannel::B2BTravelAdvisor->value],
        ))
        ->assertOk()
        ->assertJsonPath('total', 23408)
        ->assertJsonPath('cabins.0.quote.lines.1.code', 'TRADE12');
});

test('publishing a change to an applied offer does not alter a sold booking', function (): void {
    livePriceOffer();
    $departure = westDeparture();
    $created = app(CreateReservation::class)->handle(
        ReservationFixtures::createPayload($departure),
        managerUser(),
    );
    $booking = $created->bookings->firstOrFail();
    expect($booking->total)->toBe(23408);

    Offer::query()->where('code', 'LAST12')->update([
        'status' => OfferStatus::Paused->value,
        'value' => 20,
    ]);

    expect($booking->fresh()->total)->toBe(23408);
    expect(collect($booking->fresh()->price_lines)->firstWhere('code', 'LAST12')['amount'] ?? null)->toBe(-3192);
});

test('a move keeps a promo whose booking window has since closed', function (): void {
    $saleDay = CarbonImmutable::parse('2026-09-20 12:00:00', BusinessTime::zone());
    $this->travelTo($saleDay);

    Offer::factory()->live()->promo()->create([
        'code' => 'ICONIC10',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'booking_from' => '2026-09-20',
        'booking_to' => '2026-09-20',
        'price_line' => 'Iconic welcome −10%',
    ]);

    $departure = westDeparture();
    $created = app(CreateReservation::class)->handle(
        array_merge(ReservationFixtures::createPayload($departure), [
            'promo_code' => 'ICONIC10',
        ]),
        managerUser(),
    );
    $booking = $created->bookings->firstOrFail();
    expect($booking->total)->toBe(23940);
    expect($booking->promo_code)->toBe('ICONIC10');
    expect($booking->sold_on->toDateString())->toBe('2026-09-20');

    $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00', BusinessTime::zone()));

    $checkToday = PromoCode::check(
        'ICONIC10',
        $departure,
        CabinCategory::Suite,
        BookingSegment::D2C,
        SoldOn::today(),
    );
    expect($checkToday['valid'])->toBeFalse();

    $preview = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $booking->departure_id,
            'cabin_code' => 'S2',
        ])
        ->assertOk()
        ->json();

    expect($preview['new_total'])->toBe(23940);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'departure_id' => $booking->departure_id,
            'cabin_code' => 'S2',
            'confirm_total' => $preview['new_total'],
        ])
        ->assertOk()
        ->assertJsonPath('total', 23940);
});

test('sold_on backfill uses the Galápagos date of created_at, not the next UTC day', function (): void {
    $booking = Booking::factory()->create();
    $created = CarbonImmutable::parse('2026-09-21 23:30:00', BusinessTime::zone());

    expect($created->utc()->toDateString())->toBe('2026-09-22');
    expect(SoldOn::fromTimestamp($created))->toBe('2026-09-21');

    Schema::table('bookings', function (Blueprint $table): void {
        $table->date('sold_on')->nullable()->change();
    });

    DB::table('bookings')->where('id', $booking->id)->update([
        'created_at' => $created->utc(),
        'sold_on' => null,
    ]);

    expect(SoldOn::backfillMissing())->toBe(1);
    expect($booking->fresh()->sold_on?->toDateString())->toBe('2026-09-21');
});

test('a 2 percent COMM offer on a 10 percent agency booking stays at the cap', function (): void {
    Offer::factory()->live()->create([
        'code' => 'VIRTUOSO-TEST',
        'name' => 'Virtuoso extra',
        'type' => OfferType::Commission,
        'value' => 2,
        'channel' => OfferChannel::B2B,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => false,
        'show_on_card' => false,
        'show_on_departures' => false,
        'price_line' => null,
    ]);

    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = westDeparture();
    $created = app(CreateReservation::class)->handle(
        ReservationFixtures::createPayload($departure, [
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
            'commission_pct' => 10,
        ]),
        managerUser(),
    );
    $booking = $created->bookings->firstOrFail();

    expect($booking->status)->toBe(BookingStatus::PendingPayment);
    expect($booking->commission_pct)->toBe(12);
    expect($booking->commission_approved)->toBeTrue();
    expect($booking->total)->toBe(26600);

    $history = ChangeHistory::query()->where('event', 'booking.created')->where('subject_id', $booking->id)->firstOrFail();
    expect($history->after['commission_offers'] ?? null)->toBe(['VIRTUOSO-TEST']);
});

test('a COMM offer that pushes commission to 13 percent holds the booking', function (): void {
    Offer::factory()->live()->create([
        'code' => 'VIRTUOSO-OVER',
        'type' => OfferType::Commission,
        'value' => 3,
        'channel' => OfferChannel::B2B,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => false,
        'show_on_card' => false,
        'show_on_departures' => false,
    ]);

    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = westDeparture();
    $created = app(CreateReservation::class)->handle(
        ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
            'main_channel' => MainChannel::B2BTravelAdvisor->value,
            'channel_of_origin' => 'Travel Advisor',
            'agency_id' => $agency->id,
            'commission_pct' => 10,
        ]),
        managerUser(),
    );
    $booking = $created->bookings->firstOrFail();

    expect($booking->status)->toBe(BookingStatus::OnHoldAgency);
    expect($booking->commission_pct)->toBe(13);

    $held = ChangeHistory::query()->where('event', 'booking.commission_held')->where('subject_id', $booking->id)->firstOrFail();
    expect($held->after['what'] ?? '')->toContain('VIRTUOSO-OVER');
});
