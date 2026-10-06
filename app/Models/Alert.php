<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertKind;
use App\Enums\AlertSeverity;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property AlertKind $kind
 * @property AlertSeverity $severity
 * @property string $title
 * @property string $sentence
 * @property int|null $booking_id
 * @property int|null $agency_id
 * @property int|null $payment_id
 * @property int|null $delivery_id
 * @property int|null $guest_response_id
 * @property int|null $crm_task_id
 * @property string $base_key
 * @property string $idempotency_key
 * @property Carbon $raised_at
 * @property Carbon|null $acknowledged_at
 * @property int|null $acknowledged_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolution
 * @property Carbon|null $emailed_at
 * @property-read Booking|null $booking
 * @property-read Payment|null $payment
 * @property-read Delivery|null $delivery
 * @property-read CrmTask|null $crmTask
 * @property-read User|null $acknowledgedBy
 */
#[Fillable([
    'kind',
    'severity',
    'title',
    'sentence',
    'booking_id',
    'agency_id',
    'payment_id',
    'delivery_id',
    'guest_response_id',
    'crm_task_id',
    'base_key',
    'idempotency_key',
    'raised_at',
    'acknowledged_at',
    'acknowledged_by',
    'resolved_at',
    'resolution',
    'emailed_at',
])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AlertKind::class,
            'severity' => AlertSeverity::class,
            'raised_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function delete(): ?bool
    {
        throw new LogicException('Alerts are not deleted.');
    }

    /**
     * @return 'open'|'acknowledged'|'resolved'
     */
    public function state(): string
    {
        if ($this->resolved_at !== null) {
            return 'resolved';
        }

        if ($this->acknowledged_at !== null) {
            return 'acknowledged';
        }

        return 'open';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at')->whereNull('acknowledged_at');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<CrmTask, $this>
     */
    public function crmTask(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @return HasMany<AlertNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(AlertNotification::class);
    }

    public function historyLabel(): string
    {
        return $this->title;
    }
}
