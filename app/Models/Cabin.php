<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CabinCategory;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Database\Factories\CabinFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $property_id
 * @property string $code
 * @property string $label
 * @property CabinCategory $category
 * @property int $sort
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Property $property
 */
#[Fillable(['property_id', 'code', 'label', 'category', 'sort'])]
class Cabin extends Model
{
    /** @use HasFactory<CabinFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => CabinCategory::class,
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
