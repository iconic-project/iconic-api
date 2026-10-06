<?php

declare(strict_types=1);

use App\Actions\Crm\RecordContactConsent;
use App\Enums\AgencyStatus;
use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentPurpose;
use App\Enums\ContactType;
use App\Enums\DeliveryStatus;
use App\Enums\GuestResponseSource;
use App\Enums\Permission;
use App\Enums\SegmentKind;
use App\Models\Agency;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\ErasureLog;
use App\Models\Guest;
use App\Models\GuestResponse;
use App\Models\Role;
use App\Models\Segment;
use App\Models\User;
use App\Support\Crm\SegmentCompiler;
use App\Support\Crm\Suppression;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Bookings\StayAnchor;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('each seeded segment count equals the contacts it lists', function (): void {
    $actor = managerUser();
    $dreamer = consentedContact(['country' => 'DE', 'name' => 'Ada Dreamer']);
    stitchedEvent($dreamer, BehaviouralEventName::ViewProperty);
    stitchedEvent($dreamer, BehaviouralEventName::ViewProperty);
    BehaviouralEvent::factory()->create([
        'contact_id' => null,
        'name' => BehaviouralEventName::ViewProperty,
    ]);

    $festiveDeparture = ReservationFixtures::anamaraDeparture('2027-11-07', true);
    $festive = consentedContact(['name' => 'Festive Viewer']);
    stitchedEvent($festive, BehaviouralEventName::ViewStay, [
    ]);

    $familyDeparture = ReservationFixtures::anamaraDeparture('2027-11-14');
    $family = consentedContact(['name' => 'Family Booker', 'country' => 'US']);
    $familyBooking = booked($family, $actor, $familyDeparture, 'S1', BookingStatus::Confirmed);
    Guest::factory()->create([
        'booking_id' => $familyBooking->id,
        'dob' => '2016-01-15',
        'email' => $family->email,
    ]);

    $pastDeparture = ReservationFixtures::anamaraDeparture('2024-01-07');
    $past = consentedContact(['name' => 'Past Guest', 'country' => 'US']);
    $pastBooking = booked($past, $actor, $pastDeparture, 'S1', BookingStatus::CheckedOut);
    Guest::factory()->create([
        'booking_id' => $pastBooking->id,
        'email' => $past->email,
        'is_lead' => true,
    ]);
    GuestResponse::query()->create([
        'guest_id' => $pastBooking->guests()->firstOrFail()->id,
        'booking_id' => $pastBooking->id,
        'score' => 9,
        'source' => GuestResponseSource::Staff,
        'responded_at' => now(),
    ]);

    $advisorEmail = 'advisor@example.com';
    Contact::factory()->create([
        'name' => 'Quiet Advisor',
        'email' => $advisorEmail,
        'type' => ContactType::TravelAgent,
    ]);
    Agency::factory()->create([
        'email' => $advisorEmail,
        'status' => AgencyStatus::Approved,
    ]);

    $index = $this->actingAs($actor)->getJson('/api/crm/segments')->assertOk();
    assertNoSensitiveFields($index);

    expect($index->json('data'))->toHaveCount(9)
        ->and($index->json('data.0.key'))->toBe('warm_dreamers')
        ->and($index->json('meta.cap'))->toBe(50)
        ->and($index->json('meta.capped'))->toBeFalse();

    foreach ($index->json('data') as $row) {
        $contacts = $this->actingAs($actor)->getJson('/api/crm/segments/'.$row['key'].'/contacts')->assertOk();
        assertNoSensitiveFields($contacts);
        expect($contacts->json('meta.total'))->toBe($row['count']);
    }

    expect(memberIds($this, $actor, 'warm_dreamers'))->toContain($dreamer->id)
        ->and(memberIds($this, $actor, 'dach_luxury'))->toContain($dreamer->id)
        ->and(memberIds($this, $actor, 'festive_prospects'))->toContain($festive->id)
        ->and(memberIds($this, $actor, 'families_6_17'))->toContain($family->id)
        ->and(memberIds($this, $actor, 'past_guests_high_ltv'))->toContain($past->id)
        ->and(memberIds($this, $actor, 'advisors_non_producing'))->not->toBeEmpty();
});

