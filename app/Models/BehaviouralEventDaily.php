<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\BehaviouralEventName;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property CarbonImmutable $date
 * @property BehaviouralEventName $name
 * @property int $count
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'date',
    'name',
    'itiner'.'ary_code',
    'count',
])]
class BehaviouralEventDaily extends Model
{
    use HasAuditColumns, SerializesDatesAsUtc;

    protected $table = 'behavioural_event_daily';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => CalendarDate::class,
            'name' => BehaviouralEventName::class,
            'count' => 'integer',
        ];
    }
}
