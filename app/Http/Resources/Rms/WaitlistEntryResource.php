<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Enums\CabinCategory;
use App\Enums\PreferredChannel;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WaitlistEntry
 */
class WaitlistEntryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     contact: array{name: string, email: string|null},
     *     departure: array{id: int, date: string, property: array{code: string, name: string}, festive: bool},
     *     cabin_category: CabinCategory,
     *     cabin_type: string,
     *     position: int|null,
     *     since: string,
     *     notified: array{at: string, channel: PreferredChannel, by: string}|null,
     *     auto_notified: bool,
     *     cabin_available: bool,
     *     notes: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['departure.property', 'contact', 'notifiedBy']);

        $position = $this->queuePosition;
        $available = $this->cabinIsAvailable;

        $channel = $this->notified_channel;
        $notifier = $this->notifiedBy;
        $notified = $this->notified_at === null ? null : [
            'at' => Iso::utc($this->notified_at),
            'channel' => $channel instanceof PreferredChannel ? $channel : PreferredChannel::Email,
            'by' => $notifier instanceof User ? $notifier->name : '',
        ];

        return [
            'id' => $this->id,
            'contact' => [
                'name' => $this->contact->name,
                'email' => $this->contact->email,
            ],
            'departure' => [
                'id' => $this->departure->id,
                'date' => $this->departure->date->toDateString(),
                'property' => [
                    'code' => $this->departure->property->code,
                    'name' => $this->departure->property->name,
                ],
                'festive' => $this->departure->festive,
            ],
            'cabin_category' => $this->cabin_category,
            'cabin_type' => $this->cabin_category === CabinCategory::Owner ? "Owner's Suite" : 'Suite',
            'position' => is_int($position) ? $position : null,
            'since' => Iso::utc($this->created_at),
            'notified' => $notified,
            'auto_notified' => (bool) ($this->notified_at !== null && $this->notified_by === null),
            'cabin_available' => $available,
            'notes' => $this->notes,
        ];
    }
}
