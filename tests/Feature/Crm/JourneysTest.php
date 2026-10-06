<?php

declare(strict_types=1);

use App\Actions\Contacts\ResolveContact;
use App\Actions\Crm\RecordContactConsent;
use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentPurpose;
use App\Enums\DealStage;
use App\Enums\DealType;
use App\Enums\DeliveryKind;
use App\Enums\JourneyEnrolmentStatus;
use App\Enums\TaskKind;
use App\Events\AgencyApproved;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Events\DealMarkedLost;
use App\Events\HoldExpired;
use App\Models\Agency;
use App\Models\AutomationSetting;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\ErasureLog;
use App\Models\Guest;
use App\Models\Journey;
use App\Models\JourneyEnrolment;
use App\Models\Payment;
use App\Models\RoomNightClaim;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('each journey trigger enrols once', function (): void {
    $owner = salesExecUser();

    activate('nurture_to_request');
    $nurture = journeyContact();
    BehaviouralEvent::factory()->create([
        'contact_id' => $nurture->id,
        'name' => BehaviouralEventName::AbandonCart,
    ]);
    $this->artisan('iconic:journeys')->assertSuccessful();
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(enrolmentCount('nurture_to_request', $nurture))->toBe(1);

    activate('request_to_deposit');
    $requestContact = journeyContact();
    $requested = journeyBooking($requestContact, $owner, BookingStatus::Requested);
    BookingCreated::dispatch($requested);
    BookingCreated::dispatch($requested->fresh() ?? $requested);
    expect(enrolmentCount('request_to_deposit', $requestContact))->toBe(1);

    activate('payment_calendar');
    $confirmedContact = journeyContact();
    $confirmed = journeyBooking($confirmedContact, $owner, BookingStatus::Confirmed, '2027-11-14');
    BookingStatusChanged::dispatch($confirmed, BookingStatus::Requested, BookingStatus::Confirmed);
    BookingStatusChanged::dispatch($confirmed, BookingStatus::Requested, BookingStatus::Confirmed);
    expect(enrolmentCount('payment_calendar', $confirmedContact))->toBe(1);

    activate('reengagement');
    $past = journeyContact();
    $completed = journeyBooking($past, $owner, BookingStatus::CheckedOut, '2027-11-21', 1000);
    BookingStatusChanged::dispatch($completed, BookingStatus::FullyPaid, BookingStatus::CheckedOut);
    BookingStatusChanged::dispatch($completed, BookingStatus::FullyPaid, BookingStatus::CheckedOut);
    expect(enrolmentCount('reengagement', $past))->toBe(1)
        ->and(enrolment('reengagement', $past)->status)->toBe(JourneyEnrolmentStatus::Active);

    activate('b2b_partner_activation');
    $partner = journeyContact();
    $agency = Agency::factory()->create(['email' => $partner->email]);
    AgencyApproved::dispatch($agency);
    AgencyApproved::dispatch($agency->fresh() ?? $agency);
    $partnerBooking = journeyBooking($partner, $owner, BookingStatus::Confirmed, '2027-11-28');
    $partnerBooking->agency_id = $agency->id;
    $partnerBooking->save();
    BookingCreated::dispatch($partnerBooking);
    expect(enrolmentCount('b2b_partner_activation', $partner))->toBe(1);

    activate('winback');
    $lost = journeyContact();
    $held = journeyBooking($lost, $owner, BookingStatus::Requested, '2027-12-05');
    HoldExpired::dispatch($held, new RoomNightClaim);
    $held->status = BookingStatus::Cancelled;
    $held->save();
    BookingStatusChanged::dispatch($held, BookingStatus::Requested, BookingStatus::Cancelled);
    $deal = Deal::query()->create([
        'contact_id' => $lost->id,
        'title' => 'Lost dates',
        'type' => DealType::Fit,
        'stage' => DealStage::Lost,
        'stage_entered_at' => now(),
        'lost_reason' => 'Dates',
    ]);
    DealMarkedLost::dispatch($deal);
    expect(enrolmentCount('winback', $lost))->toBe(1);
});

