<?php

declare(strict_types=1);

use Database\Seeders\RolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function runRemoveRetiredPermissions(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_06_190002_remove_legacy_permissions.php');
    $migration->up();
}

test('the permission migration removes retired strings and logs the role', function (): void {
    $this->seed(RolesSeeder::class);

    $role = DB::table('roles')->where('slug', 'manager')->first();
    $permissions = json_decode((string) $role->permissions, true);
    $permissions[] = 'departures.manage';
    $permissions[] = 'itineraries.manage';

    DB::table('roles')->where('id', $role->id)->update([
        'permissions' => json_encode($permissions),
    ]);

    runRemoveRetiredPermissions();

    $stored = json_decode((string) DB::table('roles')->where('id', $role->id)->value('permissions'), true);

    expect($stored)->not->toContain('departures.manage')
        ->and($stored)->not->toContain('itineraries.manage')
        ->and($stored)->toContain('bookings.create');
});

test('the permission migration leaves a role alone when nothing retired is present', function (): void {
    $this->seed(RolesSeeder::class);

    $before = DB::table('roles')->orderBy('id')->pluck('permissions', 'id');

    runRemoveRetiredPermissions();

    $after = DB::table('roles')->orderBy('id')->pluck('permissions', 'id');

    expect($after->all())->toBe($before->all());
});
