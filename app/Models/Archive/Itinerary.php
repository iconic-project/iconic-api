<?php

declare(strict_types=1);

namespace App\Models\Archive;

use App\Models\ChangeHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Read-only view of an itinerary row kept for history.
 * The live table was renamed to archive_itineraries in Sprint 22.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Itinerary extends Model
{
    protected $table = 'archive_itineraries';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function (): void {
            throw new LogicException('Archived itineraries are read-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Archived itineraries are read-only.');
        });
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
        $code = $this->getAttribute('code');

        return is_string($code) ? $code : '';
    }
}
