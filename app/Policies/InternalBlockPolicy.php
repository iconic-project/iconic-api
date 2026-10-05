<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\InternalBlock;
use App\Models\User;

final class InternalBlockPolicy extends Policy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function view(User $actor, InternalBlock $block): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function viewHistory(User $actor, InternalBlock $block): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::BlocksManage);
    }

    public function update(User $actor, InternalBlock $block): bool
    {
        return $actor->hasPermission(Permission::BlocksManage);
    }

    public function release(User $actor, InternalBlock $block): bool
    {
        return $actor->hasPermission(Permission::BlocksManage);
    }

    public function shorten(User $actor, InternalBlock $block): bool
    {
        return $actor->hasPermission(Permission::BlocksManage);
    }
}
