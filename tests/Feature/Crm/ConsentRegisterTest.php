<?php

declare(strict_types=1);

use App\Actions\Contacts\MergeContacts;
use App\Actions\Contacts\StitchEngineIdentity;
use App\Actions\Contacts\UndoContactMerge;
use App\Actions\Crm\RecordContactConsent;
use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentDocument;
use App\Enums\ConsentPurpose;
use App\Enums\ConsentSource;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Services\Config\CurrentConfig;
use App\Support\Crm\BackfillContactConsents;
use App\Support\Crm\ConsentGate;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the gate is false until the latest row for that purpose is granted', function (): void {
    $contact = Contact::factory()->create();
    $actor = managerUser();

    foreach (ConsentPurpose::cases() as $purpose) {
        expect(ConsentGate::allows($contact, $purpose))->toBeFalse();

        app(RecordContactConsent::class)->handle(
            $contact,
            $purpose,
            granted: true,
            version: 'v-gate',
            capturePoint: ConsentCapturePoint::Staff,
            recordedBy: $actor,
            howObtained: 'asked on the phone',
        );

        expect(ConsentGate::allows($contact, $purpose))->toBeTrue();

        app(RecordContactConsent::class)->handle(
            $contact,
            $purpose,
            granted: false,
            version: 'v-gate',
            capturePoint: ConsentCapturePoint::Staff,
            capturedAt: now()->addMinute(),
            recordedBy: $actor,
            howObtained: 'asked to stop',
        );

        expect(ConsentGate::allows($contact, $purpose))->toBeFalse();
    }
});

test('a merge keeps both registers and the latest row wins, and unmerge restores them', function (): void {
    $actor = managerUser();
    $survivor = Contact::factory()->create(['email' => 'survivor-'.uniqid().'@iconic.test']);
    $loser = Contact::factory()->create(['email' => 'loser-'.uniqid().'@iconic.test']);

    app(RecordContactConsent::class)->handle(
        $survivor,
        ConsentPurpose::Marketing,
        granted: false,
        version: 'v1',
        capturePoint: ConsentCapturePoint::Staff,
        capturedAt: now()->subDay(),
        recordedBy: $actor,
        howObtained: 'withdrew by email',
    );

    app(RecordContactConsent::class)->handle(
        $loser,
        ConsentPurpose::Marketing,
        granted: true,
        version: 'v1',
        capturePoint: ConsentCapturePoint::Staff,
        capturedAt: now(),
        recordedBy: $actor,
        howObtained: 'opted in by phone',
    );

    $merged = app(MergeContacts::class)->handle($survivor, $loser, 'same guest', $actor);

    expect(ConsentGate::allows($merged['survivor'], ConsentPurpose::Marketing))->toBeTrue();
    expect(ContactConsent::query()->where('contact_id', $merged['survivor']->id)->count())->toBe(2);
    expect(ContactConsent::query()->where('contact_id', $merged['loser']->id)->count())->toBe(0);

    app(UndoContactMerge::class)->handle($merged['merge'], 'split them again', $actor);

    expect(ConsentGate::allows($survivor->fresh(), ConsentPurpose::Marketing))->toBeFalse();
    expect(ConsentGate::allows($loser->fresh(), ConsentPurpose::Marketing))->toBeTrue();
});

test('triggers refuse a content change and a delete, and allow a contact repoint', function (): void {
    $contact = Contact::factory()->create();
    $other = Contact::factory()->create();
    $row = app(RecordContactConsent::class)->handle(
        $contact,
        ConsentPurpose::Analytics,
        granted: true,
        version: 'v1 (pending LEG-002)',
        capturePoint: ConsentCapturePoint::EngineBanner,
        ip: '203.0.113.9',
    );

    expect(fn () => DB::table('contact_consents')->where('id', $row->id)->update(['granted' => false]))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('contact_consents')->where('id', $row->id)->delete())
        ->toThrow(QueryException::class, 'append-only');

    DB::table('contact_consents')->where('id', $row->id)->update(['contact_id' => $other->id]);

    expect($row->fresh()?->contact_id)->toBe($other->id);
    expect($row->fresh()?->granted)->toBeTrue();
});

