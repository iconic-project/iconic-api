<?php

declare(strict_types=1);

use App\Actions\Auth\LoginUser;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use ReflectionClass;

function failedLoginBody(): array
{
    return [
        'message' => __('auth.failed'),
        'errors' => [
            'email' => [__('auth.failed')],
        ],
    ];
}

test('login returns the me payload and sets last_login_at', function (): void {
    $user = User::factory()->withRole(SystemRole::Admin)->create([
        'name' => 'Carolina M.',
        'email' => 'carolina@iconic.test',
    ]);

    $response = withPanelCsrf()->postJson('/api/auth/login', [
        'email' => 'Carolina@Iconic.test',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('name', 'Carolina M.')
        ->assertJsonPath('email', 'carolina@iconic.test')
        ->assertJsonPath('role.slug', 'admin')
        ->assertJsonPath('sections', ['rms', 'crm'])
        ->assertJsonPath('time_zone', 'Pacific/Galapagos');

    expect($response->json('permissions'))->toEqualCanonicalizing(
        collect(Permission::cases())->map->value->all(),
    );

    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()?->last_login_at)->not->toBeNull();
});

test('the four login failures return the same body', function (string $email, string $password): void {
    User::factory()->invited()->withRole(SystemRole::Admin)->create([
        'email' => 'invited@iconic.test',
    ]);
    User::factory()->disabled()->withRole(SystemRole::Admin)->create([
        'email' => 'disabled@iconic.test',
    ]);
    User::factory()->withRole(SystemRole::Admin)->create([
        'email' => 'active@iconic.test',
    ]);

    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => $email,
        'password' => $password,
    ])
        ->assertStatus(422)
        ->assertExactJson(failedLoginBody());
})->with([
    'unknown email' => ['missing@iconic.test', 'password'],
    'wrong password' => ['active@iconic.test', 'not-the-password'],
    'invited' => ['invited@iconic.test', 'password'],
    'disabled' => ['disabled@iconic.test', 'password'],
]);

test('login is rate limited after five attempts per email and ip', function (): void {
    $user = User::factory()->withRole(SystemRole::Admin)->create([
        'email' => 'limited@iconic.test',
    ]);

    for ($i = 0; $i < 5; $i++) {
        withPanelCsrf()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);
});

test('login while already signed in switches the session', function (): void {
    $carolina = User::factory()->withRole(SystemRole::Admin)->create([
        'email' => 'carolina@iconic.test',
    ]);
    $mateo = User::factory()->withRole(SystemRole::Manager)->create([
        'email' => 'mateo@iconic.test',
    ]);

    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => $carolina->email,
        'password' => 'password',
    ])->assertOk();

    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => $mateo->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('id', $mateo->id);

    $this->withHeaders(panelHeaders())
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('id', $mateo->id);
});

test('the dummy login hash uses the same bcrypt cost as real passwords', function (): void {
    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => 'missing@iconic.test',
        'password' => 'password',
    ])->assertStatus(422);

    $hash = (new ReflectionClass(LoginUser::class))->getStaticPropertyValue('dummyPasswordHash');

    expect($hash)->toBeString();
    expect(password_get_info($hash)['options']['cost'])
        ->toBe(password_get_info(Hash::make('password'))['options']['cost']);
});

test('cfo me payload lists only the rms section', function (): void {
    $role = Role::factory()->create([
        'name' => 'External finance',
        'slug' => 'external-finance',
        'permissions' => [
            Permission::PanelRms,
            Permission::BookingsViewAll,
            Permission::PaymentsMarkWireReceived,
            Permission::RefundsExecute,
        ],
    ]);
    $user = User::factory()->create([
        'name' => 'CFO (external)',
        'email' => 'cfo@iconic.test',
        'role_id' => $role->id,
        'status' => UserStatus::Active,
    ]);

    withPanelCsrf()->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('sections', ['rms']);
});
