<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RoomType;
use App\Models\User;

final class RoomTypePolicy extends Policy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function view(User $actor, RoomType $roomType): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::PropertiesManage);
    }

    public function update(User $actor, RoomType $roomType): bool
    {
        return $actor->hasPermission(Permission::PropertiesManage);
    }

    public function viewHistory(User $actor, RoomType $roomType): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }
}
