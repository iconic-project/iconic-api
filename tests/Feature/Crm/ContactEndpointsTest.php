<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentDocument;
use App\Enums\ConsentPurpose;
use App\Enums\ConsentSource;
use App\Enums\ContactLifecycle;
use App\Enums\ContactType;
use App\Enums\MainChannel;
use App\Enums\Permission;
use App\Enums\PreferredChannel;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\Role;
use App\Models\User;
use App\Support\SensitiveFields;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the contacts list is visible to every panel.crm user and includes derived fields and filter meta', function (): void {
    $owner = salesExecUser();
    $other = salesExecUser();
    $mine = Contact::factory()->create(['name' => 'Alpha Guest', 'email' => 'alpha@iconic.test']);
    $theirs = Contact::factory()->create(['name' => 'Beta Guest', 'email' => 'beta@iconic.test']);

    $departure = ReservationFixtures::anamaraDeparture('2027-12-05');
    Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'contact_id' => $mine->id,
        'owner_id' => $other->id,
        'status' => BookingStatus::Confirmed,
        'main_channel' => MainChannel::D2C,
        'total' => 26600,
    ]);

    $response = $this->actingAs($owner)
        ->getJson('/api/crm/contacts')
        ->assertOk();

    assertNoSensitiveFields($response);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($mine->id, $theirs->id);

    $row = collect($response->json('data'))->firstWhere('id', $mine->id);
    expect($row['lifecycle'])->toBe(ContactLifecycle::Booked->value);
    expect($row['lifetime_value'])->toBe(26600);
    expect($row['segment'])->toBe('HIGH');
    expect($row['nps'])->toBeNull();
    expect($row['consent']['transactional'])->toBeTrue();
    expect($row['main_channel'])->toBe(MainChannel::D2C->value);
    expect($row)->not->toHaveKey('bookings');

    expect(collect($response->json('meta.filters.type'))->pluck('value')->all())
        ->toBe(array_column(ContactType::cases(), 'value'));
    expect(collect($response->json('meta.filters.lifecycle'))->pluck('label')->all())
        ->toBe(array_map(fn (ContactLifecycle $lifecycle): string => $lifecycle->label(), ContactLifecycle::cases()));
});

test('the contact profile reads bookings without sensitive fields', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create(['name' => 'Profile Guest']);
    $departure = ReservationFixtures::anamaraDeparture('2027-12-12');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'owner_id' => $actor->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
    ]);
    Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Marketing,
        'source' => ConsentSource::Staff,
        'withdrawn' => false,
    ]);
    $register = new ContactConsent;
    $register->contact_id = $contact->id;
    $register->purpose = ConsentPurpose::Marketing;
    $register->granted = true;
    $register->version = 'v1';
    $register->captured_at = now();
    $register->capture_point = ConsentCapturePoint::Staff;
    $register->how_obtained = 'seeded for the profile';
    $register->save();

    $response = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$contact->id)
        ->assertOk();

    assertNoSensitiveFields($response);
    expect(SensitiveFields::keysIn($response->json()))->toBeEmpty();
    $response
        ->assertJsonPath('id', $contact->id)
        ->assertJsonPath('nps', null)
        ->assertJsonPath('consent.marketing', true)
        ->assertJsonPath('consent.transactional', true)
        ->assertJsonPath('bookings.0.id', $booking->id)
        ->assertJsonPath('bookings.0.charges_total', 26600)
        ->assertJsonPath('bookings.0.can_act', true)
        ->assertJsonMissingPath('bookings.0.guests')
        ->assertJsonMissingPath('bookings.0.payments')
        ->assertJsonMissingPath('bookings.0.internal_notes');
});

test('patch updates owned fields, records field names, and conflicts on another contact email', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@iconic.test',
        'phone' => null,
        'country' => null,
        'language' => 'en',
    ]);
    $other = Contact::factory()->create(['email' => 'taken@iconic.test']);

    $this->actingAs($actor)
        ->patchJson('/api/crm/contacts/'.$contact->id, [
            'name' => 'New Name',
            'email' => 'NEW@iconic.test',
            'phone' => '+1 555 0100',
            'country' => 'us',
            'language' => 'FR',
            'preferred_channel' => PreferredChannel::Whatsapp->value,
            'type' => ContactType::CorporateCharter->value,
            'lifetime_value' => 99,
            'charges_total' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('name', 'New Name')
        ->assertJsonPath('email', 'new@iconic.test')
        ->assertJsonPath('country', 'US')
        ->assertJsonPath('language', 'fr')
        ->assertJsonPath('type', ContactType::CorporateCharter->value)
        ->assertJsonPath('preferred_channel', PreferredChannel::Whatsapp->value)
        ->assertJsonPath('lifetime_value', 0);

    $history = ChangeHistory::query()->where('event', 'contact.updated')->where('subject_id', $contact->id)->firstOrFail();
    expect($history->before)->toBe(['fields' => ['name', 'email', 'phone', 'country', 'language', 'preferred_channel', 'type']]);
    expect($history->after)->toBe(['fields' => ['name', 'email', 'phone', 'country', 'language', 'preferred_channel', 'type']]);
    expect(json_encode($history->after))->not->toContain('new@iconic.test');
    expect(json_encode($history->after))->not->toContain('New Name');

    $this->actingAs($actor)
        ->patchJson('/api/crm/contacts/'.$contact->id, [
            'email' => $other->email,
        ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'That email belongs to contact #'.$other->id.' ('.$other->name.'). Merge the contacts to keep a single record.')
        ->assertJsonPath('conflicting_contact.id', $other->id)
        ->assertJsonPath('conflicting_contact.name', $other->name);
});

test('patch is forbidden without contacts.manage even when the user can open the CRM', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelCrm],
    ]);
    $viewer = User::factory()->create(['role_id' => $role->id]);
    $contact = Contact::factory()->create();

    $this->actingAs($viewer)
        ->getJson('/api/crm/contacts/'.$contact->id)
        ->assertOk();

    $this->actingAs($viewer)
        ->patchJson('/api/crm/contacts/'.$contact->id, ['name' => 'Nope'])
        ->assertForbidden();
});

test('a user without panel.crm cannot list contacts', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/crm/contacts')
        ->assertForbidden();
});
