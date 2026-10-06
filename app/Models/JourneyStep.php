<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JourneyStepAction;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $journey_id
 * @property string $branch
 * @property int $position
 * @property string $name
 * @property array<string, mixed> $delay
 * @property string $template_key
 * @property array<string, mixed>|null $condition
 * @property JourneyStepAction $action
 * @property string|null $catalogue_key
 * @property list<string>|null $catalogue_keys
 * @property-read Journey $journey
 */
#[Fillable([
    'journey_id',
    'branch',
    'position',
    'name',
    'delay',
    'template_key',
    'condition',
    'action',
    'catalogue_key',
    'catalogue_keys',
])]
class JourneyStep extends Model
{
    use HasAuditColumns, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay' => 'array',
            'condition' => 'array',
            'action' => JourneyStepAction::class,
            'catalogue_keys' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Journey, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    /**
     * @return list<string>
     */
    public function pointerKeys(): array
    {
        if (is_array($this->catalogue_keys) && $this->catalogue_keys !== []) {
            return $this->catalogue_keys;
        }

        return is_string($this->catalogue_key) && $this->catalogue_key !== ''
            ? [$this->catalogue_key]
            : [];
    }

    public function repeats(): bool
    {
        return ($this->delay['repeats'] ?? false) === true;
    }

    public function timingLabel(): string
    {
        $delay = $this->delay;
        $rule = is_string($delay['rule'] ?? null) ? $delay['rule'] : null;

        if ($rule === 'balance_reminder') {
            $slot = (int) ($delay['slot'] ?? 0);

            return $slot === 0 ? '21 days before the balance due date' : '7 days before the balance due date';
        }

        if ($rule === 'balance_due_plus_day') {
            return 'The day after the balance is due';
        }

        if ($rule === 'extras_due_hours') {
            return 'extras due hours before check-in';
        }

        if ($rule === 'pretrip_days_before') {
            return 'pre-arrival days before check-in';
        }

        if ($rule === 'manifest_chase') {
            return 'manifest chase date';
        }

        if ($rule === 'dpng_due') {
            return 'DPNG due date';
        }

        $amount = (int) ($delay['amount'] ?? 0);
        $unit = is_string($delay['unit'] ?? null) ? $delay['unit'] : 'days';
        $anchor = is_string($delay['anchor'] ?? null) ? $delay['anchor'] : 'enrolment';

        if (($delay['repeats'] ?? false) === true) {
            return 'Every 3 months';
        }

        if (($anchor === 'arrival' || $anchor === 'departure') && $amount < 0 && $unit === 'days') {
            return 'T−'.abs($amount);
        }

        if ($unit === 'hours') {
            return 'Hour '.$amount;
        }

        if ($unit === 'months') {
            return 'Month '.$amount;
        }

        return 'Day '.$amount;
    }
}
