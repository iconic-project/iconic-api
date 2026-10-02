<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

test('forgot password always returns the same message', function (string $email): void {
    Notification::fake();

    User::factory()->withRole(SystemRole::Admin)->create([
        'email' => 'active@iconic.test',
    ]);
    User::factory()->disabled()->withRole(SystemRole::Admin)->create([
        'email' => 'disabled@iconic.test',
    ]);

    withPanelCsrf()->postJson('/api/auth/forgot-password', [
        'email' => $email,
    ])
        ->assertOk()
        ->assertJsonPath('message', __('passwords.sent'));
})->with([
    'known' => ['active@iconic.test'],
    'unknown' => ['missing@iconic.test'],
    'disabled' => ['disabled@iconic.test'],
]);

test('forgot password notifies only an active user', function (): void {
    Notification::fake();

    $active = User::factory()->withRole(SystemRole::Admin)->create([
        'email' => 'active@iconic.test',
    ]);
    $disabled = User::factory()->disabled()->withRole(SystemRole::Admin)->create([
        'email' => 'disabled@iconic.test',
    ]);

    withPanelCsrf()->postJson('/api/auth/forgot-password', [
        'email' => $active->email,
    ])->assertOk();

    withPanelCsrf()->postJson('/api/auth/forgot-password', [
        'email' => $disabled->email,
    ])->assertOk();

    withPanelCsrf()->postJson('/api/auth/forgot-password', [
        'email' => 'missing@iconic.test',
    ])->assertOk();

    Notification::assertSentTo($active, ResetPasswordNotification::class);
    Notification::assertNotSentTo($disabled, ResetPasswordNotification::class);
    Notification::assertSentTimes(ResetPasswordNotification::class, 1);
});

test('forgot password is rate limited after six attempts per ip', function (): void {
    Notification::fake();

    for ($i = 0; $i < 6; $i++) {
        withPanelCsrf()->postJson('/api/auth/forgot-password', [
            'email' => "user{$i}@iconic.test",
        ])->assertOk();
    }

    withPanelCsrf()->postJson('/api/auth/forgot-password', [
        'email' => 'another@iconic.test',
    ])->assertStatus(429);
});
