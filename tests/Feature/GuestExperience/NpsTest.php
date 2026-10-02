<?php

declare(strict_types=1);

use App\Actions\Crm\RecordContactConsent;
use App\Actions\GuestExperience\RecordGuestResponse;
use App\Enums\AlertKind;
use App\Enums\BookingAccessTokenPurpose;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentPurpose;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\GuestResponseSource;
use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Mail\Alerts\AlertMail;
use App\Mail\Documents\ReviewRequestMail;
use App\Mail\Documents\SurveyMail;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\CrmTask;
use App\Models\Delivery;
use App\Models\Departure;
use App\Models\Guest;
use App\Models\GuestResponse;
use App\Models\Role;
use App\Models\SubjectRequest;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Crm\TaskDue;
use App\Support\GuestExperience\SurveyAnswers;
use App\Support\Schedule\JobCatalogue;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;
use ZipArchive;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{departure: Departure, booking: Booking, contact: Contact, lead: Guest, companion: Guest}
 */
function npsBooking(
    string $date,
    string $reference,
    User $owner,
    BookingStatus $status = BookingStatus::Completed,
    string $contactEmail = 'ada@example.com',
    ?string $companionEmail = null,
    ?Contact $existingContact = null,
): array {
    $departure = ReservationFixtures::anamaraDeparture($date);
    $contact = $existingContact ?? Contact::factory()->create(['email' => $contactEmail]);
    $leadEmail = $existingContact instanceof Contact ? (string) $existingContact->email : $contactEmail;
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'contact_id' => $contact->id,
        'owner_id' => $owner->id,
        'status' => $status,
        'reference' => $reference,
    ]);
    $lead = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => $leadEmail,
    ]);
    $companion = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'Bea',
        'last_name' => 'Lovelace',
        'email' => $companionEmail,
    ]);

    return compact('departure', 'booking', 'contact', 'lead', 'companion');
}

function npsSendSurveys(Booking $booking): void
{
    $booking->loadMissing('departure.itinerary');
    $hours = app(CurrentConfig::class)->businessRules()->nps->surveyHoursAfterReturn;
    $due = BusinessTime::calendarDay($booking->departure->returnDate()->toDateString())->addHours($hours);
    test()->travelTo($due->addMinute());
    test()->artisan('iconic:nps-survey')->assertSuccessful();
}

function npsPlainToken(Booking $booking, ?int $guestId): string
{
    $query = BookingAccessToken::query()
        ->where('booking_id', $booking->id)
        ->where('purpose', BookingAccessTokenPurpose::Survey);

    if ($guestId === null) {
        $query->whereNull('guest_id');
    } else {
        $query->where('guest_id', $guestId);
    }

    return basename((string) $query->firstOrFail()->page_url);
}

/**
 * @return array{score: int, rec: int|null, why: string, best: string, better: string, crew: string}
 */
function npsAnswerBody(int $score, string $why = 'the wildlife', ?int $recommend = 9): array
{
    return [
        'score' => $score,
        'rec' => $recommend,
        'why' => $why,
        'best' => 'the landing',
        'better' => 'more time ashore',
        'crew' => 'the guide',
    ];
}

function npsGrantMarketing(Contact $contact, User $actor): void
{
    app(RecordContactConsent::class)->handle(
        $contact,
        ConsentPurpose::Marketing,
        granted: true,
        version: 'v1',
        capturePoint: ConsentCapturePoint::Staff,
        recordedBy: $actor,
        howObtained: 'the guest asked for news by email',
    );
}

function npsHistoryWhat(Booking $booking): string
{
    $history = ChangeHistory::query()
        ->where('event', 'booking.nps_recorded')
        ->where('subject_id', $booking->id)
        ->orderByDesc('id')
        ->first();

    $what = $history?->after['what'] ?? null;

    return is_string($what) ? $what : '';
}