test('withdrawing marketing consent leaves every marketing segment and enters suppression', function (): void {
    $actor = managerUser();
    $dreamer = consentedContact(['country' => 'DE']);
    stitchedEvent($dreamer, BehaviouralEventName::ViewProperty);
    stitchedEvent($dreamer, BehaviouralEventName::ViewProperty);

    expect(memberIds($this, $actor, 'warm_dreamers'))->toContain($dreamer->id)
        ->and(memberIds($this, $actor, 'dach_luxury'))->toContain($dreamer->id);

    recordMarketing($dreamer, false);

    $index = $this->actingAs($actor)->getJson('/api/crm/segments')->assertOk();

    foreach ($index->json('data') as $row) {
        $ids = memberIds($this, $actor, $row['key']);

        if ($row['kind'] === SegmentKind::Marketing->value) {
            expect($ids)->not->toContain($dreamer->id);
        }
    }

    expect(memberIds($this, $actor, 'suppressed'))->toContain($dreamer->id)
        ->and(Suppression::applies($dreamer->fresh() ?? $dreamer))->toBeTrue();
});

test('a withdrawn contact with an active hold stays on the operational hold segment', function (): void {
    $actor = managerUser();
    $holder = Contact::factory()->create(['name' => 'Holding Guest']);
    recordMarketing($holder, true);
    recordMarketing($holder, false);
    BookingRequest::factory()->create([
        'booking_id' => Booking::factory()->state([
            'contact_id' => $holder->id,
            'owner_id' => $actor->id,
            'status' => BookingStatus::Requested,
        ]),
        'hold_expired_at' => null,
    ]);

    expect(memberIds($this, $actor, 'holding_not_paid'))->toContain($holder->id);

    $index = $this->actingAs($actor)->getJson('/api/crm/segments')->assertOk();

    foreach ($index->json('data') as $row) {
        if ($row['kind'] === SegmentKind::Marketing->value) {
            expect(memberIds($this, $actor, $row['key']))->not->toContain($holder->id);
        }
    }
});

test('suppression in php matches the sql for each reason', function (): void {
    $clean = consentedContact(['country' => 'US', 'email' => 'clean@example.com']);
    $never = Contact::factory()->create(['email' => 'never@example.com']);
    $withdrawn = consentedContact(['email' => 'withdrawn@example.com']);
    recordMarketing($withdrawn, false);
    $erased = consentedContact(['email' => 'erased@example.com']);
    ErasureLog::query()->create([
        'contact_id' => $erased->id,
        'email_sha256' => hash('sha256', 'erased@example.com'),
        'erased_at' => now(),
    ]);
    $bounced = consentedContact(['email' => 'bounced@example.com']);
    Delivery::factory()->create([
        'booking_id' => null,
        'to' => ['bounced@example.com'],
        'status' => DeliveryStatus::HardBounce,
    ]);

    foreach ([$never, $withdrawn, $erased, $bounced] as $contact) {
        expect(Suppression::applies($contact))->toBeTrue()
            ->and(Contact::query()->whereKey($contact->id)->whereRaw(Suppression::sql())->exists())->toBeTrue();
    }

    expect(Suppression::applies($clean))->toBeFalse()
        ->and(Contact::query()->whereKey($clean->id)->whereRaw(Suppression::sql())->exists())->toBeFalse();

    $stored = Segment::query()->where('key', 'suppressed')->firstOrFail();
    $compiled = SegmentCompiler::compile($stored->conditions);

    expect($compiled['sql'])->toBe(Suppression::sql())
        ->and($compiled['bindings'])->toBe([]);
});

