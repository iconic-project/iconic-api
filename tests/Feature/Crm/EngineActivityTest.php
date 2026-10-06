<?php

declare(strict_types=1);

use App\Enums\BehaviouralEventName;
use App\Enums\Permission;
use App\Models\BehaviouralEvent;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('activity filters, sides and KPIs agree with the list', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create(['name' => 'E. Harmon']);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-21');
    $itinerary = $departure->itinerary;

    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => BehaviouralEventName::SubmitBookingRequest,
        'params' => [
            'itinerary_code' => $itinerary->code,
            'departure_id' => $departure->id,
            'cabin_count' => 1,
        ],
        'occurred_at' => now(),
    ]);
    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => BehaviouralEventName::BeginCheckout,
        'params' => [
            'itinerary_code' => $itinerary->code,
            'departure_id' => $departure->id,
        ],
        'occurred_at' => now()->subMinute(),
    ]);
    BehaviouralEvent::factory()->create([
        'contact_id' => null,
        'name' => BehaviouralEventName::ViewItinerary,
        'params' => ['itinerary_code' => $itinerary->code],
        'occurred_at' => now()->subMinutes(2),
    ]);
    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => BehaviouralEventName::PageView,
        'params' => ['page_path' => '/about'],
        'occurred_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($actor)
        ->getJson('/api/crm/activity')
        ->assertOk();

    assertNoSensitiveFields($response);

    $rows = collect($response->json('data'));
    expect($rows)->toHaveCount(4);
    expect($rows->firstWhere('name', 'submit_booking_request')['side'])->toBe('RMS + CRM');
    expect($rows->firstWhere('name', 'begin_checkout')['side'])->toBe('RMS + CRM');
    expect($rows->firstWhere('name', 'view_itinerary')['contact'])->toBe('anonymous');
    expect($rows->firstWhere('name', 'view_itinerary')['contact_id'])->toBeNull();
    expect($rows->firstWhere('name', 'submit_booking_request')['contact'])->toBe('E. Harmon');
    expect($rows->firstWhere('name', 'submit_booking_request')['contact_id'])->toBe($contact->id);
    expect($rows->firstWhere('name', 'view_itinerary')['detail'])->toBe($itinerary->name);

    $kpis = $response->json('meta.kpis');
    expect($kpis['identified'])->toBe(3);
    expect($kpis['anonymous'])->toBe(1);
    expect($kpis['identified'] + $kpis['anonymous'])->toBe($response->json('meta.total'));
    expect($kpis['inventory_touching'])->toBe(2);
    expect($kpis['web_hold_minutes'])->toBe(20);
    expect($kpis['web_hold_extension_minutes'])->toBe(10);

    $named = $this->actingAs($actor)
        ->getJson('/api/crm/activity?name=view_itinerary')
        ->assertOk();

    expect($named->json('data'))->toHaveCount(1);
    expect($named->json('meta.kpis.identified'))->toBe(0);
    expect($named->json('meta.kpis.anonymous'))->toBe(1);

    $identified = $this->actingAs($actor)
        ->getJson('/api/crm/activity?identified=1')
        ->assertOk();

    expect($identified->json('data'))->toHaveCount(3);
    expect($identified->json('meta.kpis.identified'))->toBe(3);
    expect($identified->json('meta.kpis.anonymous'))->toBe(0);

    $this->actingAs($actor)
        ->getJson('/api/crm/activity?name=not_a_real_event')
        ->assertUnprocessable();
});

test('a stay search is described from its dates and room type', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create(['name' => 'Stay Searcher']);

    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => BehaviouralEventName::SearchPerformed,
        'params' => [
            'check_in' => '2027-11-07',
            'check_out' => '2027-11-14',
            'adults' => 2,
            'children' => 0,
            'rooms' => 1,
        ],
        'occurred_at' => now(),
    ]);
    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => BehaviouralEventName::RoomTypeViewed,
        'params' => ['room_type' => 'STE'],
        'occurred_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($actor)->getJson('/api/crm/activity')->assertOk();
    assertNoSensitiveFields($response);

    $rows = collect($response->json('data'));
    expect($rows->firstWhere('name', 'search_performed')['detail'])->toContain('7 Nov 2027')
        ->and($rows->firstWhere('name', 'search_performed')['detail'])->toContain('14 Nov 2027')
        ->and($rows->firstWhere('name', 'search_performed')['detail'])->toContain('1 room')
        ->and($rows->firstWhere('name', 'room_type_viewed')['detail'])->toBe('STE');
});

test('events today uses the Galapagos calendar day', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-22 03:00:00', 'UTC'));

    $actor = salesExecUser();

    BehaviouralEvent::factory()->create([
        'contact_id' => null,
        'name' => BehaviouralEventName::PageView,
        'params' => ['page_path' => '/today'],
        'occurred_at' => CarbonImmutable::parse('2026-09-22 01:00:00', 'UTC'),
    ]);
    BehaviouralEvent::factory()->create([
        'contact_id' => null,
        'name' => BehaviouralEventName::PageView,
        'params' => ['page_path' => '/tomorrow'],
        'occurred_at' => CarbonImmutable::parse('2026-09-22 07:00:00', 'UTC'),
    ]);

    expect(BusinessTime::now()->toDateString())->toBe('2026-09-21');

    $response = $this->actingAs($actor)
        ->getJson('/api/crm/activity')
        ->assertOk();

    expect($response->json('meta.kpis.events_today'))->toBe(1);
    expect($response->json('meta.total'))->toBe(2);

    Carbon::setTestNow();
});

test('a user without panel.crm cannot read activity', function (): void {
    $role = Role::factory()->create(['permissions' => [Permission::PanelRms]]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/crm/activity')
        ->assertForbidden();
});
