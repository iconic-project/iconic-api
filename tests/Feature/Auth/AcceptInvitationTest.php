<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Enums\UserStatus;
use App\Models\ChangeHistory;
use App\Models\User;
use Illuminate\Support\Facades\Password;

test('accept invitation activates the user, writes history and signs them in', function (): void {
    $user = User::factory()->invited()->withRole(SystemRole::Manager)->create([
        'email' => 'invitee@iconic.test',
    ]);
    $token = Password::broker('invitations')->createToken($user);

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', 'invitee@iconic.test');

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Active);
    expect($user->activated_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);

    $entry = ChangeHistory::query()->where('event', 'user.activated')->first();
    expect($entry)->not->toBeNull();
    expect($entry?->actor_id)->toBe($user->id);
    expect($entry?->actor_label)->toBe($user->name);
});

test('accept invitation expires after seven days and cannot be reused', function (): void {
    $user = User::factory()->invited()->withRole(SystemRole::Admin)->create();
    $token = Password::broker('invitations')->createToken($user);

    $this->travelTo(now()->addDays(7)->addMinute());

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token']);

    $this->travelBack();

    $fresh = User::factory()->invited()->withRole(SystemRole::Admin)->create();
    $usable = Password::broker('invitations')->createToken($fresh);

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $usable,
        'email' => $fresh->email,
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])->assertOk();

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $usable,
        'email' => $fresh->email,
        'password' => 'other-password-12',
        'password_confirmation' => 'other-password-12',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});

test('accepting an invitation while signed in as carolina returns the new user', function (): void {
    $carolina = User::factory()->withRole(SystemRole::Admin)->create([
        'name' => 'Carolina M.',
        'email' => 'carolina@iconic.test',
    ]);
    $invitee = User::factory()->invited()->withRole(SystemRole::SalesExec)->create([
        'email' => 'newhire@iconic.test',
    ]);
    $token = Password::broker('invitations')->createToken($invitee);

    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => $carolina->email,
        'password' => 'password',
    ])->assertOk();

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $token,
        'email' => $invitee->email,
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])
        ->assertOk()
        ->assertJsonPath('id', $invitee->id);

    $this->withHeaders(panelHeaders())
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('id', $invitee->id);

    $entry = ChangeHistory::query()->where('event', 'user.activated')->first();
    expect($entry?->actor_id)->toBe($invitee->id);
});

test('accept invitation requires at least eight characters', function (): void {
    $user = User::factory()->invited()->withRole(SystemRole::Manager)->create();
    $token = Password::broker('invitations')->createToken($user);

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $token,
        'email' => $user->email,
        'password' => '1234567',
        'password_confirmation' => '1234567',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $token,
        'email' => $user->email,
        'password' => '12345678',
        'password_confirmation' => '12345678',
    ])->assertOk();
});

test('an invited then disabled user cannot accept', function (): void {
    $user = User::factory()->invited()->withRole(SystemRole::Admin)->create();
    $token = Password::broker('invitations')->createToken($user);

    $user->forceFill([
        'status' => UserStatus::Disabled,
        'disabled_at' => now(),
    ])->save();

    withPanelCsrf()->postJson('/api/auth/accept-invitation', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});