test('the vocabulary lists every operator the seeded definitions use', function (): void {
    $actor = managerUser();
    $response = $this->actingAs($actor)->getJson('/api/crm/segments/vocabulary')->assertOk();
    assertNoSensitiveFields($response);

    $fields = collect($response->json('data.fields'))->keyBy('field');

    expect($fields->keys()->all())->toContain(
        'event_count',
        'booking_count',
        'booking_status',
        'active_hold',
        'lifecycle',
        'ltv_band',
        'nps',
        'consent',
        'country',
        'guest_age',
        'agency_id',
        'campaign',
        'last_activity_days',
        'erasure',
        'hard_bounce',
        'stay_date',
        'arrival_weekday',
        'length_of_stay',
        'room_type',
        'rate_plan',
    );

    foreach (Segment::query()->get() as $segment) {
        foreach ($segment->conditions['items'] as $item) {
            expect($fields->get($item['field'])['operators'] ?? [])->toContain($item['operator']);
        }
    }
});

test('the segment list query count stays flat as contacts grow', function (): void {
    $actor = managerUser();
    $this->actingAs($actor)->getJson('/api/crm/segments')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($actor)->getJson('/api/crm/segments')->assertOk();
    $before = count(DB::getQueryLog());

    $departure = ReservationFixtures::anamaraDeparture('2027-12-19');

    foreach (['S2', 'S3', 'S4'] as $cabin) {
        $contact = consentedContact();
        stitchedEvent($contact, BehaviouralEventName::ViewProperty);
        booked($contact, $actor, $departure, $cabin, BookingStatus::Confirmed);
    }

    DB::flushQueryLog();
    $this->actingAs($actor)->getJson('/api/crm/segments')->assertOk();

    expect(count(DB::getQueryLog()))->toBe($before);
});

test('staff can create a segment and cannot edit a system one', function (): void {
    $actor = managerUser();

    $created = $this->actingAs($actor)->postJson('/api/crm/segments', [
        'name' => 'Opened the map',
        'sentence' => 'Viewed the route map.',
        'conditions' => [
            'match' => 'all',
            'items' => [
                ['field' => 'event_count', 'operator' => 'gte', 'value' => 1, 'event' => 'view_route_map', 'within_days' => null],
            ],
        ],
        'dimensions' => [
            ['axis' => 'BEHAVIOUR', 'label' => 'route map'],
        ],
        'kind' => 'MARKETING',
        'feeds' => 'Nothing yet',
    ])->assertCreated();

    assertNoSensitiveFields($created);
    expect($created->json('key'))->toBe('opened_the_map')
        ->and($created->json('system'))->toBeFalse();

    $segment = Segment::query()->where('key', 'opened_the_map')->firstOrFail();

    expect(ChangeHistory::query()->where('event', 'segment.created')->where('subject_id', $segment->id)->exists())->toBeTrue();

    $this->actingAs($actor)->patchJson('/api/crm/segments/opened_the_map', [
        'active' => false,
    ])->assertOk()->assertJsonPath('active', false);

    expect(ChangeHistory::query()->where('event', 'segment.updated')->where('subject_id', $segment->id)->exists())->toBeTrue();

    $this->actingAs($actor)->getJson('/api/crm/segments')
        ->assertOk()
        ->assertJsonFragment(['key' => 'opened_the_map', 'active' => false]);

    $this->actingAs($actor)->patchJson('/api/crm/segments/warm_dreamers', [
        'name' => 'Renamed',
    ])->assertStatus(409);

    $this->actingAs($actor)->postJson('/api/crm/segments', [
        'name' => 'Raw',
        'sentence' => 'Not allowed.',
        'conditions' => [
            'match' => 'all',
            'items' => [
                ['field' => 'raw_sql', 'operator' => 'eq', 'value' => '1'],
            ],
        ],
        'dimensions' => [],
        'kind' => 'MARKETING',
        'feeds' => 'None',
    ])->assertStatus(422);
});

