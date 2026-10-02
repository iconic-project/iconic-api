<?php

declare(strict_types=1);

use App\Enums\CabinCategory;
use App\Enums\CharterEnquiryStatus;
use App\Enums\ContactType;
use App\Enums\DepartureStatus;
use App\Enums\Permission;
use App\Enums\WaitlistSource;
use App\Mail\CharterEnquiryMail;
use App\Models\CharterEnquiry;
use App\Models\Role;
use App\Models\User;
use App\Models\WaitlistEntry;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function engineWaitlistPayload(int $departureId, array $overrides = []): array
{
    return array_merge([
        'departure_id' => $departureId,
        'cabin_category' => CabinCategory::Suite->value,
        'contact' => [
            'name' => 'Wait Guest',
            'email' => 'wait-'.uniqid().'@iconic.test',
        ],
        'adults' => 2,
        'children' => 0,
        'notes' => 'Any suite',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function engineCharterPayload(array $overrides = []): array
{
    return array_merge([
        'preferred_from' => '2027-11-01',
        'preferred_to' => '2027-12-31',
        'guests' => 12,
        'contact' => [
            'first_name' => 'Charter',
            'last_name' => 'Guest',
            'email' => 'charter-'.uniqid().'@iconic.test',
            'phone' => '+15550000',
        ],
        'message' => 'We would like the property for a week in November.',
    ], $overrides);
}

test('the engine waitlist is stored with source engine and refused when off', function (): void {
    $departure = checkoutWestDeparture();

    $this->postJson('/api/engine/waitlist', engineWaitlistPayload($departure->id))
        ->assertCreated()
        ->assertJsonPath('source', WaitlistSource::Engine->value);

    expect(WaitlistEntry::query()->value('source'))->toBe(WaitlistSource::Engine);

    $closed = checkoutWestDeparture('2027-11-14');
    $closed->update(['waitlist_enabled' => false]);

    $this->postJson('/api/engine/waitlist', engineWaitlistPayload($closed->id))
        ->assertUnprocessable()
        ->assertJsonPath('errors.departure_id.0', 'The waitlist is off for this departure.');
});

test('a hidden departure cannot be waitlisted from the engine', function (): void {
    $departure = checkoutWestDeparture();
    $departure->update(['status' => DepartureStatus::Hidden]);

    $this->postJson('/api/engine/waitlist', engineWaitlistPayload($departure->id))->assertNotFound();
});

test('a charter enquiry is stored and mailed to the reservations mailbox', function (): void {
    Mail::fake();
    $departure = checkoutWestDeparture();

    $this->postJson('/api/engine/charter-enquiries', engineCharterPayload([
        'departure_id' => $departure->id,
        'preferred_from' => null,
        'preferred_to' => null,
    ]))
        ->assertCreated()
        ->assertJsonPath('status', CharterEnquiryStatus::New->value)
        ->assertJsonPath('source', 'ENGINE');

    $enquiry = CharterEnquiry::query()->firstOrFail();
    expect($enquiry->guests)->toBe(12);
    expect($enquiry->departure_id)->toBe($departure->id);
    expect($enquiry->contact->name)->toBe('Charter Guest');
    expect($enquiry->contact->type)->toBe(ContactType::CorporateCharter);

    Mail::assertSent(CharterEnquiryMail::class, function (CharterEnquiryMail $mail): bool {
        return $mail->hasTo((string) config('mail.reservations'));
    });
});

test('charter guests over the property cap are refused', function (): void {
    $this->postJson('/api/engine/charter-enquiries', engineCharterPayload([
        'guests' => 17,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guests']);
});

test('staff can list charter enquiries and move NEW to CONTACTED to CLOSED', function (): void {
    Mail::fake();
    $this->postJson('/api/engine/charter-enquiries', engineCharterPayload())->assertCreated();
    $enquiry = CharterEnquiry::query()->firstOrFail();

    $this->actingAs(managerUser())
        ->getJson('/api/rms/charter-enquiries')
        ->assertOk()
        ->assertJsonFragment(['id' => $enquiry->id, 'status' => CharterEnquiryStatus::New->value]);

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/charter-enquiries/'.$enquiry->id, [
            'status' => CharterEnquiryStatus::Contacted->value,
        ])
        ->assertOk()
        ->assertJsonPath('status', CharterEnquiryStatus::Contacted->value);

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/charter-enquiries/'.$enquiry->id, [
            'status' => CharterEnquiryStatus::Closed->value,
            'reason' => 'Guest withdrew',
        ])
        ->assertOk()
        ->assertJsonPath('status', CharterEnquiryStatus::Closed->value);

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/charter-enquiries/'.$enquiry->id, [
            'status' => CharterEnquiryStatus::New->value,
        ])
        ->assertStatus(409);
});

test('panel.rms without bookings.create cannot change a charter enquiry', function (): void {
    Mail::fake();
    $this->postJson('/api/engine/charter-enquiries', engineCharterPayload())->assertCreated();
    $enquiry = CharterEnquiry::query()->firstOrFail();

    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms->value],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/rms/charter-enquiries')
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/rms/charter-enquiries/'.$enquiry->id, [
            'status' => CharterEnquiryStatus::Contacted->value,
        ])
        ->assertForbidden();
});

test('engine waitlist and charter are rate limited', function (): void {
    $departure = checkoutWestDeparture();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/engine/waitlist', engineWaitlistPayload($departure->id))->assertCreated();
    }

    $this->postJson('/api/engine/waitlist', engineWaitlistPayload($departure->id))->assertStatus(429);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/engine/charter-enquiries', engineCharterPayload())->assertCreated();
    }

    $this->postJson('/api/engine/charter-enquiries', engineCharterPayload())->assertStatus(429);
});

test('checkout create is rate limited at ten per minute', function (): void {
    $departure = checkoutWestDeparture();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/engine/checkout', checkoutHoldPayload($departure, [
            ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
        ]));
    }

    $this->postJson('/api/engine/checkout', checkoutHoldPayload($departure))
        ->assertStatus(429);
});
