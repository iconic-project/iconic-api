<?php

declare(strict_types=1);

use App\Actions\Contacts\ResolveContact;
use App\Enums\ActivityKind;
use App\Enums\BookingStatus;
use App\Enums\ConsentPurpose;
use App\Enums\DocumentKind;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactConsent;
use App\Models\CrmTask;
use App\Models\Document;
use App\Models\ErasureLog;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\SubjectRequest;
use App\Support\Crm\ConsentGate;
use App\Support\SensitiveFields;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;
use ZipArchive;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('manager and sales exec cannot use privacy routes', function (): void {
    $this->actingAs(managerUser())->getJson('/api/privacy/requests')->assertForbidden();
    $this->actingAs(salesExecUser())->getJson('/api/privacy/requests')->assertForbidden();
    $this->actingAs(adminUser())->getJson('/api/crm/requests')->assertNotFound();
});

test('a subject request is due thirty calendar days later and raises one task', function (): void {
    $admin = adminUser();
    $contact = Contact::factory()->create();

    $created = $this->actingAs($admin)->postJson('/api/privacy/requests', [
        'contact_id' => $contact->id,
        'type' => 'ACCESS',
        'received_at' => '2026-09-01T12:00:00Z',
        'channel' => 'EMAIL',
        'notes' => 'Asked by email',
    ])->assertCreated();

    expect($created->json('due_at'))->toContain('2026-10-01');

    $task = CrmTask::query()->where('kind', TaskKind::SubjectRequest)->firstOrFail();
    expect($task->idempotency_key)->toBe('subject:'.$created->json('id'));
    expect($task->needs_permission?->value)->toBe('privacy.manage');
    expect($task->owner_id)->toBe($admin->id);

    $this->travelTo('2026-10-02 12:00:00');
    $overdue = $this->actingAs($admin)->getJson('/api/privacy/requests?overdue=1')->assertOk();
    expect(collect($overdue->json('data'))->pluck('id'))->toContain($created->json('id'));
    assertNoSensitiveFields($overdue);
});

test('an access export lists the relationship and no passenger data', function (): void {
    $admin = adminUser();
    $contact = Contact::factory()->create(['email' => 'export@iconic.test']);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'contact_id' => $contact->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-EXP-1',
        'total' => 26600,
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'passport_no' => 'AB12345678',
        'nationality' => 'EC',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'amount' => 5000,
        'gateway_id' => 'pi_secret_should_not_export',
    ]);
    Consent::factory()->create(['booking_id' => $booking->id]);
    Document::query()->create([
        'booking_id' => $booking->id,
        'kind' => DocumentKind::Invoice,
        'version' => 1,
        'number' => 'INV-1',
        'snapshot' => ['total' => 26600],
        'file_path' => 'documents/inv-1.pdf',
        'file_sha256' => str_repeat('a', 64),
        'issued_at' => now(),
    ]);
    BehaviouralEvent::factory()->create(['contact_id' => $contact->id]);
    ContactActivity::query()->create([
        'contact_id' => $contact->id,
        'kind' => ActivityKind::Note,
        'body' => 'Called',
        'occurred_at' => now(),
    ]);

    $created = $this->actingAs($admin)->postJson('/api/privacy/requests', [
        'contact_id' => $contact->id,
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
    expect($raw)->toBeString();
    $payload = json_decode((string) $raw, true);
    expect(SensitiveFields::keysIn($payload))->toBe([]);
    expect(json_encode($payload))->not->toContain('AB12345678');
    expect(json_encode($payload))->not->toContain('pi_secret_should_not_export');
    expect($payload['passenger_data'])->toContain('pending LEG-002');
    expect($payload['bookings'][0]['reference'])->toBe('ANK-EXP-1');
    expect($payload['bookings'][0]['payments'][0]['amount'])->toBe(5000);
    expect($payload['documents'])->not->toBeEmpty();
    expect($payload['booking_consents'])->not->toBeEmpty();
    expect($payload['behavioural_events'])->not->toBeEmpty();
});

