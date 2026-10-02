<?php

declare(strict_types=1);

use App\Enums\BehaviouralEventName;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentPurpose;
use App\Enums\JourneyEnrolmentStatus;
use App\Models\BehaviouralEvent;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\Delivery;
use App\Models\Journey;
use App\Models\JourneyEnrolment;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
    Journey::query()->where('key', 'nurture_to_request')->update(['active' => true]);
});

test('a checkout tick stores the lead, the form consent, the stitch and the lead branch', function (): void {
    $session = 'session-lead-1';
    BehaviouralEvent::factory()->create([
        'session_id' => $session,
        'contact_id' => null,
        'name' => BehaviouralEventName::AbandonCart,
    ]);

    $payload = [
        'email' => 'Ada.Guest@iconic.test',
        'first_name' => 'Ada',
        'consent' => true,
        'version' => 'v1 (pending LEG-002)',
        'session_id' => $session,
    ];

    $first = $this->postJson('/api/engine/marketing-leads', $payload)->assertOk();
    $second = $this->postJson('/api/engine/marketing-leads', $payload)->assertOk();

    expect($first->json())->toBe(['accepted' => true])
        ->and($second->json())->toBe(['accepted' => true])
        ->and(Contact::query()->whereRaw('LOWER(email) = ?', ['ada.guest@iconic.test'])->count())->toBe(1)
        ->and(Delivery::query()->count())->toBe(0);

    $contact = Contact::query()->whereRaw('LOWER(email) = ?', ['ada.guest@iconic.test'])->firstOrFail();

    $marketing = ContactConsent::query()
        ->where('contact_id', $contact->id)
        ->where('purpose', ConsentPurpose::Marketing)
        ->where('capture_point', ConsentCapturePoint::EngineForm)
        ->first();

    expect($marketing)->not->toBeNull()
        ->and($marketing?->granted)->toBeTrue()
        ->and($marketing?->version)->toBe('v1 (pending LEG-002)');

    expect(ContactConsent::query()
        ->where('contact_id', $contact->id)
        ->where('purpose', ConsentPurpose::Analytics)
        ->where('capture_point', ConsentCapturePoint::EngineBanner)
        ->exists())->toBeTrue();

    expect(BehaviouralEvent::query()
        ->where('session_id', $session)
        ->where('name', BehaviouralEventName::AbandonCart)
        ->value('contact_id'))->toBe($contact->id);

    expect(BehaviouralEvent::query()
        ->where('session_id', $session)
        ->where('name', BehaviouralEventName::IdentityStitched)
        ->exists())->toBeTrue();

    $enrolment = JourneyEnrolment::query()->where('contact_id', $contact->id)->firstOrFail();
    expect($enrolment->branch)->toBe('lead')
        ->and(JourneyEnrolment::query()->where('contact_id', $contact->id)->count())->toBe(1);
});

test('a missing tick or the wrong consent version stores nothing', function (): void {
    $before = Contact::query()->count();

    $this->postJson('/api/engine/marketing-leads', [
        'email' => 'no-tick@iconic.test',
        'first_name' => 'No',
        'consent' => false,
        'version' => 'v1 (pending LEG-002)',
    ])->assertStatus(422);

    $this->postJson('/api/engine/marketing-leads', [
        'email' => 'wrong-version@iconic.test',
        'first_name' => 'Wrong',
        'consent' => true,
        'version' => 'v0',
    ])->assertStatus(422);

    expect(Contact::query()->count())->toBe($before)
        ->and(ContactConsent::query()->count())->toBe(0)
        ->and(JourneyEnrolment::query()->count())->toBe(0);
});

test('unsubscribe withdraws marketing consent, exits marketing enrolments, and exposes nothing personal', function (): void {
    $this->postJson('/api/engine/marketing-leads', [
        'email' => 'leave@iconic.test',
        'first_name' => 'Lea',
        'consent' => true,
        'version' => 'v1 (pending LEG-002)',
    ])->assertOk();

    $contact = Contact::query()->whereRaw('LOWER(email) = ?', ['leave@iconic.test'])->firstOrFail();
    $token = (string) $contact->unsubscribe_token;

    $preview = $this->getJson('/api/engine/unsubscribe/'.$token)->assertOk();
    expect(array_keys($preview->json()))->toBe(['valid', 'already_unsubscribed'])
        ->and($preview->json())->toBe(['valid' => true, 'already_unsubscribed' => false]);
    expect(json_encode($preview->json()))->not->toContain('leave@iconic.test')
        ->and(json_encode($preview->json()))->not->toContain('Lea');

    $this->postJson('/api/engine/unsubscribe/'.$token)->assertOk()
        ->assertExactJson(['valid' => true, 'already_unsubscribed' => true]);

    $enrolment = JourneyEnrolment::query()->where('contact_id', $contact->id)->firstOrFail();
    expect($enrolment->status)->toBe(JourneyEnrolmentStatus::Exited)
        ->and($enrolment->exit_reason)->toBe('unsubscribed');

    $latest = ContactConsent::query()
        ->where('contact_id', $contact->id)
        ->where('purpose', ConsentPurpose::Marketing)
        ->orderByDesc('id')
        ->first();

    expect($latest?->granted)->toBeFalse()
        ->and($latest?->capture_point)->toBe(ConsentCapturePoint::Unsubscribe);

    $consents = ContactConsent::query()->where('contact_id', $contact->id)->count();
    $exits = JourneyEnrolment::query()->where('contact_id', $contact->id)->where('exit_reason', 'unsubscribed')->count();

    $again = $this->postJson('/api/engine/unsubscribe/'.$token)->assertOk();
    expect($again->json())->toBe(['valid' => true, 'already_unsubscribed' => true])
        ->and(ContactConsent::query()->where('contact_id', $contact->id)->count())->toBe($consents)
        ->and(JourneyEnrolment::query()->where('contact_id', $contact->id)->where('exit_reason', 'unsubscribed')->count())->toBe($exits);

    $this->getJson('/api/engine/unsubscribe/not-a-real-token')->assertNotFound()
        ->assertJsonPath('message', 'This link is not valid.');
});
