<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\CreateStayReservation;
use App\Actions\Crm\CreateUnboundDeal;
use App\Enums\BookingStatus;
use App\Enums\DealStage;
use App\Enums\DealType;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Group;
use App\Models\Payment;
use App\Support\Payments\PaymentsKpis;
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

/**
 * @return array<string, mixed>
 */
function pipelineJson(): array
{
    $response = test()->actingAs(salesExecUser())
        ->getJson('/api/crm/pipeline')
        ->assertOk();

    /** @var array<string, mixed> $json */
    $json = $response->json();

    return $json;
}

/**
 * @param  array<string, mixed>  $json
 * @return list<int>
 */
function pipelineDealIds(array $json, string $stage): array
{
    $column = collect($json['columns'])->firstWhere('stage', $stage);
    $deals = is_array($column) ? ($column['deals'] ?? []) : [];

    return collect($deals)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

test('a request, a reservation, a group and a charter each open one deal', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2026-12-21');

    $request = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
            'client' => ['email' => 'request@iconic.test'],
        ]),
        $actor,
    );

    $reservation = app(CreateStayReservation::class)->handle(
        ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
            'client' => ['email' => 'reservation@iconic.test'],
        ]),
        $actor,
    )->bookings->firstOrFail();

    app(CreateStayReservation::class)->handle(
        ReservationFixtures::createPayload($departure, [
            'rooms' => [
                ['room_type' => 'STD', 'adults' => 2, 'child_ages' => []],
                ['room_type' => 'STD', 'adults' => 2, 'child_ages' => []],
            ],
            'client' => ['email' => 'group@iconic.test'],
            'group' => ['name' => 'Two rooms'],
        ]),
        $actor,
    );

    $charter = app(CreateStayReservation::class)->handle(
        ReservationFixtures::createPayload(ReservationFixtures::anamaraDeparture('2026-12-23'), [
            'type' => 'CHARTER',
            'cabins' => [['adults' => 8, 'children' => 0]],
            'client' => ['email' => 'charter@iconic.test'],
        ]),
        $actor,
    )->bookings->firstOrFail();

    expect(Deal::query()->where('booking_id', $request->id)->count())->toBe(1);
    expect(Deal::query()->where('booking_id', $reservation->id)->value('type'))->toBe(DealType::Fit);
    expect(Deal::query()->whereNotNull('group_id')->count())->toBe(1);
    expect(Deal::query()->where('booking_id', $charter->id)->value('type'))->toBe(DealType::Fit);
    expect(Deal::query()->count())->toBe(4);

    $json = pipelineJson();
    expect(pipelineDealIds($json, DealStage::DepositPending->value))->toHaveCount(4);
});

test('one open deal is bound and none or several open a new bound deal', function (): void {
    $actor = managerUser();
    $owner = salesExecUser();
    $departure = ReservationFixtures::anamaraDeparture('2026-12-21');
    $contact = Contact::factory()->create(['email' => 'open-one@iconic.test', 'name' => 'One Open']);

    $open = app(CreateUnboundDeal::class)->handle(
        $contact,
        $owner,
        'One open',
        DealType::Fit,
        DealStage::Qualifying,
        10000,
        null,
    );

    app(CreateStayReservation::class)->handle(
        ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
            'client' => ['name' => 'One Open', 'email' => 'open-one@iconic.test'],
        ]),
        $actor,
    );

    expect($open->fresh()?->booking_id)->not->toBeNull();
    expect($open->fresh()?->stage)->toBeNull();
    expect(Deal::query()->where('contact_id', $contact->id)->count())->toBe(1);

    $none = Contact::factory()->create(['email' => 'open-none@iconic.test']);
    app(CreateStayReservation::class)->handle(
        ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
            'client' => ['name' => 'None', 'email' => 'open-none@iconic.test'],
        ]),
        $actor,
    );
    expect(Deal::query()->where('contact_id', $none->id)->whereNotNull('booking_id')->count())->toBe(1);

    $several = Contact::factory()->create(['email' => 'open-several@iconic.test']);
    app(CreateUnboundDeal::class)->handle($several, $owner, 'A', DealType::Fit, DealStage::NewLead, 1000, null);
    app(CreateUnboundDeal::class)->handle($several, $owner, 'B', DealType::Fit, DealStage::Quoted, 2000, null);
    app(CreateStayReservation::class)->handle(
        ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S3', 'adults' => 2, 'children' => 0]],
            'client' => ['name' => 'Several', 'email' => 'open-several@iconic.test'],
        ]),
        $actor,
    );

    expect(Deal::query()->where('contact_id', $several->id)->count())->toBe(3);
    expect(Deal::query()->where('contact_id', $several->id)->whereNotNull('booking_id')->count())->toBe(1);
    expect(Deal::query()->where('contact_id', $several->id)->where('stage', DealStage::NewLead)->count())->toBe(1);
});

