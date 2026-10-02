<?php

declare(strict_types=1);

use App\Actions\Auth\SendUserInvitation;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Enums\UserStatus;
use App\Models\ChangeHistory;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvitation;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('an admin can list users with filters and default pagination', function (): void {
    $carolina = adminUser(['name' => 'Carolina M.', 'email' => 'carolina@iconic.test']);
    $mateo = managerUser(['name' => 'Mateo R.', 'email' => 'mateo@iconic.test']);
    salesExecUser(['name' => 'Lucia B.', 'email' => 'lucia@iconic.test']);

    $response = $this->actingAs($carolina)->getJson('/api/rms/users');
    $response->assertOk()
        ->assertJsonPath('meta.per_page', 25);

    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('data.0.role.slug'))->toBeString();
    expect($response->json('data.0.flags'))->toBeArray();

    $this->actingAs($carolina)
        ->getJson('/api/rms/users?status=active&role_id='.$mateo->role_id.'&q=mateo')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'mateo@iconic.test');

    $this->actingAs($carolina)
        ->getJson('/api/rms/users?per_page=101')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);
});

test('admin users include every flag permission', function (): void {
    $carolina = adminUser();

    $row = $this->actingAs($carolina)
        ->getJson('/api/rms/users?q='.$carolina->email)
        ->json('data.0');

    $flags = collect(Permission::cases())
        ->filter(fn (Permission $permission): bool => $permission->isFlag())
        ->map(fn (Permission $permission): string => $permission->value)
        ->values()
        ->all();

    expect($row['flags'])->toEqual($flags);
});

test('inviting a user sends mail, writes history and can be accepted', function (): void {
    Notification::fake();

    $carolina = adminUser(['name' => 'Carolina M.']);
    $manager = Role::query()->where('slug', SystemRole::Manager->value)->firstOrFail();

    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => $carolina->email,
        'password' => 'password',
    ])->assertOk();

    $this->postJson('/api/rms/users', [
        'name' => 'New hire',
        'email' => 'New@Iconic.test',
        'role_id' => $manager->id,
    ])
        ->assertCreated()
        ->assertJsonPath('email', 'new@iconic.test')
        ->assertJsonPath('status', UserStatus::Invited->value)
        ->assertJsonPath('role.slug', 'manager');

    $invitee = User::query()->where('email', 'new@iconic.test')->first();
    expect($invitee)->not->toBeNull();

    $token = null;
    Notification::assertSentTo($invitee, UserInvitation::class, function (UserInvitation $notification) use ($invitee, &$token): bool {
        $token = $notification->token;
        $mail = $notification->toMail($invitee);
        $url = SendUserInvitation::url($invitee, $notification->token);

        expect($notification->roleName)->toBe('Manager');
        expect($mail->actionUrl)->toBe($url);
        expect($url)->toContain('/accept-invitation');
        expect($url)->toContain('token=');
        expect($url)->toContain('email=new%40iconic.test');
        expect(collect($mail->introLines)->implode(' '))->toContain('Manager');
        expect(collect($mail->outroLines)->implode(' '))->toContain('7 days');

        return true;
    });

    $invited = ChangeHistory::query()->where('event', 'user.invited')->where('subject_id', $invitee?->id)->first();
    expect($invited?->actor_id)->toBe($carolina->id);
    expect($invited?->after)->toBe(['role' => 'Manager']);

    Auth::forgetGuards();
    Auth::shouldUse('web');

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $token,
        'email' => 'new@iconic.test',
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])->assertOk();

    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => $carolina->email,
        'password' => 'password',
    ])->assertOk();

    $history = $this->getJson("/api/rms/users/{$invitee?->id}/history")
        ->assertOk()
        ->json('data');

    $events = collect($history)->pluck('event')->all();
    expect($events)->toContain('user.invited');
    expect($events)->toContain('user.activated');
});