test('completing a voyage raises one post-trip call and a replay does not raise another', function (): void {
    $owner = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2026-06-07');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'owner_id' => $owner->id,
        'status' => BookingStatus::FullyPaid,
        'reference' => 'ANK-NPS-CALL',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00', BusinessTime::zone()));
    $this->artisan('iconic:voyage-status')->assertSuccessful();
    $this->artisan('iconic:voyage-status')->assertSuccessful();

    $tasks = CrmTask::query()->where('kind', TaskKind::PostTripCall)->get();
    $task = $tasks->first();
    $return = $departure->fresh()?->returnDate()->toDateString() ?? $departure->returnDate()->toDateString();
    $expectedDue = TaskDue::businessDays(
        BusinessTime::calendarDay($return),
        2,
        app(CurrentConfig::class)->businessRules(),
    );

    expect($booking->fresh()?->status)->toBe(BookingStatus::Completed)
        ->and($tasks)->toHaveCount(1)
        ->and($task?->idempotency_key)->toBe('post-trip-call:'.$booking->id)
        ->and($task?->owner_id)->toBe($owner->id)
        ->and($task?->booking_id)->toBe($booking->id)
        ->and($task?->needs_permission?->value)->toBe('guest_experience.manage')
        ->and($task?->due_at?->utc()->format('Y-m-d H:i:s'))->toBe($expectedDue->utc()->format('Y-m-d H:i:s'));
});

test('the survey waits until return plus the configured hours, sends once, and does not check consent', function (): void {
    $owner = managerUser();
    $fixture = npsBooking('2028-09-03', 'ANK-NPS-SURVEY', $owner, companionEmail: null);
    $return = $fixture['departure']->returnDate()->toDateString();
    $due = BusinessTime::calendarDay($return)->addHours(24);

    expect(ContactConsent::query()->count())->toBe(0);

    $this->travelTo($due->subHour());
    $this->artisan('iconic:nps-survey')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Survey)->count())->toBe(0);

    $this->travelTo($due->addMinute());
    $this->artisan('iconic:nps-survey')->assertSuccessful();

    $own = Delivery::query()->where('idempotency_key', 'survey:'.$fixture['lead']->id)->first();
    $shared = Delivery::query()->where('idempotency_key', 'survey:'.$fixture['booking']->id.':lead')->first();
    $ownToken = BookingAccessToken::query()
        ->where('guest_id', $fixture['lead']->id)
        ->where('purpose', BookingAccessTokenPurpose::Survey)
        ->first();
    $leadToken = BookingAccessToken::query()
        ->where('booking_id', $fixture['booking']->id)
        ->whereNull('guest_id')
        ->where('purpose', BookingAccessTokenPurpose::Survey)
        ->first();
    $expiresOn = BusinessTime::calendarDay($return)->addDays(60)->toDateString();

    expect($own)->not->toBeNull()
        ->and($own?->kind)->toBe(DeliveryKind::Survey)
        ->and($own?->status)->toBe(DeliveryStatus::Sent)
        ->and($own?->to)->toBe(['ada@example.com'])
        ->and($shared)->not->toBeNull()
        ->and($shared?->status)->toBe(DeliveryStatus::Sent)
        ->and($shared?->to)->toBe(['ada@example.com'])
        ->and($ownToken?->purpose)->toBe(BookingAccessTokenPurpose::Survey)
        ->and($ownToken?->covered_guest_ids)->toBe([$fixture['lead']->id])
        ->and($ownToken?->expires_at?->utc()->format('Y-m-d H:i:s'))->toBe(
            BusinessTime::dayEndUtc($expiresOn)->format('Y-m-d H:i:s'),
        )
        ->and(str_contains((string) $ownToken?->page_url, '/survey/'))->toBeTrue()
        ->and($leadToken?->covered_guest_ids)->toBe([$fixture['companion']->id])
        ->and(Delivery::query()->where('kind', DeliveryKind::Survey)->count())->toBe(2);

    Mail::assertSent(SurveyMail::class, 2);

    $this->artisan('iconic:nps-survey')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Survey)->count())->toBe(2);

    expect(collect(JobCatalogue::rows())->pluck('command')->all())->not->toContain('iconic:nps-survey');
    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('iconic:nps-survey');
});