test('the stage projection follows the booking and a mixed group uses the furthest live status', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-12-05');
    $cabins = $departure->property->rooms;
    $contact = Contact::factory()->create();

    $make = function (BookingStatus $status, string $cabin) use ($cabins, $contact): Deal {
        $booking = Booking::factory()->create([
            'room_id' => $cabins->firstWhere('code', $cabin)?->id,
            'contact_id' => $contact->id,
            'status' => $status,
            'reference' => 'ANK-'.$cabin.'-'.$status->value,
        ]);

        return Deal::query()->create([
            'contact_id' => $contact->id,
            'owner_id' => $booking->owner_id,
            'title' => $status->value,
            'type' => DealType::Fit,
            'stage' => null,
            'stage_entered_at' => now(),
            'booking_id' => $booking->id,
        ]);
    };

    $lost = Deal::query()->create([
        'contact_id' => $contact->id,
        'title' => 'Marked lost',
        'type' => DealType::Fit,
        'stage' => DealStage::Lost,
        'stage_entered_at' => now(),
        'lost_reason' => 'No dates',
    ]);
    $completed = $make(BookingStatus::CheckedOut, 'S1');
    $confirmed = $make(BookingStatus::Confirmed, 'S2');
    $onBoard = $make(BookingStatus::InHouse, 'S3');
    $overdue = $make(BookingStatus::Overdue, 'S4');
    $fullyPaid = $make(BookingStatus::FullyPaid, 'S5');
    $requested = $make(BookingStatus::Requested, 'S6');
    $pending = $make(BookingStatus::PendingPayment, 'S7');
    $held = $make(BookingStatus::OnHoldAgency, 'S8');
    $cancelled = $make(BookingStatus::Cancelled, 'OWNER');
    $releasedDeparture = ReservationFixtures::anamaraDeparture('2027-12-12');
    $releasedBooking = Booking::factory()->create([
        'room_id' => $releasedDeparture->property->rooms->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'status' => BookingStatus::Released,
        'reference' => 'ANK-REL-RELEASED',
    ]);
    $released = Deal::query()->create([
        'contact_id' => $contact->id,
        'owner_id' => $releasedBooking->owner_id,
        'title' => 'Released',
        'type' => DealType::Fit,
        'stage' => null,
        'stage_entered_at' => now(),
        'booking_id' => $releasedBooking->id,
    ]);

    $group = Group::factory()->create(['coordinator_contact_id' => $contact->id]);
    Booking::factory()->create([
        'room_id' => $cabins->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'group_id' => $group->id,
        'status' => BookingStatus::Cancelled,
    ]);
    Booking::factory()->create([
        'room_id' => $cabins->firstWhere('code', 'S2')?->id,
        'contact_id' => $contact->id,
        'group_id' => $group->id,
        'status' => BookingStatus::Requested,
    ]);
    $mixed = Deal::query()->create([
        'contact_id' => $contact->id,
        'title' => 'Mixed',
        'type' => DealType::Group,
        'stage' => null,
        'stage_entered_at' => now(),
        'group_id' => $group->id,
    ]);

    $json = pipelineJson();

    expect(pipelineDealIds($json, 'LOST'))->toContain($lost->id, $cancelled->id, $released->id);
    expect(pipelineDealIds($json, 'WON_COMPLETED'))->toContain($completed->id);
    expect(pipelineDealIds($json, 'BOOKING_CONFIRMED'))->toContain($confirmed->id, $onBoard->id, $overdue->id, $fullyPaid->id);
    expect(pipelineDealIds($json, 'DEPOSIT_PENDING'))->toContain($requested->id, $pending->id, $held->id, $mixed->id);
});

