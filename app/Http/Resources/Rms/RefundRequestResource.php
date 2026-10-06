<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Enums\Permission;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessHours;
use App\Support\Iso;
use App\Support\Payments\CancellationPenalty;
use App\Support\Refunds\RefundSla;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RefundRequest
 */
class RefundRequestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     booking: array{id: int, reference: string|null, display_reference: string|null},
     *     client: string,
     *     cancelled_at: string,
     *     days_before_arrival: int,
     *     band_min_days: int,
     *     band_label: string,
     *     penalty_pct: int,
     *     penalty_amount: int,
     *     paid_at_cancellation: int,
     *     refund_due: int,
     *     due_by: string,
     *     business_days_remaining: int,
     *     sla_breached: bool,
     *     decision_reason: string|null,
     *     decided_at: string|null,
     *     decided_by: array{id: int, name: string}|null,
     *     executed_payment_id: int|null,
     *     can_approve: bool,
     *     can_execute: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['booking.contact', 'booking.property', 'decidedBy']);

        $config = app(CurrentConfig::class);
        $rules = $config->businessRules();
        $hours = BusinessHours::fromDocument($rules);
        $sla = RefundSla::for($this->resource, $hours, $rules);
        $band = [
            'min_days' => $this->band_min_days,
            'penalty_pct' => $this->penalty_pct,
        ];
        $label = CancellationPenalty::label(
            $band,
            $this->band_source === 'CHARTER' ? $rules->charterBands : $rules->bands,
        );
        $actor = $request->user();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'booking' => [
                'id' => $this->booking->id,
                'reference' => $this->booking->reference,
                'display_reference' => $this->booking->displayReference(),
            ],
            'client' => $this->booking->contact->name,
            'cancelled_at' => Iso::utc($this->cancelled_at),
            'days_before_arrival' => $this->days_before_arrival,
            'band_min_days' => $this->band_min_days,
            'band_source' => $this->band_source,
            'band_label' => $label,
            'penalty_pct' => $this->penalty_pct,
            'penalty_amount' => $this->penalty_amount,
            'paid_at_cancellation' => $this->paid_at_cancellation,
            'refund_due' => $this->refund_due,
            'due_by' => Iso::utc($this->due_by),
            'business_days_remaining' => $sla['business_days_remaining'],
            'sla_breached' => $sla['sla_breached'],
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at !== null ? Iso::utc($this->decided_at) : null,
            'decided_by' => $this->decidedBy === null ? null : [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ],
            'executed_payment_id' => $this->executed_payment_id,
            'can_approve' => $actor instanceof User && $actor->hasPermission(Permission::RefundsApprove),
            'can_execute' => $actor instanceof User && $actor->hasPermission(Permission::RefundsExecute),
        ];
    }
}
