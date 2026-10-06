<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

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
     *     stay: array{check_in: string, check_out: string, room_type: array{code: string, name: string}},
     *     position: int|null,
     *     since: string,
     *     notified: array{at: string, channel: PreferredChannel, by: string}|null,
     *     auto_notified: bool,
     *     room_available: bool,
     *     notes: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['roomType', 'contact', 'notifiedBy']);

        $position = $this->queuePosition;
        $available = $this->roomIsAvailable;

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
            'stay' => [
                'check_in' => $this->check_in->toDateString(),
                'check_out' => $this->check_out->toDateString(),
                'room_type' => [
                    'code' => $this->roomType->code,
                    'name' => $this->roomType->name,
                ],
            ],
            'position' => is_int($position) ? $position : null,
            'since' => Iso::utc($this->created_at),
            'notified' => $notified,
            'auto_notified' => (bool) ($this->notified_at !== null && $this->notified_by === null),
            'room_available' => $available,
            'notes' => $this->notes,
        ];
    }
}
