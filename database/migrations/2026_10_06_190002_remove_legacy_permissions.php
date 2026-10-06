<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drops yacht inventory permissions from every role. Roles themselves stay.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const REMOVED = [
        'departures.manage',
        'itineraries.manage',
    ];

    public function up(): void
    {
        $roles = DB::table('roles')->orderBy('id')->get(['id', 'slug', 'permissions']);

        foreach ($roles as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions)) {
                continue;
            }

            $kept = [];
            $removed = [];

            foreach ($permissions as $permission) {
                if (! is_string($permission)) {
                    continue;
                }

                if (in_array($permission, self::REMOVED, true)) {
                    $removed[] = $permission;

                    continue;
                }

                $kept[] = $permission;
            }

            if ($removed === []) {
                continue;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values($kept)),
                'updated_at' => now(),
            ]);

            Log::info('Removed retired permissions from role.', [
                'role' => $role->slug,
                'removed' => $removed,
            ]);
        }
    }

    public function down(): void
    {
        // Permission strings are not restored. The enum no longer defines them.
    }
};
