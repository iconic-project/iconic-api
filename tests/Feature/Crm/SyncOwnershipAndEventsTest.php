<?php

declare(strict_types=1);

use App\Enums\BehaviouralEventName;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Support\Crm\EventCatalogue;
use App\Support\Crm\FieldOwnership;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('ownership is the implemented contract, not a mirror table', function (): void {
    $response = $this->actingAs(salesExecUser())
        ->getJson('/api/crm/sync/ownership')
        ->assertOk();

    assertNoSensitiveFields($response);

    $rows = collect($response->json('data'));
    expect($rows)->toHaveCount(count(FieldOwnership::rows()));
    expect($rows->pluck('object')->all())->toBe(array_column(FieldOwnership::rows(), 'object'));

    $booking = $rows->firstWhere('object', 'Booking');
    expect($booking['read_by'])->toContain('read directly from the booking tables');
    expect($booking['code'])->toBe('App\\Models\\Booking');

    $guest = $rows->firstWhere('object', 'Guest personal data');
    expect($guest['read_by'])->toBe('Not replicated');
    expect($guest['code'])->toBe('App\\Support\\SensitiveFields');

    $deals = $rows->firstWhere('object', 'Deal & pipeline');
    expect($deals['rule'])->toContain('Stages 1–4 are stored');
    expect($deals['code'])->toBe('App\\Models\\Deal');
});

test('the event catalogue lists every domain event and behavioural name', function (): void {
    $files = collect(glob(app_path('Events/*.php')) ?: [])
        ->map(fn (string $path): string => basename($path, '.php'))
        ->sort()
        ->values()
        ->all();

    $catalogued = collect(EventCatalogue::domain())->pluck('name')->sort()->values()->all();
    expect($catalogued)->toBe($files);

    $response = $this->actingAs(salesExecUser())
        ->getJson('/api/crm/sync/events')
        ->assertOk();

    assertNoSensitiveFields($response);

    $names = collect($response->json('data'))->pluck('name');
    foreach (BehaviouralEventName::cases() as $name) {
        expect($names)->toContain($name->value);
    }

    $stitched = collect($response->json('data'))->firstWhere('name', 'identity.stitched');
    expect($stitched['producer'])->toBe('StitchEngineIdentity');
    expect($stitched['listeners'])->toBe([]);

    $status = collect($response->json('data'))->firstWhere('name', 'BookingStatusChanged');
    expect($status['producer'])->toBe('TransitionBooking');
    expect($status['listeners'])->toContain('SendOnBookingStatusChanged');

    expect($response->json('meta.note'))->toContain('no event bus');
});

test('identity lists the merge log', function (): void {
    $actor = managerUser();
    $survivor = Contact::factory()->create(['email' => 'keep@iconic.test']);
    $loser = Contact::factory()->create(['email' => 'drop@iconic.test']);

    $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'Duplicate email',
        ])
        ->assertOk();

    $response = $this->actingAs($actor)
        ->getJson('/api/crm/sync/identity')
        ->assertOk();

    assertNoSensitiveFields($response);

    $row = $response->json('data.0');
    expect($row['survivor_id'])->toBe($survivor->id);
    expect($row['loser_id'])->toBe($loser->id);
    expect($row['reason'])->toBe('Duplicate email');
    expect($row['merged_by'])->toBe($actor->name);
    expect($row['undone'])->toBeFalse();
});

test('a user without panel.crm cannot read sync catalogues', function (): void {
    $role = Role::factory()->create(['permissions' => [Permission::PanelRms]]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->getJson('/api/crm/sync/ownership')->assertForbidden();
    $this->actingAs($user)->getJson('/api/crm/sync/events')->assertForbidden();
    $this->actingAs($user)->getJson('/api/crm/sync/identity')->assertForbidden();
});
