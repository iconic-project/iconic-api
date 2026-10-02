<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     role: array{id: int, name: string, slug: string},
     *     permissions: list<string>,
     *     sections: list<'rms'|'crm'>,
     *     time_zone: string
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('role');

        $role = $this->role;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ],
            'permissions' => $this->permissions()->map(fn (Permission $permission): string => $permission->value)->values()->all(),
            'sections' => $this->sections(),
            'time_zone' => (string) config('iconic.business_timezone'),
        ];
    }
}
