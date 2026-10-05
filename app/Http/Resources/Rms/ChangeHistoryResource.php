<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\ChangeHistory;
use App\Models\User;
use App\Support\Bookings\LegacyStatus;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChangeHistory
 */
class ChangeHistoryResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     event: string,
     *     subject_type: string,
     *     subject_id: int,
     *     subject_label: string|null,
     *     actor: array{id: int, name: string}|null,
     *     actor_label: string,
     *     before: array<string, mixed>|null,
     *     after: array<string, mixed>|null,
     *     reason: string|null,
     *     source: string,
     *     at: string
     * }
     */
    public function toArray(Request $request): array
    {
        $actor = $this->actor;

        return [
            'id' => $this->id,
            'event' => $this->event,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            'actor' => $actor instanceof User
                ? ['id' => $actor->id, 'name' => $actor->name]
                : null,
            'actor_label' => $this->actor_label,
            'before' => LegacyStatus::payload($this->before),
            'after' => LegacyStatus::payload($this->after),
            'reason' => $this->reason,
            'source' => $this->context['source'] ?? 'system',
            'at' => Iso::utc($this->created_at),
        ];
    }
}
