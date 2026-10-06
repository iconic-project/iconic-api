<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundRequestStatus;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Database\Factories\RefundRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $booking_id
 * @property Carbon $cancelled_at
 * @property int $days_before_arrival
 * @property int $band_min_days
 * @property string|null $band_source
 * @property int $penalty_pct
 * @property int $penalty_amount
 * @property int $paid_at_cancellation
 * @property int $refund_due
 * @property RefundRequestStatus $status
 * @property Carbon $due_by
 * @property Carbon|null $decided_at
 * @property int|null $decided_by
 * @property string|null $decision_reason
 * @property int|null $executed_payment_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Booking $booking
 * @property-read User|null $decidedBy
 * @property-read Payment|null $executedPayment
 */
#[Fillable([
    'booking_id',
    'cancelled_at',
    'days_before_arrival',
    'band_min_days',
    'band_source',
    'penalty_pct',
    'penalty_amount',
    'paid_at_cancellation',
    'refund_due',
    'status',
    'due_by',
    'decided_at',
    'decided_by',
    'decision_reason',
    'executed_payment_id',
])]
class RefundRequest extends Model
{
    /** @use HasFactory<RefundRequestFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
            'days_before_arrival' => 'integer',
            'band_min_days' => 'integer',
            'penalty_pct' => 'integer',
            'penalty_amount' => 'integer',
            'paid_at_cancellation' => 'integer',
            'refund_due' => 'integer',
            'status' => RefundRequestStatus::class,
            'due_by' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function executedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'executed_payment_id');
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }

    public function historyLabel(): string
    {
        $this->loadMissing('booking');

        return ($this->booking->displayReference() ?? 'booking').' refund';
    }
}
