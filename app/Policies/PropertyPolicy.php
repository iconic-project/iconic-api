<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Property;
use App\Models\User;

final class PropertyPolicy extends Policy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function view(User $actor, Property $property): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function update(User $actor, Property $property): bool
    {
        return $actor->hasPermission(Permission::PropertiesManage);
    }
}
