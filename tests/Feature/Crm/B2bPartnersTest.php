<?php

declare(strict_types=1);

use App\Actions\Crm\RecordContactConsent;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentPurpose;
use App\Enums\DealStage;
use App\Enums\DealType;
use App\Enums\Permission;
use App\Events\AgencyApproved;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Journey;
use App\Models\JourneyEnrolment;
use App\Models\Role;
use App\Models\User;
use App\Support\Agencies\AgencyBookingWindow;
use App\Support\Iso;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

test('a matched agency shows the partner journey, ledger totals and only that agency deals', function (): void {
    $crm = b2bCrmUser();
    $owner = salesExecUser();
    $partner = b2bContact();

    b2bActivate('b2b_partner_activation');
    $agency = Agency::factory()->create([
        'name' => 'Matched B2B',
        'email' => strtoupper((string) $partner->email),
        'commission_pct' => 15,
    ]);
    AgencyApproved::dispatch($agency);

    $confirmed = b2bBooking($partner, $owner, BookingStatus::Confirmed, '2027-11-07', 26600);
    $confirmed->agency_id = $agency->id;
    $confirmed->commission_pct = 10;
    $confirmed->commission_approved = true;
    $confirmed->save();

    $waitlisted = b2bBooking($partner, $owner, BookingStatus::Waitlisted, '2027-11-14', 10000);
    $waitlisted->agency_id = $agency->id;
    $waitlisted->save();

    $openDeal = Deal::query()->create([
        'contact_id' => $partner->id,
        'title' => 'Open partner lead',
        'type' => DealType::Agency,
        'stage' => DealStage::NewLead,
        'stage_entered_at' => now()->addMinute(),
        'booking_id' => $waitlisted->id,
    ]);

    $closedDeal = Deal::query()->create([
        'contact_id' => $partner->id,
        'title' => 'Confirmed partner booking',
        'type' => DealType::Agency,
        'stage' => null,
        'stage_entered_at' => now(),
        'booking_id' => $confirmed->id,
    ]);

    $other = Agency::factory()->create([
        'name' => 'Other B2B',
        'email' => 'other-b2b@example.test',
    ]);
    $otherBooking = b2bBooking($partner, $owner, BookingStatus::Confirmed, '2027-11-21', 5000);
    $otherBooking->agency_id = $other->id;
    $otherBooking->save();
    $otherDeal = Deal::query()->create([
        'contact_id' => $partner->id,
        'title' => 'Other agency deal',
        'type' => DealType::Agency,
        'stage' => DealStage::NewLead,
        'stage_entered_at' => now(),
        'booking_id' => $otherBooking->id,
    ]);

    Deal::query()->create([
        'contact_id' => $partner->id,
        'title' => 'Unbound lead',
        'type' => DealType::Fit,
        'stage' => DealStage::Qualifying,
        'stage_entered_at' => now(),
    ]);

    $list = $this->actingAs($crm)->getJson('/api/crm/b2b-partners')->assertOk();
    assertNoSensitiveFields($list);
    expect($list->json())->not->toHaveKey('meta');

    /** @var array<string, mixed> $matched */
    $matched = collect($list->json('data'))->firstWhere('id', $agency->id);
    $enrolment = JourneyEnrolment::query()
        ->where('contact_id', $partner->id)
        ->whereHas('journey', fn ($query) => $query->where('key', 'b2b_partner_activation'))
        ->firstOrFail();

    $agency->load(['bookings.departure', 'bookings.commissionPayout']);
    $stats = AgencyBookingWindow::stats($agency->bookings);
    $confirmed->refresh();

    expect($matched['contact'])->toBe(['id' => $partner->id, 'name' => $partner->name])
        ->and($matched['status'])->toBe('APPROVED')
        ->and($matched['commission_pct'])->toBe(15)
        ->and($matched['revenue'])->toBe($stats['revenue'])
        ->and($matched['commission_accrued'])->toBe($stats['commission_accrued'])
        ->and($stats['commission_accrued'])->toBe($confirmed->commissionAmount())
        ->and($stats['revenue'])->toBe(36600)
        ->and($matched['enrolment']['status'])->toBe('ACTIVE')
        ->and($matched['enrolment']['step'])->toBe([
            'position' => 1,
            'name' => 'Welcome + rate agreement and materials',
        ])
        ->and($matched['enrolment']['next_due_at'])->toBe(Iso::utc($enrolment->next_due_at))
        ->and(array_keys($matched['enrolment']))->toBe(['status', 'step', 'next_due_at'])
        ->and($matched['enrolment_note'])->toBeNull()
        ->and($matched['open_deal_count'])->toBe(1)
        ->and(array_key_exists('deals', $matched))->toBeFalse();

    $show = $this->actingAs($crm)->getJson('/api/crm/b2b-partners/'.$agency->id)->assertOk();
    assertNoSensitiveFields($show);
    expect($show->json('enrolment.journey_key'))->toBe('b2b_partner_activation')
        ->and($show->json('enrolment.status'))->toBe('ACTIVE')
        ->and($show->json('enrolment.step.template_key'))->toBe('portal_invite')
        ->and($show->json('enrolment.next_due_at'))->toBe(Iso::utc($enrolment->next_due_at))
        ->and($show->json('enrolment.sends'))->toBeArray()
        ->and($show->json('open_deal_count'))->toBe(1)
        ->and($show->json('revenue'))->toBe($stats['revenue']);

    $dealIds = collect($show->json('deals'))->pluck('id')->all();
    expect($dealIds)->toContain($openDeal->id)
        ->and($dealIds)->toContain($closedDeal->id)
        ->and($dealIds)->not->toContain($otherDeal->id);
});