test('a send step waits, sends once, and completes', function (): void {
    activate('winback');
    $contact = journeyContact();
    $held = journeyBooking($contact, salesExecUser(), BookingStatus::Requested, '2027-12-12');
    HoldExpired::dispatch($held, new RoomNightClaim);

    $enrolment = enrolment('winback', $contact);
    expect($enrolment->status)->toBe(JourneyEnrolmentStatus::Active);

    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(0)
        ->and($enrolment->fresh()?->position)->toBe(1);

    travelToDue($enrolment);
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(1)
        ->and($enrolment->fresh()?->position)->toBe(2);

    travelToDue($enrolment);
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(2);

    travelToDue($enrolment);
    $this->artisan('iconic:journeys')->assertSuccessful();
    $this->artisan('iconic:journeys')->assertSuccessful();

    expect(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(3)
        ->and($enrolment->fresh()?->status)->toBe(JourneyEnrolmentStatus::Completed);
});

test('withdrawing marketing consent stops the next nurture step and not a transactional step', function (): void {
    activate('nurture_to_request');
    $contact = journeyContact();
    JourneyEnrolment::onLeadCaptured($contact);
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(1);

    grantMarketing($contact, false);
    $nurture = enrolment('nurture_to_request', $contact);
    travelToDue($nurture);
    $this->artisan('iconic:journeys')->assertSuccessful();

    expect($nurture->fresh()?->status)->toBe(JourneyEnrolmentStatus::Suppressed)
        ->and($nurture->fresh()?->exit_reason)->toBe('Marketing consent withdrawn.')
        ->and(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(1);

    activate('request_to_deposit');
    $buyer = journeyContact();
    grantMarketing($buyer, false);
    $booking = journeyBooking($buyer, salesExecUser(), BookingStatus::Requested, '2027-12-19');
    BookingCreated::dispatch($booking);
    $this->artisan('iconic:journeys')->assertSuccessful();

    expect(enrolment('request_to_deposit', $buyer)->status)->toBe(JourneyEnrolmentStatus::Active)
        ->and(Delivery::query()->where('booking_id', $booking->id)->where('kind', DeliveryKind::Journey)->count())->toBe(1);
});

test('a new booking exits nurture before a due step is sent', function (): void {
    activate('nurture_to_request');
    $contact = journeyContact();
    JourneyEnrolment::onLeadCaptured($contact);
    $booking = journeyBooking($contact, salesExecUser(), BookingStatus::Requested, '2027-12-26');
    BookingCreated::dispatch($booking);

    $this->artisan('iconic:journeys')->assertSuccessful();

    expect(enrolment('nurture_to_request', $contact)->status)->toBe(JourneyEnrolmentStatus::Exited)
        ->and(enrolment('nurture_to_request', $contact)->exit_reason)->toBe('booking.created')
        ->and(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(0);
});

test('a switched-off journey send stays put and a pointer still advances', function (): void {
    activate('nurture_to_request');
    AutomationSetting::query()->create([
        'key' => 'welcome_web_lead',
        'enabled' => false,
        'disabled_reason' => 'Paused',
        'disabled_at' => now(),
    ]);
    $contact = journeyContact();
    JourneyEnrolment::onLeadCaptured($contact);
    $this->artisan('iconic:journeys')->assertSuccessful();
    $this->artisan('iconic:journeys')->assertSuccessful();

    $nurture = enrolment('nurture_to_request', $contact);
    expect($nurture->status)->toBe(JourneyEnrolmentStatus::Active)
        ->and($nurture->position)->toBe(1)
        ->and(Delivery::query()->count())->toBe(0)
        ->and(ChangeHistory::query()->where('event', 'automation.skipped')->count())->toBe(1);

    activate('payment_calendar');
    AutomationSetting::query()->create([
        'key' => 'balance_reminder_21',
        'enabled' => false,
        'disabled_reason' => 'Paused',
        'disabled_at' => now(),
    ]);
    $buyer = journeyContact();
    $booking = journeyBooking($buyer, salesExecUser(), BookingStatus::Confirmed, '2028-01-02');
    BookingStatusChanged::dispatch($booking, BookingStatus::Requested, BookingStatus::Confirmed);
    $calendar = enrolment('payment_calendar', $buyer);
    $calendar->next_due_at = now()->subMinute();
    $calendar->save();

    $this->artisan('iconic:journeys')->assertSuccessful();

    $calendar->refresh();
    expect($calendar->status)->toBe(JourneyEnrolmentStatus::Active)
        ->and($calendar->position)->toBe(2)
        ->and($calendar->sends)->toHaveCount(1)
        ->and($calendar->sends->first()?->catalogue_key)->toBe('balance_reminder_21')
        ->and($calendar->sends->first()?->delivery_id)->toBeNull();
});

test('an inactive journey refuses enrolment and does not send', function (): void {
    $contact = journeyContact();
    JourneyEnrolment::onLeadCaptured($contact);
    expect(JourneyEnrolment::query()->count())->toBe(0);

    activate('winback');
    $held = journeyBooking($contact, salesExecUser(), BookingStatus::Requested, '2028-01-09');
    HoldExpired::dispatch($held, new RoomNightClaim);
    $pending = enrolment('winback', $contact);
    $pending->next_due_at = now()->subMinute();
    $pending->save();
    Journey::query()->where('key', 'winback')->update(['active' => false]);

    $this->artisan('iconic:journeys')->assertSuccessful();

    $row = enrolment('winback', $contact);
    expect($row->status)->toBe(JourneyEnrolmentStatus::Active)
        ->and(Delivery::query()->count())->toBe(0);

    HoldExpired::dispatch($held, new RoomNightClaim);
    expect(enrolmentCount('winback', $contact))->toBe(1);
});

test('a runner pass does not change bookings, payments, guests or documents', function (): void {
    activate('request_to_deposit');
    $booking = journeyBooking(journeyContact(), salesExecUser(), BookingStatus::Requested, '2028-01-16');
    BookingCreated::dispatch($booking);

    $before = ledgerStamp();
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(1)
        ->and(ledgerStamp())->toBe($before);
});

test('the request handover task is raised once', function (): void {
    activate('request_to_deposit');
    $booking = journeyBooking(journeyContact(), salesExecUser(), BookingStatus::Requested, '2028-01-23');
    BookingCreated::dispatch($booking);
    $enrolment = JourneyEnrolment::query()->where('booking_id', $booking->id)->firstOrFail();

    Carbon::setTestNow($enrolment->enrolled_at->copy()->addHours(4));
    $this->artisan('iconic:journeys')->assertSuccessful();
    $this->artisan('iconic:journeys')->assertSuccessful();

    expect(CrmTask::query()->where('kind', TaskKind::JourneyHandover)->count())->toBe(1)
        ->and(CrmTask::query()->where('kind', TaskKind::JourneyHandover)->value('idempotency_key'))
        ->toBe('journey-handover:'.$enrolment->id.':'.$enrolment->journey->steps()->where('position', 2)->value('id'));
});

test('crm can list journeys, enrolments and toggle the active flag', function (): void {
    $crm = salesExecUser();
    $admin = adminUser();

    $index = $this->actingAs($crm)->getJson('/api/crm/journeys')->assertOk();
    assertNoSensitiveFields($index);
    expect($index->json('data'))->toHaveCount(6)
        ->and($index->json('data.0.key'))->toBe('nurture_to_request')
        ->and($index->json('data.0.kind'))->toBe('MARKETING')
        ->and($index->json('data.0.suppression_sentence'))->toBe(Journey::SUPPRESSION_SENTENCE)
        ->and($index->json('data.0.active'))->toBeFalse()
        ->and($index->json('data.0.steps.0.count'))->toBe(0)
        ->and(collect($index->json('data'))->firstWhere('key', 'payment_calendar')['steps'][0]['action'])->toBe('pointer');

    $this->actingAs($crm)->patchJson('/api/crm/journeys/winback', ['active' => true])->assertForbidden();

    $patched = $this->actingAs($admin)->patchJson('/api/crm/journeys/winback', ['active' => true])->assertOk();
    assertNoSensitiveFields($patched);
    expect($patched->json('active'))->toBeTrue()
        ->and(ChangeHistory::query()->where('event', 'journey.updated')->count())->toBe(1);

    $this->actingAs($admin)->patchJson('/api/crm/journeys/winback', ['active' => true])->assertOk();
    expect(ChangeHistory::query()->where('event', 'journey.updated')->count())->toBe(1);

    $contact = journeyContact();
    $held = journeyBooking($contact, $crm, BookingStatus::Requested, '2028-01-30');
    HoldExpired::dispatch($held, new RoomNightClaim);

    $enrolments = $this->actingAs($crm)->getJson('/api/crm/journeys/winback/enrolments')->assertOk();
    assertNoSensitiveFields($enrolments);
    expect($enrolments->json('data'))->toHaveCount(1)
        ->and($enrolments->json('data.0.contact.id'))->toBe($contact->id)
        ->and($enrolments->json('data.0.booking.id'))->toBe($held->id)
        ->and($enrolments->json('data.0.status'))->toBe('ACTIVE');

    $mine = $this->actingAs($crm)->getJson('/api/crm/contacts/'.$contact->id.'/journeys')->assertOk();
    assertNoSensitiveFields($mine);
    expect($mine->json('data.0.journey_key'))->toBe('winback')
        ->and($mine->json('data.0.sends'))->toBeArray();
});

test('the abandoned checkout branch sends at 24 hours, 48 hours and day 7', function (): void {
    activate('nurture_to_request');

    $started = journeyContact();
    BehaviouralEvent::factory()->create([
        'contact_id' => $started->id,
        'name' => BehaviouralEventName::BeginCheckout,
    ]);
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(JourneyEnrolment::query()->where('contact_id', $started->id)->where('branch', 'abandoned_checkout')->count())->toBe(0);

    $unticked = Contact::factory()->create();
    BehaviouralEvent::factory()->create([
        'contact_id' => $unticked->id,
        'name' => BehaviouralEventName::AbandonCart,
    ]);
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(enrolmentCount('nurture_to_request', $unticked))->toBe(0);

    $contact = journeyContact();
    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => BehaviouralEventName::AbandonCart,
    ]);
    $this->artisan('iconic:journeys')->assertSuccessful();
    $this->artisan('iconic:journeys')->assertSuccessful();

    $enrolment = JourneyEnrolment::query()
        ->where('contact_id', $contact->id)
        ->where('branch', 'abandoned_checkout')
        ->firstOrFail();

    expect($enrolment->status)->toBe(JourneyEnrolmentStatus::Active)
        ->and(JourneyEnrolment::query()->where('contact_id', $contact->id)->where('branch', 'lead')->count())->toBe(0)
        ->and(Delivery::query()->count())->toBe(0);

    travelToDue($enrolment);
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(Delivery::query()->count())->toBe(1)
        ->and(Delivery::query()->value('subject'))->toBe('Can we help you plan your Galápagos expedition?')
        ->and($enrolment->fresh()?->position)->toBe(2);

    travelToDue($enrolment);
    $this->artisan('iconic:journeys')->assertSuccessful();
    expect(Delivery::query()->orderBy('id')->pluck('subject')->all())->toBe([
        'Can we help you plan your Galápagos expedition?',
        'Still dreaming of Galápagos? We are here to help.',
    ])->and($enrolment->fresh()?->position)->toBe(3);

    travelToDue($enrolment);
    $this->artisan('iconic:journeys')->assertSuccessful();
    $this->artisan('iconic:journeys')->assertSuccessful();

    expect(Delivery::query()->orderBy('id')->pluck('subject')->all())->toBe([
        'Can we help you plan your Galápagos expedition?',
        'Still dreaming of Galápagos? We are here to help.',
        'Can we help plan your trip?',
    ])->and($enrolment->fresh()?->status)->toBe(JourneyEnrolmentStatus::Completed);

    $stopped = journeyContact();
    BehaviouralEvent::factory()->create([
        'contact_id' => $stopped->id,
        'name' => BehaviouralEventName::AbandonCart,
    ]);
    $this->artisan('iconic:journeys')->assertSuccessful();
    $row = JourneyEnrolment::query()
        ->where('contact_id', $stopped->id)
        ->where('branch', 'abandoned_checkout')
        ->firstOrFail();
    travelToDue($row);
    $this->artisan('iconic:journeys')->assertSuccessful();
    $sent = Delivery::query()->count();

    grantMarketing($stopped, false);
    travelToDue($row);
    $this->artisan('iconic:journeys')->assertSuccessful();

    expect($row->fresh()?->status)->toBe(JourneyEnrolmentStatus::Suppressed)
        ->and($row->fresh()?->exit_reason)->toBe('Marketing consent withdrawn.')
        ->and(Delivery::query()->count())->toBe($sent);
});

test('an erased email is not enrolled on a marketing journey and a transactional journey still enrols', function (): void {
    $email = 'erased-again@iconic.test';
    $gone = Contact::factory()->create(['email' => $email]);
    ErasureLog::query()->create([
        'contact_id' => $gone->id,
        'email_sha256' => hash('sha256', $email),
        'erased_at' => now(),
    ]);
    $gone->forceFill(['email' => null])->save();

    $again = app(ResolveContact::class)->handle([
        'name' => 'Returned',
        'email' => $email,
    ]);
    grantMarketing($again, true);

    activate('nurture_to_request');
    JourneyEnrolment::onLeadCaptured($again);
    expect(enrolmentCount('nurture_to_request', $again))->toBe(0);

    activate('request_to_deposit');
    $booking = journeyBooking($again, salesExecUser(), BookingStatus::Requested, '2028-02-06');
    BookingCreated::dispatch($booking);

    expect(enrolment('request_to_deposit', $again)->status)->toBe(JourneyEnrolmentStatus::Active);
});

function activate(string $key): void
{
    Journey::query()->where('key', $key)->update(['active' => true]);
}

function journeyContact(): Contact
{
    $contact = Contact::factory()->create();
    grantMarketing($contact, true);

    return $contact;
}

function grantMarketing(Contact $contact, bool $granted): void
{
    app(RecordContactConsent::class)->handle(
        $contact,
        ConsentPurpose::Marketing,
        $granted,
        'journey-test',
        ConsentCapturePoint::EngineForm,
    );
}

function journeyBooking(Contact $contact, User $owner, BookingStatus $status, string $date = '2027-11-07', int $total = 26600): Booking
{
    $departure = ReservationFixtures::anamaraDeparture($date);
    $cabin = $departure->property->rooms->firstOrFail();

    return Booking::factory()->create([
        'room_id' => $cabin->id,
        'contact_id' => $contact->id,
        'owner_id' => $owner->id,
        'status' => $status,
        'total' => $total,
        'price_lines' => [
            ['code' => 'base', 'label' => '2 adults', 'amount' => $total],
        ],
    ]);
}

function enrolment(string $key, Contact $contact): JourneyEnrolment
{
    return JourneyEnrolment::query()
        ->where('contact_id', $contact->id)
        ->whereHas('journey', fn ($query) => $query->where('key', $key))
        ->firstOrFail();
}

function enrolmentCount(string $key, Contact $contact): int
{
    return JourneyEnrolment::query()
        ->where('contact_id', $contact->id)
        ->whereHas('journey', fn ($query) => $query->where('key', $key))
        ->count();
}

function travelToDue(JourneyEnrolment $enrolment): void
{
    $enrolment->refresh();
    $due = $enrolment->next_due_at;

    if ($due !== null) {
        Carbon::setTestNow($due);
    }
}

/**
 * @return array{bookings: list<array<string, mixed>>, payments: list<array<string, mixed>>, guests: list<array<string, mixed>>, documents: list<array<string, mixed>>}
 */
function ledgerStamp(): array
{
    $stamp = function (string $model): array {
        /** @var class-string<Booking|Payment|Guest|Document> $model */
        return $model::query()->orderBy('id')->get(['id', 'updated_at'])
            ->map(fn ($row): array => [
                'id' => $row->id,
                'updated_at' => $row->updated_at?->toISOString(),
            ])->all();
    };

    return [
        'bookings' => $stamp(Booking::class),
        'payments' => $stamp(Payment::class),
        'guests' => $stamp(Guest::class),
        'documents' => $stamp(Document::class),
    ];
}
