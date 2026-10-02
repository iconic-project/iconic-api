<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Models\AgencyUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgencyUser
 */
class PortalMeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     agency: array{id: int, reference: string, name: string},
     *     time_zone: string
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('agency');

        $agency = $this->agency;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'agency' => [
                'id' => $agency->id,
                'reference' => $agency->reference,
                'name' => $agency->name,
            ],
            'time_zone' => (string) config('iconic.business_timezone'),
        ];
    }
}
