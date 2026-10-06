<?php

declare(strict_types=1);

use App\Enums\ChannelOfOrigin;
use App\Enums\ChannelOfOriginGroup;
use App\Enums\MainChannel;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('sales exec can read booking form options', function (): void {
    $response = $this->actingAs(salesExecUser())
        ->getJson('/api/rms/bookings/form-options')
        ->assertOk();

    $main = collect($response->json('main'));
    expect($main->pluck('value')->all())->toBe(array_map(
        fn (MainChannel $channel): string => $channel->value,
        MainChannel::cases(),
    ));
    expect($main->firstWhere('value', MainChannel::B2B->value)['trade'])->toBeTrue();
    expect($main->firstWhere('value', MainChannel::D2C->value)['trade'])->toBeFalse();
    expect($main->firstWhere('value', MainChannel::Partners->value)['trade'])->toBeFalse();

    $origin = $response->json('origin');
    expect(collect($origin)->pluck('group')->all())->toBe(array_map(
        fn (ChannelOfOriginGroup $group): string => $group->value,
        ChannelOfOriginGroup::cases(),
    ));

    $byGroup = [];

    foreach (ChannelOfOrigin::cases() as $channel) {
        $byGroup[$channel->group()->value][] = $channel->value;
    }

    foreach ($origin as $group) {
        expect(collect($group['options'])->pluck('value')->all())
            ->toBe($byGroup[$group['group']]);
    }

    $response
        ->assertJsonPath('preferred.0.value', 'EMAIL')
        ->assertJsonPath('preferred.0.label', 'Email')
        ->assertJsonPath('guests.child_min_age', 6)
        ->assertJsonPath('guests.child_max_age', 17)
        ->assertJsonPath('guests.max_per_property', 16)
        ->assertJsonPath('commission.cap_pct', 12)
        ->assertJsonPath('commission.default_pct', 10)
        ->assertJsonPath('payments.wire_window_hours', 72)
        ->assertJsonPath('agencies', []);
});

test('form options require bookings.create', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/rms/bookings/form-options')
        ->assertForbidden();
});
