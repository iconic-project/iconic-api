<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\ChangeHistory;
use App\Support\Bookings\LegacyStatus;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChangeHistory
 */
class BookingAuditResource extends JsonResource
{
    /**
     * @return array{
     *     at: string,
     *     actor_label: string,
     *     reference: string|null,
     *     client: string|null,
     *     what: string,
     *     why: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $after = $this->after ?? [];
        $what = isset($after['what']) && is_string($after['what']) && $after['what'] !== ''
            ? LegacyStatus::text($after['what'])
            : match ($this->event) {
                'booking.deleted' => 'Reservation deleted',
                default => 'Request released — hold returned to inventory',
            };

        $client = isset($after['client']) && is_string($after['client']) ? $after['client'] : null;

        return [
            'at' => Iso::utc($this->created_at),
            'actor_label' => $this->actor_label,
            'reference' => $this->subject_label,
            'client' => $client,
            'what' => $what,
            'why' => $this->reason,
        ];
    }
}
