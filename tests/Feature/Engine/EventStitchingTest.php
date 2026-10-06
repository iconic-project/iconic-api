<?php

declare(strict_types=1);

use App\Enums\BehaviouralEventName;
use App\Enums\ContactLifecycle;
use App\Models\BehaviouralEvent;
use App\Models\ChangeHistory;
use App\Models\Contact;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    adminUser();
});

test('checkout submit back-fills a session and writes one identity.stitched', function (): void {
    $session = engineSessionId();
    $this->postJson('/api/engine/events', [
        'session_id' => $session,
        'events' => [
            engineEvent(BehaviouralEventName::ViewItinerary->value, ['itinerary_code' => 'WEST']),
            engineEvent(BehaviouralEventName::BeginCheckout->value, ['itinerary_code' => 'WEST', 'cabin_count' => 1]),
        ],
    ])->assertOk();

    $departure = checkoutWestDeparture();
    $hold = createCheckoutHold($departure);
    $payload = checkoutSubmitPayload($hold['cabins'], (int) $hold['quote']['total'], [
        'session_id' => $session,
    ]);

    $this->postJson('/api/engine/checkout/'.$hold['token'].'/submit', $payload)->assertOk();

    $contact = Contact::query()->where('email', $payload['email'])->firstOrFail();
    $events = BehaviouralEvent::query()->where('session_id', $session)->orderBy('id')->get();

    expect($events->where('name', BehaviouralEventName::IdentityStitched)->count())->toBe(1);
    expect($events->every(fn (BehaviouralEvent $event): bool => $event->contact_id === $contact->id))->toBeTrue();
    expect($events->firstWhere('name', BehaviouralEventName::IdentityStitched)?->params['count'])->toBe(2);
    expect($contact->engine_identified_at)->not->toBeNull();
    expect(ChangeHistory::query()->where('event', 'identity.stitched')->where('subject_id', $contact->id)->count())->toBe(1);
});

test('a waitlist stitch with no booking makes the contact MQL', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    $session = engineSessionId();
    $this->postJson('/api/engine/events', [
        'session_id' => $session,
        'events' => [engineEvent(BehaviouralEventName::ViewStay->value, ['itinerary_code' => 'WEST'])],
    ])->assertOk();

    $payload = engineWaitlistPayload('STD', ['session_id' => $session]);

    $this->postJson('/api/engine/waitlist', $payload)->assertCreated();

    $contact = Contact::query()->where('email', $payload['contact']['email'])->firstOrFail();
    $derived = Contact::query()->withDerived()->whereKey($contact->id)->firstOrFail();

    expect($derived->lifecycle)->toBe(ContactLifecycle::Mql->value);
    expect(BehaviouralEvent::query()->where('session_id', $session)->whereNull('contact_id')->count())->toBe(0);
});

test('a second contact on the same session does not rewrite the first', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    $session = engineSessionId();
    $this->postJson('/api/engine/events', [
        'session_id' => $session,
        'events' => [engineEvent(BehaviouralEventName::PageView->value, ['page_path' => '/first'])],
    ])->assertOk();

    $first = engineWaitlistPayload('STD', [
        'session_id' => $session,
        'contact' => ['name' => 'First Guest', 'email' => 'first-'.uniqid().'@iconic.test'],
    ]);
    $this->postJson('/api/engine/waitlist', $first)->assertCreated();
    $firstContact = Contact::query()->where('email', $first['contact']['email'])->firstOrFail();

    $this->postJson('/api/engine/events', [
        'session_id' => $session,
        'events' => [engineEvent(BehaviouralEventName::PageView->value, ['page_path' => '/after-first'])],
    ])->assertOk();

    $second = engineWaitlistPayload('STD', [
        'session_id' => $session,
        'contact' => ['name' => 'Second Guest', 'email' => 'second-'.uniqid().'@iconic.test'],
    ]);
    $this->postJson('/api/engine/waitlist', $second)->assertCreated();
    $secondContact = Contact::query()->where('email', $second['contact']['email'])->firstOrFail();

    $firstEvent = BehaviouralEvent::query()->where('params->page_path', '/first')->firstOrFail();
    $afterFirst = BehaviouralEvent::query()->where('params->page_path', '/after-first')->firstOrFail();

    expect($firstEvent->contact_id)->toBe($firstContact->id);
    expect($afterFirst->contact_id)->toBe($firstContact->id);

    $this->postJson('/api/engine/events', [
        'session_id' => $session,
        'events' => [engineEvent(BehaviouralEventName::PageView->value, ['page_path' => '/after-second'])],
    ])->assertOk();

    $afterSecond = BehaviouralEvent::query()->where('params->page_path', '/after-second')->firstOrFail();
    expect($afterSecond->contact_id)->toBe($secondContact->id);
    expect($firstEvent->fresh()?->contact_id)->toBe($firstContact->id);

    expect(BehaviouralEvent::query()
        ->where('session_id', $session)
        ->where('name', BehaviouralEventName::IdentityStitched)
        ->count())->toBe(2);
});

test('the complete page stitches the booking contact', function (): void {
    $session = engineSessionId();
    $this->postJson('/api/engine/events', [
        'session_id' => $session,
        'events' => [engineEvent(BehaviouralEventName::PageView->value, ['page_path' => '/complete/[token]'])],
    ])->assertOk();

    $booking = completeBooking();
    $token = completeTokenFor($booking);

    $this->putJson('/api/engine/complete/'.$token.'/billing', [
        'billing_name' => 'Ada Lovelace',
        'session_id' => $session,
    ])->assertOk();

    expect(BehaviouralEvent::query()->where('session_id', $session)->where('contact_id', $booking->contact_id)->count())->toBe(2);
    expect($booking->contact->fresh()?->engine_identified_at)->not->toBeNull();
});

test('a charter enquiry stitches the session', function (): void {
    Mail::fake();
    $session = engineSessionId();
    $this->postJson('/api/engine/events', [
        'session_id' => $session,
        'events' => [engineEvent(BehaviouralEventName::CharterInquirySubmit->value, ['value' => 199500, 'currency' => 'USD'])],
    ])->assertOk();

    $payload = engineCharterPayload(['session_id' => $session]);
    $this->postJson('/api/engine/charter-enquiries', $payload)->assertCreated();

    $contact = Contact::query()->where('email', $payload['contact']['email'])->firstOrFail();
    expect(BehaviouralEvent::query()->where('session_id', $session)->where('contact_id', $contact->id)->count())->toBe(2);
});

test('submitting the same session twice for one contact writes one stitch', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    $session = engineSessionId();
    $this->postJson('/api/engine/events', [
        'session_id' => $session,
        'events' => [engineEvent(BehaviouralEventName::PageView->value, ['page_path' => '/'])],
    ])->assertOk();

    $payload = engineWaitlistPayload('STD', ['session_id' => $session]);
    $this->postJson('/api/engine/waitlist', $payload)->assertCreated();
    $this->postJson('/api/engine/waitlist', array_merge($payload, [
        'contact' => $payload['contact'],
        'notes' => 'Again',
    ]))->assertCreated();

    $contact = Contact::query()->where('email', $payload['contact']['email'])->firstOrFail();
    expect(BehaviouralEvent::query()
        ->where('session_id', $session)
        ->where('contact_id', $contact->id)
        ->where('name', BehaviouralEventName::IdentityStitched)
        ->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'identity.stitched')->where('subject_id', $contact->id)->count())->toBe(1);
});