test('guests with no usable lead address produce one blocked survey delivery', function (): void {
    $owner = managerUser();
    $fixture = npsBooking('2028-09-10', 'ANK-NPS-BLOCK', $owner, contactEmail: 'blocked-lead@example.com', companionEmail: null);
    $fixture['lead']->forceFill(['email' => null])->save();
    $fixture['contact']->forceFill(['email' => null])->save();

    npsSendSurveys($fixture['booking']);

    $blocked = Delivery::query()->where('kind', DeliveryKind::Survey)->get();

    expect($blocked)->toHaveCount(1)
        ->and($blocked->first()?->status)->toBe(DeliveryStatus::Blocked)
        ->and($blocked->first()?->idempotency_key)->toBe('survey:'.$fixture['booking']->id.':lead');
    Mail::assertNothingSent();
});

test('the survey link covers only its guests and rejects the wrong purpose, an expiry and a revocation', function (): void {
    $owner = managerUser();
    $fixture = npsBooking('2028-09-17', 'ANK-NPS-LINK', $owner, companionEmail: null);
    npsSendSurveys($fixture['booking']);

    $leadToken = npsPlainToken($fixture['booking'], null);
    $ownToken = npsPlainToken($fixture['booking'], $fixture['lead']->id);
    $return = $fixture['departure']->returnDate()->toDateString();

    $this->getJson('/api/engine/survey/'.$leadToken)
        ->assertOk()
        ->assertJsonPath('reference', 'ANK-NPS-LINK')
        ->assertJsonPath('departure_date', $fixture['departure']->date->toDateString())
        ->assertJsonPath('guests.0.id', $fixture['companion']->id)
        ->assertJsonPath('guests.0.responded', false)
        ->assertJsonMissingPath('guests.0.why');

    $this->postJson('/api/engine/survey/'.$leadToken.'/guests/'.$fixture['companion']->id, npsAnswerBody(7, 'companion note'))
        ->assertOk()
        ->assertJsonPath('guests.0.responded', true)
        ->assertJsonMissing(['why' => 'companion note']);

    $this->postJson('/api/engine/survey/'.$leadToken.'/guests/'.$fixture['lead']->id, npsAnswerBody(8))
        ->assertNotFound();
    $this->postJson('/api/engine/survey/'.$ownToken.'/guests/'.$fixture['companion']->id, npsAnswerBody(8))
        ->assertNotFound();

    $this->postJson('/api/engine/survey/'.$leadToken.'/guests/'.$fixture['companion']->id, npsAnswerBody(7))
        ->assertStatus(409)
        ->assertJsonPath('message', 'This guest already has a response for this voyage.');

    $wrong = BookingAccessToken::query()->create([
        'booking_id' => $fixture['booking']->id,
        'guest_id' => $fixture['lead']->id,
        'token_hash' => BookingAccessToken::hashToken('questionnaire-plain-token'),
        'purpose' => BookingAccessTokenPurpose::Questionnaire,
        'covered_guest_ids' => [$fixture['lead']->id],
        'expires_at' => now()->addDay(),
        'page_url' => 'http://localhost:3000/questionnaire/questionnaire-plain-token',
    ]);
    expect($wrong->id)->toBeInt();
    $this->getJson('/api/engine/survey/questionnaire-plain-token')->assertNotFound();

    $expiresOn = BusinessTime::calendarDay($return)->addDays(60)->toDateString();
    $this->travelTo(BusinessTime::dayEndUtc($expiresOn)->addSecond());
    $this->getJson('/api/engine/survey/'.$ownToken)->assertNotFound();

    $this->travelTo(CarbonImmutable::parse('2028-09-20 12:00:00', BusinessTime::zone()));
    BookingAccessToken::query()->where('guest_id', $fixture['lead']->id)->update(['revoked_at' => now()]);
    $this->getJson('/api/engine/survey/'.$ownToken)->assertNotFound();
});

