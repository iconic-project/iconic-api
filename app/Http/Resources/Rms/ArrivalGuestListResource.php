<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Enums\PreferenceSource;
use App\Enums\PreferenceStatus;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     send_date: string,
 *     send_state: 'sent'|'scheduled',
 *     kpis: array{guests: int, bookings: int, answered: int, total: int, celebrations: int, accessibility_or_medical: int},
 *     guests: list<array{
 *         guest_id: int,
 *         name: string,
 *         booking_reference: string,
 *         email: string|null,
 *         email_note: string|null,
 *         room: string,
 *         status: PreferenceStatus,
 *         status_label: string,
 *         answered_at: string|null,
 *         source: PreferenceSource|null,
 *         send_date: string,
 *         dietary: string|null,
 *         celebration: string|null,
 *         activity: string|null,
 *         accessibility_provided: bool,
 *         emergency_contact_provided: bool,
 *         accessibility?: string|null,
 *         emergency_contact?: string|null
 *     }>
 * } $resource
 */
#[SchemaName('ArrivalGuestListResource')]
class ArrivalGuestListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