test('backfill copies every marketing log row once and leaves other documents alone', function (): void {
    $owner = managerUser();
    $contact = Contact::factory()->create();
    $departure = ReservationFixtures::anamaraDeparture('2027-12-05');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'owner_id' => $owner->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-8810',
    ]);

    $accepted = Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Marketing,
        'version' => 'v-backfill',
        'source' => ConsentSource::Engine,
        'ip' => '203.0.113.10',
        'withdrawn' => false,
        'accepted_at' => Carbon::parse('2026-06-01 12:00:00'),
        'how_obtained' => null,
        'recorded_by' => null,
    ]);

    Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Marketing,
        'version' => 'v-backfill',
        'source' => ConsentSource::Engine,
        'ip' => '203.0.113.11',
        'withdrawn' => true,
        'accepted_at' => Carbon::parse('2026-06-02 12:00:00'),
        'how_obtained' => null,
        'recorded_by' => null,
    ]);

    Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Terms,
        'source' => ConsentSource::Engine,
    ]);

    $marketing = Consent::query()->where('document', ConsentDocument::Marketing)->count();

    expect(BackfillContactConsents::run())->toBe($marketing);
    expect(ContactConsent::query()->count())->toBe($marketing);
    expect(BackfillContactConsents::run())->toBe(0);

    $copied = ContactConsent::query()->where('source_consent_id', $accepted->id)->first();
    expect($copied)->not->toBeNull();
    expect($copied?->granted)->toBeTrue();
    expect($copied?->version)->toBe('v-backfill');
    expect($copied?->ip)->toBe('203.0.113.10');
    expect($copied?->capture_point)->toBe(ConsentCapturePoint::BookingLogBackfill);
    expect($copied?->captured_at?->utc()->toDateTimeString())->toBe('2026-06-01 12:00:00');
    expect(ContactConsent::query()->where('source_consent_id', $accepted->id)->count())->toBe(1);
    expect(ContactConsent::query()->where('granted', false)->count())->toBe(1);
});