test('a score below the threshold alerts guest experience and completing the reply task resolves the alert', function (): void {
    $manager = managerUser(['email' => 'nps-gx@iconic.test']);
    $fixture = npsBooking('2028-10-02', 'ANK-NPS-LOW', $manager, contactEmail: 'low-score@example.com');
    npsSendSurveys($fixture['booking']);
    $token = npsPlainToken($fixture['booking'], $fixture['lead']->id);

    $this->postJson('/api/engine/survey/'.$token.'/guests/'.$fixture['lead']->id, npsAnswerBody(6, 'too rough', 4))
        ->assertOk();

    $task = CrmTask::query()->where('kind', TaskKind::NpsReply)->first();
    $alert = Alert::query()->where('kind', AlertKind::NpsLow)->first();

    expect($task)->not->toBeNull()
        ->and($task?->status)->toBe(TaskStatus::Open)
        ->and($alert)->not->toBeNull()
        ->and($alert?->crm_task_id)->toBe($task?->id)
        ->and($alert?->guest_response_id)->not->toBeNull()
        ->and(npsHistoryWhat($fixture['booking']))->toContain('Post-trip survey recorded — score 6')
        ->and(npsHistoryWhat($fixture['booking']))->toContain('alert sent to guest experience');

    $this->artisan('iconic:alerts')->assertSuccessful();
    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo('nps-gx@iconic.test'));
    expect(Alert::query()->where('kind', AlertKind::NpsLow)->whereNotNull('emailed_at')->count())->toBe(1);

    $this->actingAs($manager)->postJson('/api/crm/tasks/'.$task?->id.'/complete', [
        'outcome' => 'Spoke with the guest',
    ])->assertOk();

    expect($alert?->fresh()?->resolved_at)->not->toBeNull()
        ->and($task?->fresh()?->status)->toBe(TaskStatus::Done);
});

test('a score of 7 records the survey and sends neither an alert nor a review request', function (): void {
    $owner = managerUser();
    $fixture = npsBooking('2028-10-09', 'ANK-NPS-MID', $owner, contactEmail: 'mid-score@example.com');
    npsGrantMarketing($fixture['contact'], $owner);
    npsSendSurveys($fixture['booking']);

    $this->postJson(
        '/api/engine/survey/'.npsPlainToken($fixture['booking'], $fixture['lead']->id).'/guests/'.$fixture['lead']->id,
        npsAnswerBody(7),
    )->assertOk();

    expect(Alert::query()->where('kind', AlertKind::NpsLow)->count())->toBe(0)
        ->and(CrmTask::query()->where('kind', TaskKind::NpsReply)->count())->toBe(0)
        ->and(Delivery::query()->where('kind', DeliveryKind::ReviewRequest)->count())->toBe(0)
        ->and(npsHistoryWhat($fixture['booking']))->toBe('Post-trip survey recorded — score 7');
});

test('a high score from the contact requests a review only when marketing consent is on record', function (): void {
    $owner = managerUser();
    $without = npsBooking('2028-10-16', 'ANK-NPS-NOCONSENT', $owner, contactEmail: 'no-consent@example.com');
    npsSendSurveys($without['booking']);
    $this->postJson(
        '/api/engine/survey/'.npsPlainToken($without['booking'], $without['lead']->id).'/guests/'.$without['lead']->id,
        npsAnswerBody(9),
    )->assertOk();

    expect(Delivery::query()->where('kind', DeliveryKind::ReviewRequest)->count())->toBe(0)
        ->and(npsHistoryWhat($without['booking']))->toContain('no marketing consent on record for this guest');

    $with = npsBooking('2028-10-23', 'ANK-NPS-CONSENT', $owner, contactEmail: 'Ada@Example.com');
    npsGrantMarketing($with['contact'], $owner);
    npsSendSurveys($with['booking']);
    $this->postJson(
        '/api/engine/survey/'.npsPlainToken($with['booking'], $with['lead']->id).'/guests/'.$with['lead']->id,
        npsAnswerBody(9),
    )->assertOk();

    $review = Delivery::query()->where('idempotency_key', 'review:'.$with['lead']->id)->first();

    expect($review)->not->toBeNull()
        ->and($review?->kind)->toBe(DeliveryKind::ReviewRequest)
        ->and($review?->status)->toBe(DeliveryStatus::Sent)
        ->and($review?->to)->toBe(['Ada@Example.com'])
        ->and(npsHistoryWhat($with['booking']))->toContain('review request sent');

    Mail::assertSent(ReviewRequestMail::class, function (ReviewRequestMail $mail): bool {
        return $mail->reviewUrl === 'PENDING CLIENT' && $mail->hasTo('Ada@Example.com');
    });
});

