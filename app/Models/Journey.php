<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationKind;
use App\Enums\JourneySubject;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $goal
 * @property AutomationKind $kind
 * @property JourneySubject $subject
 * @property array<string, mixed> $trigger
 * @property list<array<string, mixed>> $exit_conditions
 * @property string $exit_sentence
 * @property string|null $contract
 * @property bool $active
 * @property bool $system
 * @property int|null $created_by
 * @property int|null $updated_by
 */
#[Fillable([
    'key',
    'name',
    'goal',
    'kind',
    'subject',
    'trigger',
    'exit_conditions',
    'exit_sentence',
    'contract',
    'active',
    'system',
])]
class Journey extends Model
{
    use HasAuditColumns, SerializesDatesAsUtc;

    public const SUPPRESSION_SENTENCE = 'Suppression: unsubscribed · consent withdrawn · human-touch handoff';

    /**
     * Prototype order.
     *
     * @var list<string>
     */
    public const SYSTEM_KEYS = [
        'nurture_to_request',
        'request_to_deposit',
        'payment_calendar',
        'reengagement',
        'b2b_partner_activation',
        'winback',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AutomationKind::class,
            'subject' => JourneySubject::class,
            'trigger' => 'array',
            'exit_conditions' => 'array',
            'active' => 'boolean',
            'system' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * @param  Builder<Journey>  $query
     */
    public function scopeInDisplayOrder(Builder $query): void
    {
        $list = implode(', ', array_map(
            fn (string $key): string => "'".$key."'",
            self::SYSTEM_KEYS,
        ));

        $query->orderByRaw('CASE WHEN `key` IN ('.$list.') THEN FIELD(`key`, '.$list.') ELSE 1000 END')
            ->orderBy('key');
    }

    /**
     * @return HasMany<JourneyStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(JourneyStep::class)->orderBy('position');
    }

    /**
     * @return HasMany<JourneyEnrolment, $this>
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(JourneyEnrolment::class);
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
        return $this->name;
    }

    public function triggerLine(): string
    {
        $line = $this->trigger['line'] ?? null;

        return is_string($line) ? $line : '';
    }
}
