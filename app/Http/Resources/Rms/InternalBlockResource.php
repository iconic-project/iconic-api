<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\CabinClaim;
use App\Models\InternalBlock;
use App\Models\User;
use App\Support\Blocks\ScopeSummary;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin InternalBlock
 */
class InternalBlockResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     reference: string,
     *     reason: string,
     *     reason_label: string,
     *     notes: string|null,
     *     scope_summary: string,
     *     created_by: array{id: int, name: string}|null,
     *     created_at: string|null,
     *     released_at: string|null,
     *     released_by: array{id: int, name: string}|null,
     *     release_note: string|null,
     *     claims: list<array{
     *         id: int,
     *         kind: string,
     *         released_at: string|null,
     *         cabin: array{id: int, code: string, label: string},
     *         departure: array{
     *             id: int,
     *             reference: string,
     *             date: string,
     *             property: array{id: int, code: string, name: string}
     *         }
     *     }>
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'createdBy',
            'releasedBy',
            'claims.cabin',
            'claims.departure.property',
        ]);

        $claims = $this->claims;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'notes' => $this->notes,
            'scope_summary' => $this->scopeSummary($claims),
            'created_by' => $this->actorPayload($this->createdBy),
            'created_at' => Iso::utc($this->created_at),
            'released_at' => $this->released_at !== null ? Iso::utc($this->released_at) : null,
            'released_by' => $this->actorPayload($this->releasedBy),
            'release_note' => $this->release_note,
            'claims' => $claims->map(fn (CabinClaim $claim): array => [
                'id' => $claim->id,
                'kind' => $claim->kind->value,
                'released_at' => $claim->released_at !== null ? Iso::utc($claim->released_at) : null,
                'cabin' => [
                    'id' => $claim->cabin->id,
                    'code' => $claim->cabin->code,
                    'label' => $claim->cabin->label,
                ],
                'departure' => [
                    'id' => $claim->departure->id,
                    'reference' => $claim->departure->reference,
                    'date' => $claim->departure->date->toDateString(),
                    'property' => [
                        'id' => $claim->departure->property->id,
                        'code' => $claim->departure->property->code,
                        'name' => $claim->departure->property->name,
                    ],
                ],
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, CabinClaim>  $claims
     */
    private function scopeSummary($claims): string
    {
        $scopes = $claims->map(fn (CabinClaim $claim): array => [
            'property_code' => $claim->departure->property->code,
            'date' => $claim->departure->date->toDateString(),
            'cabin_codes' => [$claim->cabin->code],
        ])->all();

        return ScopeSummary::format($scopes);
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function actorPayload(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