test('a companion score does not request a review or change the contact nps, and the contact own later score does', function (): void {
    $owner = managerUser();
    $fixture = npsBooking('2028-10-30', 'ANK-NPS-MATE', $owner, contactEmail: 'ada.mate@example.com', companionEmail: 'bea.mate@example.com');
    npsGrantMarketing($fixture['contact'], $owner);
    npsSendSurveys($fixture['booking']);

    $this->postJson(
        '/api/engine/survey/'.npsPlainToken($fixture['booking'], $fixture['companion']->id).'/guests/'.$fixture['companion']->id,
        npsAnswerBody(9, 'companion loved it'),
    )->assertOk();

    expect(Delivery::query()->where('kind', DeliveryKind::ReviewRequest)->count())->toBe(0)
        ->and(npsHistoryWhat($fixture['booking']))->toContain('no marketing consent on record for this guest');

    $blank = $this->actingAs($owner)->getJson('/api/crm/contacts/'.$fixture['contact']->id)
        ->assertOk()
        ->assertJsonPath('nps', null);
    assertNoSensitiveFields($blank);

    $this->postJson(
        '/api/engine/survey/'.npsPlainToken($fixture['booking'], $fixture['lead']->id).'/guests/'.$fixture['lead']->id,
        npsAnswerBody(9, 'contact loved it'),
    )->assertOk();

    expect(Delivery::query()->where('idempotency_key', 'review:'.$fixture['lead']->id)->count())->toBe(1);

    $shown = $this->actingAs($owner)->getJson('/api/crm/contacts/'.$fixture['contact']->id)->assertOk();
    $shown->assertJsonPath('nps', 9);
    expect($shown->json())->not->toHaveKeys(['why', 'best', 'better', 'crew', 'call_notes', 'recommend'])
        ->and(json_encode($shown->json()))->not->toContain('contact loved it');

    $later = npsBooking('2028-11-06', 'ANK-NPS-LATER', $owner, existingContact: $fixture['contact']);
    $later['lead']->forceFill(['email' => 'Ada.Mate@example.com'])->save();
    npsSendSurveys($later['booking']);
    $this->travelTo(CarbonImmutable::parse('2028-11-20 12:00:00', BusinessTime::zone()));
    $this->postJson(
        '/api/engine/survey/'.npsPlainToken($later['booking'], $later['lead']->id).'/guests/'.$later['lead']->id,
        npsAnswerBody(4, 'later voyage'),
    )->assertOk();

    $this->actingAs($owner)->getJson('/api/crm/contacts/'.$fixture['contact']->id)
        ->assertOk()
        ->assertJsonPath('nps', 4);

    $list = $this->actingAs($owner)->getJson('/api/crm/contacts')->assertOk();
    $row = collect($list->json('data'))->firstWhere('id', $fixture['contact']->id);
    expect($row['nps'] ?? null)->toBe(4);
});