test('an objection withdraws marketing profiling and remarketing', function (): void {
    $admin = adminUser();
    $contact = Contact::factory()->create();

    $created = $this->actingAs($admin)->postJson('/api/privacy/requests', [
        'contact_id' => $contact->id,
        'type' => 'OBJECTION',
        'received_at' => '2026-09-02T12:00:00Z',
        'channel' => 'PHONE',
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/privacy/requests/'.$created->json('id').'/complete', [
        'verified_how' => 'Matched the phone number on the booking',
        'outcome' => 'Withdrew marketing, profiling and remarketing',
    ])->assertOk();

    expect(ConsentGate::allows($contact, ConsentPurpose::Marketing))->toBeFalse();
    expect(ConsentGate::allows($contact, ConsentPurpose::Profiling))->toBeFalse();
    expect(ConsentGate::allows($contact, ConsentPurpose::Remarketing))->toBeFalse();
    expect(ContactConsent::query()->where('contact_id', $contact->id)->where('granted', false)->count())->toBe(3);
    expect(CrmTask::query()->where('idempotency_key', 'subject:'.$created->json('id'))->firstOrFail()->status)
        ->toBe(TaskStatus::AutoClosed);
});

test('erasure waits for the cruise and an open refund, then keeps the ledger', function (): void {
    $admin = adminUser();
    $contact = Contact::factory()->create(['email' => 'erase-me@iconic.test', 'language' => 'en']);
    $language = $contact->language;
    $type = $contact->type;
    $future = ReservationFixtures::anamaraDeparture('2027-11-07');
    $futureBooking = Booking::factory()->create([
        'departure_id' => $future->id,
        'contact_id' => $contact->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-FUT-1',
    ]);

    $created = $this->actingAs($admin)->postJson('/api/privacy/requests', [
        'contact_id' => $contact->id,
        'type' => 'ERASURE',
        'received_at' => '2026-09-03T12:00:00Z',
        'channel' => 'LETTER',
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/privacy/requests/'.$created->json('id').'/erase', [
        'verified_how' => 'Letter matched the booking email',
        'confirmation' => 'erase-me@iconic.test',
    ])->assertStatus(409);

    $futureBooking->forceFill(['contact_id' => Contact::factory()->create()->id])->save();
    $pastDeparture = ReservationFixtures::anamaraDeparture('2027-11-14');
    $pastDeparture->forceFill(['date' => '2020-01-05'])->save();
    $past = Booking::factory()->create([
        'departure_id' => $pastDeparture->id,
        'contact_id' => $contact->id,
        'status' => BookingStatus::Completed,
        'reference' => 'ANK-PAST-1',
    ]);
    $refund = RefundRequest::factory()->create([
        'booking_id' => $past->id,
        'status' => 'PENDING',
    ]);

    $this->actingAs($admin)->postJson('/api/privacy/requests/'.$created->json('id').'/erase', [
        'verified_how' => 'Letter matched the booking email',
        'confirmation' => 'erase-me@iconic.test',
    ])->assertStatus(409);

    $refund->forceFill(['status' => 'REJECTED'])->save();

    $bookings = Booking::query()->count();
    $payments = Payment::query()->count();
    $documents = Document::query()->count();
    $consents = Consent::query()->count();

    $this->actingAs($admin)->postJson('/api/privacy/requests/'.$created->json('id').'/erase', [
        'verified_how' => 'Letter matched the booking email',
        'confirmation' => 'Erase-Me@iconic.test',
    ])->assertOk();

    $contact->refresh();
    expect($contact->name)->toBe('Erased contact #'.$contact->id);
    expect($contact->email)->toBeNull();
    expect($contact->phone)->toBeNull();
    expect($contact->phone_e164)->toBeNull();
    expect($contact->country)->toBeNull();
    expect($contact->first_touch)->toBeNull();
    expect($contact->last_touch)->toBeNull();
    expect($contact->language)->toBe($language);
    expect($contact->type)->toBe($type);
    expect(Booking::query()->count())->toBe($bookings);
    expect(Payment::query()->count())->toBe($payments);
    expect(Document::query()->count())->toBe($documents);
    expect(Consent::query()->count())->toBe($consents);
    expect(ContactActivity::query()->where('contact_id', $contact->id)->count())->toBe(1);
    expect(ContactActivity::query()->where('contact_id', $contact->id)->value('body'))->toStartWith('Erased on ');
    expect(ErasureLog::query()->where('contact_id', $contact->id)->count())->toBe(1);
    expect(ErasureLog::query()->value('email_sha256'))->toBe(hash('sha256', 'erase-me@iconic.test'));

    $again = app(ResolveContact::class)->handle([
        'name' => 'Same person',
        'email' => 'erase-me@iconic.test',
    ]);
    expect($again->id)->not->toBe($contact->id);
    expect(ContactConsent::query()->where('contact_id', $again->id)->count())->toBe(0);
    expect(ConsentGate::allows($again, ConsentPurpose::Marketing))->toBeFalse();
});
