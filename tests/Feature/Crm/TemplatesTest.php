<?php

declare(strict_types=1);

use App\Enums\AutomationKind;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\GuestResponseSource;
use App\Enums\JourneyEnrolmentStatus;
use App\Enums\JourneyStepAction;
use App\Enums\JourneySubject;
use App\Mail\Templates\TemplateTestMail;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\Guest;
use App\Models\GuestResponse;
use App\Models\Journey;
use App\Models\JourneyEnrolment;
use App\Models\JourneySend;
use App\Models\JourneyStep;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\TemplateTestSend;
use App\Models\User;
use App\Support\Templates\TemplateRenderer;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\MessageTemplatesSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use RuntimeException;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

test('templates list the published version and a draft is refused without the rules', function (): void {
    $crm = salesExecUser();
    $index = test()->actingAs($crm)->getJson('/api/crm/templates')->assertOk();
    assertNoSensitiveFields($index);

    $welcome = collect($index->json('data'))->firstWhere('key', 'welcome_web_lead');

    expect($index->json('data'))->toHaveCount(24)
        ->and($welcome['kind'])->toBe('MARKETING')
        ->and($welcome['name'])->toBe('Welcome — your Galápagos begins here')
        ->and($welcome['published']['subject'])->toBe('Your Galápagos adventure begins here — Iconic')
        ->and($welcome['published']['variables'])->toContain('unsubscribe_link', 'first_name')
        ->and($welcome['published']['approval_reference'])->toBe(MessageTemplatesSeeder::APPROVAL_REFERENCE)
        ->and($welcome['draft'])->toBeNull();

    $draft = test()->actingAs($crm)->postJson('/api/crm/templates/welcome_web_lead/drafts', marketingDraft('Hi {{first_name}}'))
        ->assertCreated();
    assertNoSensitiveFields($draft);

    test()->actingAs($crm)->postJson('/api/crm/templates/welcome_web_lead/versions/'.$draft->json('version').'/publish', [
        'approval_reference' => 'APR-1',
    ])->assertForbidden();
});