test('moves follow ownership and a bound deal names the booking', function (): void {
    $owner = salesExecUser(['name' => 'Owner']);
    $other = salesExecUser(['name' => 'Other']);
    $admin = adminUser();
    $contact = Contact::factory()->create();

    $dealId = test()->actingAs($owner)
        ->postJson('/api/crm/deals', [
            'contact_id' => $contact->id,
            'title' => 'Fit lead',
            'type' => 'FIT',
            'stage' => 'NEW_LEAD',
            'estimate' => 5000,
        ])
        ->assertOk()
        ->json('id');

    test()->actingAs($other)
        ->patchJson('/api/crm/deals/'.$dealId.'/stage', ['stage' => 'QUALIFYING'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This deal belongs to another owner.');

    test()->actingAs($admin)
        ->patchJson('/api/crm/deals/'.$dealId.'/stage', ['stage' => 'QUALIFYING'])
        ->assertOk()
        ->assertJsonPath('stage', 'QUALIFYING');

    test()->actingAs($owner)
        ->patchJson('/api/crm/deals/'.$dealId.'/stage', ['stage' => 'LOST'])
        ->assertStatus(422);

    test()->actingAs($owner)
        ->patchJson('/api/crm/deals/'.$dealId.'/stage', [
            'stage' => 'LOST',
            'reason' => 'Dates do not work',
        ])
        ->assertOk()
        ->assertJsonPath('stage', 'LOST');

    test()->actingAs($owner)
        ->patchJson('/api/crm/deals/'.$dealId.'/stage', ['stage' => 'NEGOTIATION'])
        ->assertStatus(422);

    test()->actingAs($owner)
        ->patchJson('/api/crm/deals/'.$dealId.'/stage', [
            'stage' => 'NEGOTIATION',
            'reason' => 'They wrote back',
        ])
        ->assertOk()
        ->assertJsonPath('stage', 'NEGOTIATION');

    $departure = ReservationFixtures::anamaraDeparture('2027-12-12');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'reference' => 'ANK-2027-0099',
        'status' => BookingStatus::PendingPayment,
    ]);

    test()->actingAs($owner)
        ->postJson('/api/crm/deals/'.$dealId.'/bind', ['booking_id' => $booking->id])
        ->assertOk()
        ->assertJsonPath('stage', 'DEPOSIT_PENDING')
        ->assertJsonPath('booking.reference', 'ANK-2027-0099');

    test()->actingAs($owner)
        ->patchJson('/api/crm/deals/'.$dealId.'/stage', [
            'stage' => 'LOST',
            'reason' => 'Walking away',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This deal follows booking ANK-2027-0099 — change it in the RMS.');

    expect(ChangeHistory::query()->where('event', 'deal.stage_changed')->where('subject_id', $dealId)->count())->toBeGreaterThan(0);
    expect($booking->fresh()?->status)->toBe(BookingStatus::PendingPayment);
});

test('an unassigned deal must be taken before it can move', function (): void {
    $deal = Deal::query()->create([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => null,
        'title' => 'Unassigned lead',
        'type' => DealType::Fit,
        'stage' => DealStage::NewLead,
        'stage_entered_at' => now(),
    ]);
    $owner = salesExecUser();

    test()->actingAs($owner)
        ->patchJson('/api/crm/deals/'.$deal->id.'/stage', ['stage' => 'QUALIFYING'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Take this deal before moving it.');

    test()->actingAs($owner)
        ->postJson('/api/crm/deals/'.$deal->id.'/assign')
        ->assertOk()
        ->assertJsonPath('owner.id', $owner->id);

    expect($deal->fresh()?->stage)->toBe(DealStage::NewLead);
    expect(ChangeHistory::query()->where('event', 'deal.assigned')->where('subject_id', $deal->id)->exists())->toBeTrue();
});

test('pipeline cash matches payments and revenue and the query count stays flat', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-12-19');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'amount' => 2660,
        'status' => PaymentStatus::Settled,
    ]);

    $json = pipelineJson();
    $ledger = PaymentsKpis::ledger();
    $kpis = $json['meta']['kpis'];

    expect($kpis['collected'])->toBe($ledger['collected']);
    expect($kpis['awaiting_first_payment'])->toBe($ledger['pending']);
    expect($kpis['overdue'])->toBe($ledger['overdue_amount']);
    expect($kpis['collected'])->toBe(2660);

    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->actingAs(salesExecUser())->getJson('/api/crm/pipeline')->assertOk();
    $before = count(DB::getQueryLog());

    Deal::query()->create([
        'contact_id' => Contact::factory()->create()->id,
        'title' => 'Extra',
        'type' => DealType::Fit,
        'stage' => DealStage::NewLead,
        'stage_entered_at' => now(),
        'estimate' => 1000,
    ]);

    DB::flushQueryLog();
    test()->actingAs(salesExecUser())->getJson('/api/crm/pipeline')->assertOk();
    expect(count(DB::getQueryLog()))->toBe($before);
});

test('merge and unmerge move the deal', function (): void {
    $survivor = Contact::factory()->create(['name' => 'Older', 'email' => null]);
    $loser = Contact::factory()->create(['name' => 'Newer', 'email' => 'deal-merge@iconic.test']);
    $owner = salesExecUser();
    $deal = app(CreateUnboundDeal::class)->handle($loser, $owner, 'Merge me', DealType::Fit, DealStage::NewLead, 1000, null);
    $actor = managerUser();

    $merged = test()->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$loser->id.'/merge', [
            'contact_id' => $survivor->id,
            'reason' => 'Same person',
        ])
        ->assertOk();

    expect($deal->fresh()?->contact_id)->toBe($survivor->id);

    test()->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$merged->json('merge.id').'/undo', [
            'reason' => 'Not the same',
        ])
        ->assertOk();

    expect($deal->fresh()?->contact_id)->toBe($loser->id);
});

test('deal history appears on the contact timeline', function (): void {
    $owner = salesExecUser();
    $contact = Contact::factory()->create();
    $deal = app(CreateUnboundDeal::class)->handle($contact, $owner, 'Timeline', DealType::Fit, DealStage::NewLead, null, null);

    test()->actingAs($owner)
        ->patchJson('/api/crm/deals/'.$deal->id.'/stage', [
            'stage' => 'LOST',
            'reason' => 'Not this year',
        ])
        ->assertOk();

    $titles = collect(test()->actingAs($owner)
        ->getJson('/api/crm/contacts/'.$contact->id.'/timeline')
        ->assertOk()
        ->json('data'))
        ->pluck('title');

    expect($titles)->toContain('Deal opened', 'Marked lost');
});

test('the stage map is the implemented doc 07 table', function (): void {
    $response = test()->actingAs(salesExecUser())
        ->getJson('/api/crm/pipeline/stage-map')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(8);
    expect($response->json('data.2.stage'))->toBe('QUOTED');
    expect($response->json('data.5.rms_statuses'))->toContain('IN_HOUSE', 'OVERDUE');
});
