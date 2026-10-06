<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Itinerary;
use App\Models\User;

final class ItineraryPolicy extends Policy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function view(User $actor, Itinerary $itinerary): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function viewHistory(User $actor, Itinerary $itinerary): bool
    {
        return $actor->hasPermission(Permission::PanelRms);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Itinerary $itinerary): bool
    {
        return false;
    }

    public function delete(User $actor, Itinerary $itinerary): bool
    {
        return false;
    }
}