test('publishing enforces the approval reference, the unsubscribe rule, and immutability', function (): void {
    $admin = adminUser();

    $bare = test()->actingAs($admin)->postJson('/api/crm/templates/welcome_web_lead/drafts', [
        'subject' => 'Hello',
        'body' => ['paragraphs' => ['Hi {{first_name}}'], 'list' => [], 'cta' => null],
    ])->assertCreated();

    test()->actingAs($admin)->postJson('/api/crm/templates/welcome_web_lead/versions/'.$bare->json('version').'/publish', [
        'approval_reference' => 'APR-1',
    ])->assertStatus(422)->assertJsonPath('message', 'A marketing template must include {{unsubscribe_link}}.');

    test()->actingAs($admin)->postJson('/api/crm/templates/welcome_web_lead/drafts', marketingDraft('Hi {{first_name}}'))
        ->assertStatus(409);

    $transactional = test()->actingAs($admin)->postJson('/api/crm/templates/request_acknowledgement/drafts', marketingDraft('Hi {{first_name}}'))
        ->assertCreated();

    test()->actingAs($admin)->postJson('/api/crm/templates/request_acknowledgement/versions/'.$transactional->json('version').'/publish', [
        'approval_reference' => 'APR-1',
    ])->assertStatus(422)->assertJsonPath('message', 'A transactional template must not include {{unsubscribe_link}}.');

    $ready = test()->actingAs($admin)->postJson('/api/crm/templates/reengagement_6_months/drafts', marketingDraft('Hi {{first_name}}'))
        ->assertCreated();
    $number = $ready->json('version');

    test()->actingAs($admin)->postJson('/api/crm/templates/reengagement_6_months/versions/'.$number.'/publish', [])
        ->assertStatus(422);

    $published = test()->actingAs($admin)->postJson('/api/crm/templates/reengagement_6_months/versions/'.$number.'/publish', [
        'approval_reference' => 'APR-14',
    ])->assertOk();
    assertNoSensitiveFields($published);
    expect($published->json('published'))->toBeTrue()
        ->and($published->json('approval_reference'))->toBe('APR-14')
        ->and(ChangeHistory::query()->where('event', 'template.published')->count())->toBe(1);

    $template = MessageTemplate::query()->where('key', 'reengagement_6_months')->firstOrFail();
    expect($template->versions()->where('published', true)->count())->toBe(2);

    $version = MessageTemplateVersion::query()->where('template_id', $template->id)->where('version', $number)->firstOrFail();

    expect(fn () => $version->update(['subject' => 'Changed']))->toThrow(LogicException::class);
    expect(fn () => $version->delete())->toThrow(LogicException::class);
    expect(fn () => DB::table('message_template_versions')->where('id', $version->id)->update(['subject' => 'Changed']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('message_template_versions')->where('id', $version->id)->delete())
        ->toThrow(QueryException::class);
});

test('preview resolves declared variables and refuses an unknown or empty one', function (): void {
    $admin = adminUser();
    $contact = Contact::factory()->create(['name' => 'Ada Preview']);

    $preview = test()->actingAs($admin)->postJson('/api/crm/templates/welcome_web_lead/preview', [
        'contact_id' => $contact->id,
        'version' => 1,
    ])->assertOk();
    assertNoSensitiveFields($preview);
    expect($preview->json('subject'))->toBe('Your Galápagos adventure begins here — Iconic')
        ->and($preview->json('body'))->toContain('Ada')
        ->and($preview->json('body'))->toContain('/unsubscribe/');
    Mail::assertNothingSent();
    expect(TemplateTestSend::query()->count())->toBe(0);

    $unknown = test()->actingAs($admin)->postJson('/api/crm/templates/nurture_itinerary/drafts', [
        'subject' => 'Hello {{cabin_code}}',
        'body' => [
            'paragraphs' => ['Hi {{first_name}}'],
            'list' => [],
            'cta' => ['label' => 'Unsubscribe', 'link_key' => 'unsubscribe_link'],
        ],
    ])->assertCreated();

    test()->actingAs($admin)->postJson('/api/crm/templates/nurture_itinerary/preview', [
        'contact_id' => $contact->id,
        'version' => $unknown->json('version'),
    ])->assertStatus(422)->assertJsonPath('message', 'Unknown template variable {{cabin_code}}.');

    test()->actingAs($admin)->postJson('/api/crm/templates/request_acknowledgement/preview', [
        'contact_id' => $contact->id,
        'version' => 1,
    ])->assertStatus(422)->assertJsonPath('message', 'Could not resolve {{booking_reference}} for this contact.');

    $booking = journeyBookingFor($contact, $admin);
    $withBooking = test()->actingAs($admin)->postJson('/api/crm/templates/request_acknowledgement/preview', [
        'booking_id' => $booking->id,
        'version' => 1,
    ])->assertOk();
    expect($withBooking->json('subject'))->toContain($booking->displayReference())
        ->and($withBooking->json('body'))->toContain('Ada');

    test()->actingAs($admin)->postJson('/api/crm/templates/extras_second_window/drafts', [
        'subject' => 'Hello',
        'body' => ['paragraphs' => ['<script>alert(1)</script>'], 'list' => [], 'cta' => null],
    ])->assertStatus(422);
});

test('a rendered message carries no personal data beyond its declared variables', function (): void {
    $admin = adminUser();
    $contact = Contact::factory()->create(['name' => 'Ada Preview', 'email' => 'ada@example.com']);
    $booking = journeyBookingFor($contact, $admin);
    $guest = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'QX-GUEST-FIRST',
        'last_name' => 'QX-GUEST-LAST',
        'dob' => '1971-02-09',
        'nationality' => 'GB',
        'passport_no' => 'QX-PASSPORT-991',
        'email' => 'qx-guest-991@example.com',
        'medical_note' => 'QX-MEDICAL-991',
        'dietary_note' => 'QX-DIETARY-991',
        'accessibility_note' => 'QX-ACCESS-991',
    ]);
    GuestResponse::query()->create([
        'guest_id' => $guest->id,
        'booking_id' => $booking->id,
        'score' => 9,
        'why' => 'QX-SURVEY-991',
        'source' => GuestResponseSource::GuestLink,
        'responded_at' => now(),
    ]);

    $version = MessageTemplate::query()->where('key', 'welcome_web_lead')->firstOrFail()->publishedVersion();

    if (! $version instanceof MessageTemplateVersion) {
        throw new RuntimeException('The welcome template has no published version.');
    }

    $rendered = app(TemplateRenderer::class)->render($version, $contact, $booking);

    expect($rendered->html)->toContain('Ada')
        ->and($rendered->subject)->not->toContain('QX-');

    foreach ([
        'QX-PASSPORT-991',
        '1971-02-09',
        'qx-guest-991@example.com',
        'QX-MEDICAL-991',
        'QX-DIETARY-991',
        'QX-ACCESS-991',
        'QX-SURVEY-991',
        'QX-GUEST-FIRST',
        'QX-GUEST-LAST',
    ] as $secret) {
        expect($rendered->html)->not->toContain($secret)
            ->and($rendered->subject)->not->toContain($secret);
    }
});