test('staff record a response only on a completed voyage, once, and call notes close the post-trip call', function (): void {
    $manager = managerUser();
    $sales = salesExecUser();
    $open = npsBooking('2028-11-13', 'ANK-NPS-OPEN', $manager, BookingStatus::Confirmed, 'open-voyage@example.com');

    $this->actingAs($manager)->postJson('/api/rms/bookings/'.$open['booking']->id.'/guest-responses', [
        ...npsAnswerBody(8),
        'guest_id' => $open['lead']->id,
    ])->assertStatus(422);

    $fixture = npsBooking('2028-11-20', 'ANK-NPS-STAFF', $manager, BookingStatus::FullyPaid, 'staff-voyage@example.com', 'staff-mate@example.com');
    $this->travelTo(CarbonImmutable::parse('2028-12-01 12:00:00', BusinessTime::zone()));
    $this->artisan('iconic:voyage-status')->assertSuccessful();
    $call = CrmTask::query()->where('idempotency_key', 'post-trip-call:'.$fixture['booking']->id)->first();
    expect($call?->status)->toBe(TaskStatus::Open);

    npsSendSurveys($fixture['booking']);
    $this->postJson(
        '/api/engine/survey/'.npsPlainToken($fixture['booking'], $fixture['companion']->id).'/guests/'.$fixture['companion']->id,
        npsAnswerBody(8),
    )->assertOk();
    expect($call?->fresh()?->status)->toBe(TaskStatus::Open);

    $this->actingAs($sales)->postJson('/api/rms/bookings/'.$fixture['booking']->id.'/guest-responses', [
        ...npsAnswerBody(9),
        'guest_id' => $fixture['lead']->id,
        'call_notes' => 'Called and talked through the score',
    ])->assertForbidden();

    $this->actingAs($sales)->getJson('/api/rms/guest-experience/nps')->assertForbidden();

    $this->actingAs($manager)->postJson('/api/rms/bookings/'.$fixture['booking']->id.'/guest-responses', [
        ...npsAnswerBody(9),
        'guest_id' => $fixture['lead']->id,
        'call_notes' => 'Called and talked through the score',
    ])->assertCreated()
        ->assertJsonPath('call_notes', 'Called and talked through the score');

    expect($call?->fresh()?->status)->toBe(TaskStatus::AutoClosed);

    $this->actingAs($manager)->postJson('/api/rms/bookings/'.$fixture['booking']->id.'/guest-responses', [
        ...npsAnswerBody(9),
        'guest_id' => $fixture['lead']->id,
        'call_notes' => 'again',
    ])->assertStatus(409);
});

test('the nps read returns the average, the bands, the review count and the empty-state facts', function (): void {
    $manager = managerUser();
    $low = npsBooking('2028-12-03', 'ANK-NPS-KPI-LOW', $manager, contactEmail: 'kpi-low@example.com');
    $high = npsBooking('2028-12-10', 'ANK-NPS-KPI-HIGH', $manager, contactEmail: 'kpi-high@example.com');
    npsGrantMarketing($high['contact'], $manager);

    $this->travelTo(CarbonImmutable::parse('2028-12-20 15:00:00', BusinessTime::zone()));
    app(RecordGuestResponse::class)->handle(
        $low['booking'],
        $low['lead'],
        SurveyAnswers::from(npsAnswerBody(6, 'kpi why should stay off the list')),
        GuestResponseSource::Staff,
        $manager,
    );
    app(RecordGuestResponse::class)->handle(
        $high['booking'],
        $high['lead'],
        SurveyAnswers::from(npsAnswerBody(9)),
        GuestResponseSource::Staff,
        $manager,
    );

    $earliest = Departure::query()->orderBy('date')->orderBy('id')->firstOrFail();
    $expectedFirst = BusinessTime::calendarDay($earliest->returnDate()->toDateString())->addHours(24)->toDateString();

    $page = $this->actingAs($manager)->getJson('/api/rms/guest-experience/nps?from=2028-12-20&to=2028-12-20')->assertOk();

    expect($page->json('kpis.average_score'))->toBe('7.5')
        ->and($page->json('kpis.responses'))->toBe(2)
        ->and($page->json('kpis.alerts_below'))->toBe(1)
        ->and($page->json('kpis.review_requests_sent'))->toBe(1)
        ->and($page->json('facts.first_expected_survey_on'))->toBe($expectedFirst)
        ->and($page->json('facts.survey_hours_after_return'))->toBe(24)
        ->and($page->json('facts.alert_below'))->toBe(7)
        ->and($page->json('facts.review_request_from'))->toBe(8)
        ->and(collect($page->json('responses'))->pluck('score_class')->sort()->values()->all())->toBe(['high', 'low'])
        ->and(json_encode($page->json()))->not->toContain('kpi why should stay off the list');

    $this->actingAs($manager)->getJson('/api/rms/guest-experience/nps?from=2028-12-21&to=2028-12-21')
        ->assertOk()
        ->assertJsonPath('kpis.responses', 0)
        ->assertJsonPath('kpis.average_score', null)
        ->assertJsonPath('facts.first_expected_survey_on', $expectedFirst);
});

