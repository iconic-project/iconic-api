<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\ChangeHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

test('reset password with a valid token updates the password and writes history', function (): void {
    $user = User::factory()->withRole(SystemRole::Admin)->create([
        'email' => 'active@iconic.test',
    ]);
    $token = Password::broker()->createToken($user);

    withPanelCsrf()->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])
        ->assertOk()
        ->assertJsonPath('message', __('passwords.reset'));

    expect(Hash::check('new-password-12', $user->fresh()?->getAuthPassword() ?? ''))->toBeTrue();
    $this->assertGuest();

    $entry = ChangeHistory::query()->where('event', 'user.password_reset')->first();
    expect($entry)->not->toBeNull();
    expect($entry?->actor_id)->toBe($user->id);
    expect($entry?->actor_label)->toBe($user->name);
});

test('reset password rejects expired and used tokens', function (): void {
    $user = User::factory()->withRole(SystemRole::Admin)->create();
    $token = Password::broker()->createToken($user);

    $this->travel(61)->minutes();

    withPanelCsrf()->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token']);

    $this->travelBack();

    $fresh = Password::broker()->createToken($user);

    withPanelCsrf()->postJson('/api/auth/reset-password', [
        'token' => $fresh,
        'email' => $user->email,
        'password' => 'new-password-12',
        'password_confirmation' => 'new-password-12',
    ])->assertOk();

    withPanelCsrf()->postJson('/api/auth/reset-password', [
        'token' => $fresh,
        'email' => $user->email,
        'password' => 'other-password-12',
        'password_confirmation' => 'other-password-12',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});