test('staff record consent on the register and do not write the booking log', function (): void {
    $contact = Contact::factory()->create();
    $before = Consent::query()->count();

    $this->actingAs(salesExecUser())
        ->postJson('/api/crm/contacts/'.$contact->id.'/consents', [
            'purpose' => ConsentPurpose::Marketing->value,
            'granted' => true,
            'how_obtained' => 'asked on the phone',
        ])
        ->assertForbidden();

    $manager = managerUser();

    $this->actingAs($manager)
        ->postJson('/api/crm/contacts/'.$contact->id.'/consents', [
            'purpose' => ConsentPurpose::Profiling->value,
            'granted' => true,
            'how_obtained' => 'asked on the phone',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['version']);

    $response = $this->actingAs($manager)
        ->postJson('/api/crm/contacts/'.$contact->id.'/consents', [
            'purpose' => ConsentPurpose::Marketing->value,
            'granted' => true,
            'how_obtained' => 'asked on the phone',
        ])
        ->assertOk();

    assertNoSensitiveFields($response);
    expect(Consent::query()->count())->toBe($before);

    $marketing = collect($response->json('current'))->firstWhere('purpose', ConsentPurpose::Marketing->value);
    expect($marketing['granted'])->toBeTrue();
    expect($marketing['version'])->toBe('v1');
    expect($marketing['capture_point'])->toBe(ConsentCapturePoint::Staff->value);
    expect($marketing['how_obtained'])->toBe('asked on the phone');
    expect($marketing['ip_present'])->toBeFalse();
    expect($marketing)->not->toHaveKey('ip');
    expect($response->json('history.0.recorded_by.name'))->toBe($manager->name);
});

test('the register counts match the marketing list filter and never return an ip', function (): void {
    $actor = managerUser();
    $optedIn = Contact::factory()->create(['name' => 'Opted In']);
    $optedOut = Contact::factory()->create(['name' => 'Opted Out']);

    app(RecordContactConsent::class)->handle(
        $optedIn,
        ConsentPurpose::Marketing,
        granted: true,
        version: 'v1',
        capturePoint: ConsentCapturePoint::EngineForm,
        ip: '203.0.113.50',
    );

    app(RecordContactConsent::class)->handle(
        $optedOut,
        ConsentPurpose::Marketing,
        granted: true,
        version: 'v1',
        capturePoint: ConsentCapturePoint::Staff,
        recordedBy: $actor,
        howObtained: 'asked',
    );
    app(RecordContactConsent::class)->handle(
        $optedOut,
        ConsentPurpose::Marketing,
        granted: false,
        version: 'v1',
        capturePoint: ConsentCapturePoint::Staff,
        capturedAt: now()->addMinute(),
        recordedBy: $actor,
        howObtained: 'withdrew',
    );

    $register = $this->actingAs($actor)->getJson('/api/crm/consents/register')->assertOk();
    assertNoSensitiveFields($register);

    $rows = collect($register->json('data'));
    expect($rows->first()['purpose'])->toBe('TRANSACTIONAL');
    expect($rows->first()['basis'])->toBe('Contract');
    expect($rows->first()['contacts'])->toBe(Contact::query()->notMerged()->count());
    expect($rows->firstWhere('purpose', 'PROFILING')['where_captured'])->toBe('not captured yet');
    expect($rows->firstWhere('purpose', 'REMARKETING')['where_captured'])->toBe('not captured yet');
    expect($rows->firstWhere('purpose', 'WHATSAPP')['where_captured'])->toBe('not captured yet');
    expect($rows->firstWhere('purpose', 'MARKETING')['contacts'])->toBe(1);

    $list = $this->actingAs($actor)->getJson('/api/crm/contacts?consent=marketing')->assertOk();
    expect($list->json('meta.total'))->toBe(1);

    $detail = $this->actingAs($actor)->getJson('/api/crm/contacts/'.$optedIn->id.'/consents')->assertOk();
    assertNoSensitiveFields($detail);
    expect($detail->getContent())->not->toContain('203.0.113.50');
    $history = collect($detail->json('history'))->firstWhere('purpose', 'MARKETING');
    expect($history['ip_present'])->toBeTrue();
    expect($history)->not->toHaveKey('ip');

    $map = $this->actingAs(salesExecUser())->getJson('/api/crm/consents/data-map')->assertOk();
    assertNoSensitiveFields($map);
    $passport = collect($map->json('data'))->firstWhere('rule_key', 'retention.passport_months_after_check_out');
    expect($passport['in_crm'])->toBe('never');
    expect($passport['rule_value'])->toBe(app(CurrentConfig::class)->businessRules()->retention->passportMonthsAfterCheckOut);
    expect(collect($map->json('data'))->firstWhere('rule_key', 'retention.behavioural_raw_months'))->not->toBeNull();
});

test('the timeline shows a register line and hides the marketing booking-log line', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create();
    $departure = ReservationFixtures::anamaraDeparture('2027-12-12');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'owner_id' => $actor->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-8811',
    ]);

    Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Marketing,
        'version' => 'v-hidden',
        'source' => ConsentSource::Engine,
        'accepted_at' => now()->subHour(),
    ]);

    app(RecordContactConsent::class)->handle(
        $contact,
        ConsentPurpose::Marketing,
        granted: true,
        version: 'v1',
        capturePoint: ConsentCapturePoint::EngineForm,
    );

    $response = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$contact->id.'/timeline')
        ->assertOk();

    assertNoSensitiveFields($response);
    $items = collect($response->json('data'));
    $register = $items->firstWhere('kind', 'register');
    expect($register['title'])->toBe('Marketing');
    expect($register['detail'])->toContain('granted');
    expect($register['detail'])->toContain('Engine form');
    expect($items->where('kind', 'consent')->filter(
        fn (array $item): bool => str_contains((string) $item['detail'], 'v-hidden'),
    ))->toBeEmpty();
});

test('stitching records analytics once at the first event time', function (): void {
    $contact = Contact::factory()->create();
    $session = str_replace('-', '', (string) fake()->uuid());
    $first = Carbon::parse('2026-08-01 15:04:05');

    BehaviouralEvent::factory()->create([
        'session_id' => $session,
        'contact_id' => null,
        'name' => BehaviouralEventName::PageView,
        'occurred_at' => $first,
    ]);
    BehaviouralEvent::factory()->create([
        'session_id' => $session,
        'contact_id' => null,
        'name' => BehaviouralEventName::ViewProperty,
        'occurred_at' => $first->copy()->addHour(),
    ]);

    app(StitchEngineIdentity::class)->handle($contact, $session);
    app(StitchEngineIdentity::class)->handle($contact, $session);

    $rows = ContactConsent::query()
        ->where('contact_id', $contact->id)
        ->where('purpose', ConsentPurpose::Analytics)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()?->granted)->toBeTrue();
    expect($rows->first()?->capture_point)->toBe(ConsentCapturePoint::EngineBanner);
    expect($rows->first()?->version)->toBe('v1 (pending LEG-002)');
    expect($rows->first()?->session_id)->toBe($session);
    expect($rows->first()?->captured_at?->utc()->toDateTimeString())->toBe('2026-08-01 15:04:05');
});