test('the access export contains the contact own survey answers and not a companion or the call notes', function (): void {
    $admin = adminUser();
    $fixture = npsBooking('2028-12-17', 'ANK-NPS-EXPORT', $admin, contactEmail: 'export-me@example.com', companionEmail: 'export-mate@example.com');
    $fixture['departure']->forceFill(['date' => '2020-01-05'])->save();

    app(RecordGuestResponse::class)->handle(
        $fixture['booking']->fresh() ?? $fixture['booking'],
        $fixture['lead'],
        SurveyAnswers::from([
            ...npsAnswerBody(8, 'CONTACT-WHY-SEASICK', 7),
            'call_notes' => 'STAFF-CALL-NOTE-SECRET',
        ]),
        GuestResponseSource::Staff,
        $admin,
    );
    app(RecordGuestResponse::class)->handle(
        $fixture['booking']->fresh() ?? $fixture['booking'],
        $fixture['companion'],
        SurveyAnswers::from([
            ...npsAnswerBody(3, 'COMPANION-WHY-PRIVATE', 2),
            'call_notes' => 'COMPANION-CALL-NOTE',
        ]),
        GuestResponseSource::Staff,
        $admin,
    );

    $created = $this->actingAs($admin)->postJson('/api/privacy/requests', [
        'contact_id' => $fixture['contact']->id,
        'type' => 'ACCESS',
        'received_at' => '2026-09-01T12:00:00Z',
        'channel' => 'EMAIL',
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/privacy/requests/'.$created->json('id').'/export')->assertOk();

    $stored = SubjectRequest::query()->findOrFail($created->json('id'));
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path((string) $stored->export_path)))->toBeTrue();
    $raw = $zip->getFromName('access.json');
    $zip->close();
    $payload = json_decode((string) $raw, true);
    $rows = $payload['survey_responses'] ?? [];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['booking_reference'])->toBe('ANK-NPS-EXPORT')
        ->and($rows[0]['score'])->toBe(8)
        ->and($rows[0]['recommend'])->toBe(7)
        ->and($rows[0]['why'])->toBe('CONTACT-WHY-SEASICK')
        ->and($rows[0]['best'])->toBe('the landing')
        ->and($rows[0]['better'])->toBe('more time ashore')
        ->and($rows[0]['crew'])->toBe('the guide')
        ->and($rows[0]['responded_at'])->not->toBeNull()
        ->and(json_encode($payload))->not->toContain('COMPANION-WHY-PRIVATE')
        ->and(json_encode($payload))->not->toContain('STAFF-CALL-NOTE-SECRET')
        ->and(json_encode($payload))->not->toContain('call_notes');
});

test('erasure clears the contact own survey text, keeps the score, and leaves the companion', function (): void {
    $admin = adminUser();
    $fixture = npsBooking('2028-12-24', 'ANK-NPS-ERASE', $admin, contactEmail: 'erase-nps@example.com', companionEmail: 'erase-mate@example.com');
    $fixture['departure']->forceFill(['date' => '2020-01-12'])->save();
    $booking = $fixture['booking']->fresh() ?? $fixture['booking'];

    $own = app(RecordGuestResponse::class)->handle(
        $booking,
        $fixture['lead'],
        SurveyAnswers::from([
            ...npsAnswerBody(8, 'ERASE-WHY', 6),
            'call_notes' => 'ERASE-CALL',
        ]),
        GuestResponseSource::Staff,
        $admin,
    );
    $companion = app(RecordGuestResponse::class)->handle(
        $booking,
        $fixture['companion'],
        SurveyAnswers::from([
            ...npsAnswerBody(3, 'KEEP-COMPANION-WHY', 1),
            'call_notes' => 'KEEP-COMPANION-CALL',
        ]),
        GuestResponseSource::Staff,
        $admin,
    );

    $created = $this->actingAs($admin)->postJson('/api/privacy/requests', [
        'contact_id' => $fixture['contact']->id,
        'type' => 'ERASURE',
        'received_at' => '2026-09-03T12:00:00Z',
        'channel' => 'LETTER',
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/privacy/requests/'.$created->json('id').'/erase', [
        'verified_how' => 'Letter matched the booking email',
        'confirmation' => 'erase-nps@example.com',
    ])->assertOk();

    $own->refresh();
    $companion->refresh();
    $request = SubjectRequest::query()->findOrFail($created->json('id'));

    expect($own->why)->toBeNull()
        ->and($own->best)->toBeNull()
        ->and($own->better)->toBeNull()
        ->and($own->crew)->toBeNull()
        ->and($own->call_notes)->toBeNull()
        ->and($own->score)->toBe(8)
        ->and($own->recommend)->toBe(6)
        ->and($companion->why)->toBe('KEEP-COMPANION-WHY')
        ->and($companion->call_notes)->toBe('KEEP-COMPANION-CALL')
        ->and($companion->score)->toBe(3)
        ->and($request->outcome)->toContain('why, best, better, crew, call_notes')
        ->and($request->outcome)->toContain('the score was kept');

    expect(GuestResponse::query()->find($own->id)?->score)->toBe(8);
});

