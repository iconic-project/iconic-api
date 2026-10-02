<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PropertyStatus;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $slug
 * @property string|null $timezone
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $country
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $description
 * @property string|null $hero_image_path
 * @property string|null $hero_alt
 * @property list<string>|null $highlights
 * @property list<array{0: string, 1: string}>|null $facts
 * @property list<array{0: string, 1: string}>|null $faqs
 * @property string|null $policies_text
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property PropertyStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Cabin> $cabins
 * @property-read Collection<int, Departure> $departures
 */
#[Fillable([
    'code',
    'name',
    'slug',
    'timezone',
    'address_line_1',
    'address_line_2',
    'city',
    'postcode',
    'country',
    'phone',
    'email',
    'description',
    'hero_image_path',
    'hero_alt',
    'highlights',
    'facts',
    'faqs',
    'policies_text',
    'meta_title',
    'meta_description',
    'status',
])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'facts' => 'array',
            'faqs' => 'array',
            'status' => PropertyStatus::class,
        ];
    }

    /**
     * @return HasMany<Cabin, $this>
     */
    public function cabins(): HasMany
    {
        return $this->hasMany(Cabin::class)->orderBy('sort');
    }

    /**
     * @return HasMany<Departure, $this>
     */
    public function departures(): HasMany
    {
        return $this->hasMany(Departure::class);
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }
}