test('invite validation rejects missing role, unknown role and duplicate email', function (): void {
    $admin = adminUser(['email' => 'carolina@iconic.test']);
    $manager = Role::query()->where('slug', SystemRole::Manager->value)->firstOrFail();

    $this->actingAs($admin)
        ->postJson('/api/rms/users', [
            'name' => 'X',
            'email' => 'x@iconic.test',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role_id']);

    $this->actingAs($admin)
        ->postJson('/api/rms/users', [
            'name' => 'X',
            'email' => 'x@iconic.test',
            'role_id' => 999999,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role_id']);

    $this->actingAs($admin)
        ->postJson('/api/rms/users', [
            'name' => 'X',
            'email' => 'Carolina@Iconic.test',
            'role_id' => $manager->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('updating a user can write name and role history separately', function (): void {
    $admin = adminUser();
    $user = salesExecUser(['name' => 'Lucia']);
    $manager = Role::query()->where('slug', SystemRole::Manager->value)->firstOrFail();

    $this->actingAs($admin)
        ->patchJson("/api/rms/users/{$user->id}", [
            'name' => 'Lucía B.',
            'role_id' => $manager->id,
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Lucía B.')
        ->assertJsonPath('role.slug', 'manager');

    $updated = ChangeHistory::query()->where('event', 'user.updated')->where('subject_id', $user->id)->first();
    expect($updated?->before)->toBe(['name' => 'Lucia']);
    expect($updated?->after)->toBe(['name' => 'Lucía B.']);
    expect($updated?->actor_id)->toBe($admin->id);

    $changed = ChangeHistory::query()->where('event', 'user.role_changed')->where('subject_id', $user->id)->first();
    expect($changed?->before)->toBe(['role' => 'Sales Exec']);
    expect($changed?->after)->toBe(['role' => 'Manager']);
});

test('a no-op user patch writes no history', function (): void {
    $user = salesExecUser(['name' => 'Lucia']);

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/users/{$user->id}", [
            'name' => 'Lucia',
            'role_id' => $user->role_id,
        ])
        ->assertOk();

    expect(ChangeHistory::query()->whereIn('event', ['user.updated', 'user.role_changed'])->count())->toBe(0);
});

test('update user rejects a null role_id', function (): void {
    $user = salesExecUser();

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/users/{$user->id}", ['role_id' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role_id']);
});

test('disable and enable write history and are no-ops when already in that state', function (): void {
    $admin = adminUser();
    $user = salesExecUser();

    $this->actingAs($admin)
        ->postJson("/api/rms/users/{$user->id}/disable", ['reason' => 'Left the team'])
        ->assertOk()
        ->assertJsonPath('status', UserStatus::Disabled->value);

    $disabled = ChangeHistory::query()->where('event', 'user.disabled')->where('subject_id', $user->id)->first();
    expect($disabled?->reason)->toBe('Left the team');
    expect($disabled?->actor_id)->toBe($admin->id);

    $this->actingAs($admin)
        ->postJson("/api/rms/users/{$user->id}/disable")
        ->assertOk();

    expect(ChangeHistory::query()->where('event', 'user.disabled')->where('subject_id', $user->id)->count())->toBe(1);

    $this->actingAs($admin)
        ->postJson("/api/rms/users/{$user->id}/enable")
        ->assertOk()
        ->assertJsonPath('status', UserStatus::Active->value);

    expect(ChangeHistory::query()->where('event', 'user.enabled')->where('subject_id', $user->id)->count())->toBe(1);

    $this->actingAs($admin)
        ->postJson("/api/rms/users/{$user->id}/enable")
        ->assertOk();

    expect(ChangeHistory::query()->where('event', 'user.enabled')->where('subject_id', $user->id)->count())->toBe(1);
});

test('enabling a never-accepted user returns them to invited', function (): void {
    $admin = adminUser();
    $user = User::factory()->invited()->disabled()->withRole(SystemRole::SalesExec)->create();

    $this->actingAs($admin)
        ->postJson("/api/rms/users/{$user->id}/enable")
        ->assertOk()
        ->assertJsonPath('status', UserStatus::Invited->value);
});

test('resend invitation replaces the token and writes history', function (): void {
    Notification::fake();

    $admin = adminUser();
    $user = User::factory()->invited()->withRole(SystemRole::Manager)->create();
    $old = Password::broker('invitations')->createToken($user);

    $this->actingAs($admin)
        ->postJson("/api/rms/users/{$user->id}/resend-invitation")
        ->assertOk();

    $resent = ChangeHistory::query()->where('event', 'user.invitation_resent')->where('subject_id', $user->id)->first();
    expect($resent?->actor_id)->toBe($admin->id);

    $newToken = null;
    Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use (&$newToken): bool {
        $newToken = $notification->token;

        return true;
    });

    expect($newToken)->not->toBe($old);
});

test('resend invitation is forbidden for users who are not invited', function (): void {
    $user = salesExecUser();

    $this->actingAs(adminUser())
        ->postJson("/api/rms/users/{$user->id}/resend-invitation")
        ->assertStatus(409)
        ->assertJsonPath('message', 'An invitation can only be resent to an invited user.');
});
