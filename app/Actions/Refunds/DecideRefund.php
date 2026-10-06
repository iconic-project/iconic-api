<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Action;
use App\Enums\RefundRequestStatus;
use App\Models\RefundRequest;
use App\Models\User;
use App\Support\History\History;
use Illuminate\Validation\ValidationException;

final class DecideRefund extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(RefundRequest $refund, array $data, User $actor): RefundRequest
    {
        return $this->transaction(function () use ($refund, $data, $actor): RefundRequest {
            $refund = RefundRequest::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($refund->status !== RefundRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'decision' => ['This refund has already been decided.'],
                ]);
            }

            $decision = $data['decision'] instanceof RefundRequestStatus
                ? $data['decision']
                : RefundRequestStatus::from((string) $data['decision']);

            if (! in_array($decision, [RefundRequestStatus::Approved, RefundRequestStatus::Rejected], true)) {
                throw ValidationException::withMessages([
                    'decision' => ['Decision must be APPROVED or REJECTED.'],
                ]);
            }

            $reason = trim((string) $data['reason']);
            $refund->status = $decision;
            $refund->decided_at = now();
            $refund->decided_by = $actor->id;
            $refund->decision_reason = $reason;
            $refund->save();

            $refund->loadMissing('booking');

            History::record($refund->booking, $decision === RefundRequestStatus::Approved
                ? 'refund.approved'
                : 'refund.rejected', before: [
                    'status' => RefundRequestStatus::Pending->value,
                ], after: [
                    'status' => $decision->value,
                ], reason: $reason, actor: $actor);

            return $refund->fresh(['booking.contact', 'booking.property', 'decidedBy']) ?? $refund;
        });
    }
}
