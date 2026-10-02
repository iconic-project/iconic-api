<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\ChangeHistory;
use App\Models\User;
use App\Notifications\UserInvitation;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Notification;

test('create-admin invites an admin and prints the panel link', function (): void {
    $this->seed(RolesSeeder::class);
    Notification::fake();

    $this->artisan('iconic:create-admin', [
        'email' => 'You@Example.com',
        'name' => 'You',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('http://localhost:3001/accept-invitation');

    $user = User::query()->where('email', 'you@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user?->status)->toBe(UserStatus::Invited);
    expect($user?->password)->toBeNull();
    expect($user?->role?->slug)->toBe('admin');

    Notification::assertSentTo($user, UserInvitation::class);

    $entry = ChangeHistory::query()->where('event', 'user.invited')->first();
    expect($entry)->not->toBeNull();
    expect($entry?->actor_id)->toBeNull();
    expect($entry?->actor_label)->toBe('System');
    expect($entry?->after)->toBe(['role' => 'Admin']);
});

test('create-admin refuses a duplicate email', function (): void {
    $this->seed(RolesSeeder::class);
    Notification::fake();

    User::factory()->create(['email' => 'you@example.com']);

    $this->artisan('iconic:create-admin', [
        'email' => 'you@example.com',
        'name' => 'You',
    ])
        ->assertFailed()
        ->expectsOutputToContain('already exists');
});