test('segment reads need panel.crm and writes need contacts.manage', function (): void {
    $viewer = User::factory()->create([
        'role_id' => Role::factory()->create([
            'permissions' => [Permission::PanelCrm],
        ])->id,
    ]);
    $outsider = User::factory()->create([
        'role_id' => Role::factory()->create([
            'permissions' => [Permission::PanelRms],
        ])->id,
    ]);

    $this->actingAs($outsider)->getJson('/api/crm/segments')->assertForbidden();
    $this->actingAs($viewer)->getJson('/api/crm/segments')->assertOk();
    $this->actingAs($viewer)->postJson('/api/crm/segments', [
        'name' => 'Blocked',
        'sentence' => 'No.',
        'conditions' => [
            'match' => 'all',
            'items' => [
                ['field' => 'country', 'operator' => 'eq', 'value' => 'DE'],
            ],
        ],
        'dimensions' => [],
        'kind' => 'OPERATIONAL',
        'feeds' => 'None',
    ])->assertForbidden();
});

test('the list says when it is capped', function (): void {
    $actor = managerUser();

    for ($i = 0; $i < 42; $i++) {
        Segment::query()->create([
            'key' => 'custom_'.$i,
            'name' => 'Custom '.$i,
            'sentence' => 'A custom audience.',
            'conditions' => [
                'match' => 'all',
                'items' => [
                    ['field' => 'country', 'operator' => 'eq', 'value' => 'ZZ'],
                ],
            ],
            'dimensions' => [],
            'kind' => SegmentKind::Operational,
            'system' => false,
            'active' => true,
            'feeds' => 'None',
        ]);
    }

    $index = $this->actingAs($actor)->getJson('/api/crm/segments')->assertOk();

    expect($index->json('data'))->toHaveCount(50)
        ->and($index->json('data.0.key'))->toBe('warm_dreamers')
        ->and($index->json('meta.capped'))->toBeTrue()
        ->and($index->json('meta.message'))->toBe('The segment list is capped at 50 definitions.');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function consentedContact(array $attributes = []): Contact
{
    $contact = Contact::factory()->create($attributes);
    recordMarketing($contact, true);

    return $contact;
}

function recordMarketing(Contact $contact, bool $granted): void
{
    app(RecordContactConsent::class)->handle(
        $contact,
        ConsentPurpose::Marketing,
        $granted,
        'segments-test',
        ConsentCapturePoint::EngineForm,
    );
}

/**
 * @param  array<string, mixed>  $params
 */
function stitchedEvent(Contact $contact, BehaviouralEventName $name, array $params = []): void
{
    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => $name,
        'params' => $params === [] ? ['page_path' => '/itineraries/western-realm'] : $params,
        'occurred_at' => now(),
        'received_at' => now(),
    ]);
}

function booked(Contact $contact, User $owner, StayAnchor $anchor, string $roomCode, BookingStatus $status): Booking
{
    $stay = $anchor->stayDates();

    return Booking::factory()->create([
        'room_id' => $anchor->property->rooms->firstWhere('code', $roomCode)?->id,
        'property_id' => $anchor->property_id,
        'check_in' => $stay->checkIn()->toDateString(),
        'check_out' => $stay->checkOut()->toDateString(),
        'nights' => 7,
        'contact_id' => $contact->id,
        'owner_id' => $owner->id,
        'status' => $status,
        'total' => 26600,
    ]);
}

/**
 * @return list<int>
 */
function memberIds(mixed $test, User $actor, string $key): array
{
    /** @var TestResponse $response */
    $response = $test->actingAs($actor)->getJson('/api/crm/segments/'.$key.'/contacts?per_page=100');
    $response->assertOk();

    /** @var list<int> $ids */
    $ids = collect($response->json('data'))->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

    return $ids;
}
