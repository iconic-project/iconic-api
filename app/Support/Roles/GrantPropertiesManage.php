<?php

declare(strict_types=1);

namespace App\Support\Roles;

/**
 * Admin already holds every permission. Role::saving clears the admin
 * permissions column, so there is no JSON list to update.
 */
final class GrantPropertiesManage
{
    public static function grant(): void
    {
        // Admin implies Permission::cases(). See Role::permissionValues().
    }

    public static function revoke(): void
    {
        // Nothing was stored on the admin role.
    }
}
