<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('a limited manager cannot escalate privileges', function (): void {
    $limited = limitedAdminRole();
    $actor = User::factory()->create(['role_id' => $limited->id]);
    $adminRole = Role::query()->where('slug', SystemRole::Admin->value)->firstOrFail();
    $managerRole = Role::query()->where('slug', SystemRole::Manager->value)->firstOrFail();
    $disabledAdmin = User::factory()->disabled()->withRole(SystemRole::Admin)->create();
    $mateo = managerUser(['name' => 'Mateo R.']);

    $this->actingAs($actor)
        ->postJson('/api/rms/users', [
            'name' => 'Nope',
            'email' => 'nope@iconic.test',
            'role_id' => $adminRole->id,
        ])
        ->assertForbidden();

    $peer = User::factory()->create(['role_id' => $limited->id]);

    $this->actingAs($actor)
        ->patchJson("/api/rms/users/{$peer->id}", ['role_id' => $managerRole->id])
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson('/api/rms/roles', [
            'name' => 'Director copy',
            'permissions' => [Permission::RefundsApprove->value],
        ])
        ->assertForbidden();

    $this->actingAs($actor)
        ->patchJson("/api/rms/roles/{$limited->id}", [
            'permissions' => [
                Permission::PanelRms->value,
                Permission::UsersManage->value,
                Permission::RolesManage->value,
                Permission::RefundsApprove->value,
            ],
        ])
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson("/api/rms/users/{$disabledAdmin->id}/enable")
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson("/api/rms/users/{$mateo->id}/disable")
        ->assertForbidden();

    $this->actingAs($actor)
        ->patchJson("/api/rms/roles/{$managerRole->id}", [
            'permissions' => [Permission::PanelRms->value],
        ])
        ->assertForbidden();
});

test('a limited manager can manage a user or role within its own permissions', function (): void {
    $limited = limitedAdminRole();
    $actor = User::factory()->create(['role_id' => $limited->id]);
    $subset = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);
    $peer = User::factory()->invited()->create(['role_id' => $subset->id]);

    $this->actingAs($actor)
        ->postJson('/api/rms/users', [
            'name' => 'Inside',
            'email' => 'inside@iconic.test',
            'role_id' => $subset->id,
        ])
        ->assertCreated()
        ->assertJsonPath('role.id', $subset->id);

    $this->actingAs($actor)
        ->patchJson("/api/rms/users/{$peer->id}", ['name' => 'Peer'])
        ->assertOk()
        ->assertJsonPath('name', 'Peer');

    $this->actingAs($actor)
        ->postJson("/api/rms/users/{$peer->id}/resend-invitation")
        ->assertOk();

    $this->actingAs($actor)
        ->postJson("/api/rms/users/{$peer->id}/disable")
        ->assertOk()
        ->assertJsonPath('status', UserStatus::Disabled->value);

    $this->actingAs($actor)
        ->postJson("/api/rms/users/{$peer->id}/enable")
        ->assertOk()
        ->assertJsonPath('status', UserStatus::Invited->value);

    $this->actingAs($actor)
        ->postJson('/api/rms/roles', [
            'name' => 'Nested',
            'permissions' => [Permission::PanelRms->value],
        ])
        ->assertCreated();

    $this->actingAs($actor)
        ->patchJson("/api/rms/roles/{$subset->id}", [
            'description' => 'Still within bounds',
        ])
        ->assertOk()
        ->assertJsonPath('description', 'Still within bounds');

    $empty = Role::factory()->create(['permissions' => []]);

    $this->actingAs($actor)
        ->deleteJson("/api/rms/roles/{$empty->id}")
        ->assertNoContent();
});

test('an admin can perform the privilege changes a limited manager cannot', function (): void {
    $admin = adminUser();
    $limited = limitedAdminRole();
    $adminRole = Role::query()->where('slug', SystemRole::Admin->value)->firstOrFail();
    $managerRole = Role::query()->where('slug', SystemRole::Manager->value)->firstOrFail();
    $disabledAdmin = User::factory()->disabled()->withRole(SystemRole::Admin)->create();
    $mateo = managerUser();
    $secondAdmin = adminUser(['name' => 'Other admin']);

    $this->actingAs($admin)
        ->postJson('/api/rms/users', [
            'name' => 'Another admin',
            'email' => 'another-admin@iconic.test',
            'role_id' => $adminRole->id,
        ])
        ->assertCreated();

    $this->actingAs($admin)
        ->patchJson("/api/rms/users/{$mateo->id}", ['role_id' => $managerRole->id])
        ->assertOk();

    $this->actingAs($admin)
        ->postJson('/api/rms/roles', [
            'name' => 'Approver',
            'permissions' => [Permission::RefundsApprove->value],
        ])
        ->assertCreated();

    $this->actingAs($admin)
        ->patchJson("/api/rms/roles/{$limited->id}", [
            'permissions' => [
                Permission::PanelRms->value,
                Permission::UsersManage->value,
                Permission::RolesManage->value,
                Permission::RefundsApprove->value,
            ],
        ])
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/rms/users/{$disabledAdmin->id}/enable")
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/rms/users/{$mateo->id}/disable")
        ->assertOk();

    $this->actingAs($admin)
        ->patchJson("/api/rms/roles/{$managerRole->id}", [
            'permissions' => [Permission::PanelRms->value],
        ])
        ->assertOk();

    expect($secondAdmin->fresh())->not->toBeNull();
});
