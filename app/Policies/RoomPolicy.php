<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Room;
use App\Models\User;

final class RoomPolicy extends Policy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function view(User $actor, Room $room): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::PropertiesManage);
    }

    public function update(User $actor, Room $room): bool
    {
        return $actor->hasPermission(Permission::PropertiesManage);
    }
}