test('an agency with no contact stays on the list and is not enrolled', function (): void {
    $crm = b2bCrmUser();
    $silent = b2bContact();
    $silentAgency = Agency::factory()->create([
        'name' => 'Silent B2B',
        'email' => $silent->email,
    ]);
    $unmatched = Agency::factory()->create([
        'name' => 'Unmatched B2B',
        'email' => 'nobody-b2b@example.test',
    ]);
    $contacts = Contact::query()->count();

    $list = $this->actingAs($crm)->getJson('/api/crm/b2b-partners')->assertOk();
    assertNoSensitiveFields($list);
    expect(Contact::query()->count())->toBe($contacts);

    /** @var array<string, mixed> $skipped */
    $skipped = collect($list->json('data'))->firstWhere('id', $unmatched->id);
    /** @var array<string, mixed> $quiet */
    $quiet = collect($list->json('data'))->firstWhere('id', $silentAgency->id);

    expect($skipped['contact'])->toBeNull()
        ->and($skipped['enrolment'])->toBeNull()
        ->and($skipped['enrolment_note'])->toBe('No CRM contact matches this agency, so b2b_partner_activation was not enrolled.')
        ->and(Contact::query()->where('email', 'nobody-b2b@example.test')->exists())->toBeFalse()
        ->and($quiet['contact'])->toBe(['id' => $silent->id, 'name' => $silent->name])
        ->and($quiet['enrolment'])->toBeNull()
        ->and($quiet['enrolment_note'])->toBeNull();
});

test('patching a b2b partner is not a route', function (): void {
    $crm = b2bCrmUser();
    $agency = Agency::factory()->create(['commission_pct' => 10]);

    $this->actingAs($crm)
        ->patchJson('/api/crm/b2b-partners/'.$agency->id, ['commission_pct' => 99])
        ->assertStatus(405);

    expect($agency->fresh()->commission_pct)->toBe(10);
});

test('a user without panel.crm cannot read b2b partners', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);
    $agency = Agency::factory()->create();

    $this->actingAs($user)->getJson('/api/crm/b2b-partners')->assertForbidden();
    $this->actingAs($user)->getJson('/api/crm/b2b-partners/'.$agency->id)->assertForbidden();
});

function b2bCrmUser(): User
{
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelCrm],
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}

function b2bActivate(string $key): void
{
    Journey::query()->where('key', $key)->update(['active' => true]);
}

function b2bContact(): Contact
{
    $contact = Contact::factory()->create();
    app(RecordContactConsent::class)->handle(
        $contact,
        ConsentPurpose::Marketing,
        true,
        'b2b-partner-test',
        ConsentCapturePoint::EngineForm,
    );

    return $contact;
}

function b2bBooking(Contact $contact, User $owner, BookingStatus $status, string $date, int $total): Booking
{
    $departure = ReservationFixtures::anamaraDeparture($date);
    $cabin = $departure->property->cabins->firstOrFail();

    return Booking::factory()->create([
        'departure_id' => $departure->id,
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
