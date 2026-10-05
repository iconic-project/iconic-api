<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

final class StayRestrictionPolicy extends Policy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function set(User $actor): bool
    {
        return $actor->hasPermission(Permission::InventoryManageRestrictions);
    }
}