test('the survey question list is served to the engine and to panel.rms, and the scales are enforced', function (): void {
    $manager = managerUser();
    $sales = salesExecUser();
    $crmOnly = User::factory()->create([
        'role_id' => Role::factory()->create([
            'permissions' => [Permission::PanelCrm],
        ])->id,
    ]);
    $fixture = npsBooking('2028-08-06', 'ANK-NPS-Q', $manager, contactEmail: 'questions@example.com');
    npsSendSurveys($fixture['booking']);
    $token = npsPlainToken($fixture['booking'], $fixture['lead']->id);

    $engine = $this->getJson('/api/engine/survey/'.$token)->assertOk();
    $rms = $this->actingAs($sales)->getJson('/api/rms/guest-experience/survey-questions')->assertOk();

    expect($engine->json('questions'))->toBe($rms->json('data'))
        ->and(collect($engine->json('questions'))->pluck('key')->all())->toBe([
            'score', 'why', 'best', 'better', 'crew', 'rec',
        ])
        ->and($engine->json('questions.0.type'))->toBe('scale')
        ->and($engine->json('questions.0.min'))->toBe(1)
        ->and($engine->json('questions.0.max'))->toBe(10)
        ->and($engine->json('questions.1.type'))->toBe('text')
        ->and($engine->json('questions.1.min'))->toBeNull()
        ->and($engine->json('questions.5.key'))->toBe('rec')
        ->and($engine->json('questions.5.min'))->toBe(0)
        ->and($engine->json('questions.5.max'))->toBe(10);

    $this->actingAs($crmOnly)->getJson('/api/rms/guest-experience/survey-questions')->assertForbidden();
    $this->actingAs($sales)->getJson('/api/rms/guest-experience/nps')->assertForbidden();

    $this->postJson('/api/engine/survey/'.$token.'/guests/'.$fixture['lead']->id, [
        ...npsAnswerBody(0),
    ])->assertStatus(422)->assertJsonValidationErrors(['score']);

    $this->postJson('/api/engine/survey/'.$token.'/guests/'.$fixture['lead']->id, [
        ...npsAnswerBody(11),
    ])->assertStatus(422)->assertJsonValidationErrors(['score']);

    $this->postJson('/api/engine/survey/'.$token.'/guests/'.$fixture['lead']->id, [
        ...npsAnswerBody(8),
        'recommend' => 9,
    ])->assertStatus(422)->assertJsonValidationErrors(['recommend']);

    $this->postJson('/api/engine/survey/'.$token.'/guests/'.$fixture['lead']->id, npsAnswerBody(8, 'the wildlife', 0))
        ->assertOk();

    expect(GuestResponse::query()->where('guest_id', $fixture['lead']->id)->first()?->recommend)->toBe(0);

    $this->actingAs($sales)->postJson('/api/rms/bookings/'.$fixture['booking']->id.'/guest-responses', [
        ...npsAnswerBody(9),
        'guest_id' => $fixture['companion']->id,
    ])->assertForbidden();
});