test('a test send goes only to the requester and is recorded', function (): void {
    $admin = adminUser();
    $contact = Contact::factory()->create(['name' => 'Ada Preview', 'email' => 'ada-contact@example.com']);

    $sent = test()->actingAs($admin)->postJson('/api/crm/templates/welcome_web_lead/test-send', [
        'contact_id' => $contact->id,
        'version' => 1,
    ])->assertOk();
    assertNoSensitiveFields($sent);
    expect($sent->json('status'))->toBe('SENT')
        ->and($sent->json('subject'))->toBe('Your Galápagos adventure begins here — Iconic');

    Mail::assertSent(TemplateTestMail::class, function (TemplateTestMail $mail) use ($admin, $contact): bool {
        return $mail->hasTo($admin->email)
            && ! $mail->hasTo($contact->email)
            && str_contains($mail->renderedHtml, 'Ada')
            && str_contains($mail->renderedHtml, '/unsubscribe/');
    });

    expect(TemplateTestSend::query()->where('user_id', $admin->id)->where('contact_id', $contact->id)->count())->toBe(1)
        ->and(Delivery::query()->count())->toBe(0)
        ->and(ChangeHistory::query()->where('event', 'template.test_sent')->count())->toBe(1);
});

test('a journey step refuses to send when its template has no published version', function (): void {
    $template = MessageTemplate::query()->create([
        'key' => 'unpublished_step',
        'name' => 'Unpublished',
        'kind' => AutomationKind::Transactional,
    ]);
    $journey = Journey::query()->create([
        'key' => 'unpublished_journey',
        'name' => 'Unpublished',
        'goal' => 'Stay put',
        'kind' => AutomationKind::Transactional,
        'subject' => JourneySubject::Contact,
        'trigger' => ['line' => 'test'],
        'exit_conditions' => [],
        'exit_sentence' => 'none',
        'contract' => 'A test contract.',
        'active' => true,
        'system' => false,
    ]);
    JourneyStep::query()->create([
        'journey_id' => $journey->id,
        'position' => 1,
        'name' => 'Unpublished',
        'delay' => ['anchor' => 'enrolment', 'amount' => 0, 'unit' => 'hours'],
        'template_key' => $template->key,
        'action' => JourneyStepAction::Send,
    ]);
    $contact = Contact::factory()->create(['name' => 'Ada Preview']);
    $enrolment = JourneyEnrolment::query()->create([
        'journey_id' => $journey->id,
        'contact_id' => $contact->id,
        'position' => 1,
        'next_due_at' => now()->subMinute(),
        'status' => JourneyEnrolmentStatus::Active,
        'enrolled_at' => now()->subHour(),
    ]);

    test()->artisan('iconic:journeys')->assertSuccessful();

    $enrolment->refresh();
    expect($enrolment->position)->toBe(1)
        ->and($enrolment->status)->toBe(JourneyEnrolmentStatus::Active)
        ->and(Delivery::query()->where('kind', DeliveryKind::Journey)->count())->toBe(0)
        ->and(JourneySend::query()->where('journey_enrolment_id', $enrolment->id)->count())->toBe(0);
});

/**
 * @return array{subject: string, body: array{paragraphs: list<string>, list: list<string>, cta: array{label: string, link_key: string}}}
 */
function marketingDraft(string $paragraph): array
{
    return [
        'subject' => 'Hello',
        'body' => [
            'paragraphs' => [$paragraph],
            'list' => [],
            'cta' => ['label' => 'Unsubscribe', 'link_key' => 'unsubscribe_link'],
        ],
    ];
}

function journeyBookingFor(Contact $contact, User $owner): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->yacht->cabins->firstOrFail()->id,
        'contact_id' => $contact->id,
        'owner_id' => $owner->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
    ]);
}
