<?php

declare(strict_types=1);

use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\DealStage;
use App\Enums\DealType;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\JourneyEnrolment;
use App\Models\JourneyStep;
use App\Models\Segment;
use App\Support\BusinessTime;
use App\Support\Crm\SegmentCompiler;
use App\Support\Journeys\JourneyClock;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('segment filters use the stay and do not join departures', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $contact = Contact::factory()->create(['name' => 'Sunday Arrival']);
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'owner_id' => $actor->id,
        'status' => BookingStatus::Confirmed,
        'rate_plan_code' => 'FLEX',
    ]);
    $booking->refresh()->load('roomType');
    $weekday = $booking->check_in->format('l');

    $created = $this->actingAs($actor)->postJson('/api/crm/segments', [
        'name' => 'Sunday flex stays',
        'sentence' => 'Arrives on that Sunday in a flex plan.',
        'conditions' => [
            'match' => 'all',
            'items' => [
                ['field' => 'stay_date', 'operator' => 'eq', 'value' => $booking->check_in->toDateString()],
                ['field' => 'arrival_weekday', 'operator' => 'eq', 'value' => $weekday],
                ['field' => 'length_of_stay', 'operator' => 'eq', 'value' => $booking->nights],
                ['field' => 'room_type', 'operator' => 'eq', 'value' => $booking->roomType->code],
                ['field' => 'rate_plan', 'operator' => 'eq', 'value' => 'FLEX'],
            ],
        ],
        'dimensions' => [
            ['axis' => 'PROFILE', 'label' => 'stay'],
        ],
        'kind' => 'OPERATIONAL',
        'feeds' => 'Nothing yet',
    ])->assertCreated();

    assertNoSensitiveFields($created);

    $members = $this->actingAs($actor)
        ->getJson('/api/crm/segments/sunday_flex_stays/contacts')
        ->assertOk();

    assertNoSensitiveFields($members);
    expect(collect($members->json('data'))->pluck('id')->all())->toContain($contact->id);

    $compiled = SegmentCompiler::compile([
        'match' => 'all',
        'items' => [
            ['field' => 'stay_date', 'operator' => 'eq', 'value' => '2027-11-07'],
            ['field' => 'guest_age', 'operator' => 'between', 'value' => [6, 17]],
        ],
    ]);

    expect($compiled['sql'])->not->toContain('departures')
        ->and($compiled['sql'])->toContain('bookings.check_in');
});

test('journey anchors measure from check-in and check-out', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
    ]);
    $booking->refresh();
    $enrolment = new JourneyEnrolment;
    $enrolment->setRelation('booking', $booking);
    $clock = app(JourneyClock::class);

    $arrival = new JourneyStep;
    $arrival->delay = ['anchor' => 'arrival', 'amount' => -3, 'unit' => 'days'];
    $legacy = new JourneyStep;
    $legacy->delay = ['anchor' => 'departure', 'amount' => -3, 'unit' => 'days'];
    $checkOut = new JourneyStep;
    $checkOut->delay = ['anchor' => 'check_out', 'amount' => 1, 'unit' => 'days'];

    $expectedArrival = BusinessTime::calendarDay($booking->check_in->toDateString())->subDays(3)->utc();
    $expectedCheckOut = BusinessTime::calendarDay($booking->check_out->toDateString())->addDays(1)->utc();

    expect($clock->dueAt($enrolment, $arrival)->toDateString())->toBe($expectedArrival->toDateString())
        ->and($clock->dueAt($enrolment, $legacy)->toDateString())->toBe($expectedArrival->toDateString())
        ->and($clock->dueAt($enrolment, $checkOut)->toDateString())->toBe($expectedCheckOut->toDateString());
});

test('the deal drawer shows the stay and stay searches', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-12-05');
    $contact = Contact::factory()->create();
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'owner_id' => $actor->id,
        'status' => BookingStatus::Confirmed,
    ]);
    $booking->refresh()->load(['roomType', 'property']);
    $deal = Deal::query()->create([
        'contact_id' => $contact->id,
        'owner_id' => $actor->id,
        'title' => 'Stay search',
        'type' => DealType::Fit,
        'stage' => DealStage::NewLead,
        'stage_entered_at' => now(),
        'estimate' => 1000,
        'booking_id' => $booking->id,
    ]);
    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => BehaviouralEventName::SearchPerformed,
        'params' => [
            'check_in' => '2027-12-05',
            'check_out' => '2027-12-12',
            'adults' => 2,
            'children' => 0,
            'rooms' => 1,
        ],
    ]);

    $response = $this->actingAs($actor)->getJson('/api/crm/deals/'.$deal->id)->assertOk();
    assertNoSensitiveFields($response);

    expect($response->json('booking.check_in'))->toBe($booking->check_in->toDateString())
        ->and($response->json('booking.check_out'))->toBe($booking->check_out->toDateString())
        ->and($response->json('booking.nights'))->toBe($booking->nights)
        ->and($response->json('booking.room_type'))->toBe($booking->roomType?->name)
        ->and($response->json('booking.property_name'))->toBe($booking->property?->name)
        ->and($response->json('booking.departure_date'))->toBe($booking->check_in->toDateString())
        ->and($response->json('searches.0.name'))->toBe('search_performed')
        ->and($response->json('searches.0.detail'))->toContain('5 Dec 2027');
});

test('stored journey anchors move to the stay clock and journey history stays', function (): void {
    $step = JourneyStep::query()->firstOrFail();
    $history = ChangeHistory::query()->where('subject_type', 'journey')->where('subject_id', $step->journey_id)->count();
    $step->delay = ['anchor' => 'departure', 'amount' => -14, 'unit' => 'days'];
    $step->save();

    $segment = Segment::query()->where('key', 'festive_prospects')->firstOrFail();
    $segment->conditions = [
        'match' => 'all',
        'items' => [
            ['field' => 'festive_departure_views', 'operator' => 'gte', 'value' => 1, 'within_days' => null],
        ],
    ];
    $segment->sentence = 'Viewed a festive departure.';
    $segment->save();

    $migration = require database_path('migrations/2026_10_06_150001_stay_clock_journey_anchors.php');
    $migration->up();

    $step->refresh();
    $segment->refresh();

    expect($step->delay['anchor'] ?? null)->toBe('arrival')
        ->and($segment->conditions['items'][0]['field'] ?? null)->toBe('event_count')
        ->and($segment->conditions['items'][0]['event'] ?? null)->toBe('view_departure')
        ->and($segment->sentence)->toBe('Viewed a departure.')
        ->and(ChangeHistory::query()->where('subject_type', 'journey')->where('subject_id', $step->journey_id)->count())->toBe($history);
});
