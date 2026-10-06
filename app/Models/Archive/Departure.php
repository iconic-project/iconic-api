<?php

declare(strict_types=1);

namespace App\Models\Archive;

use App\Models\ChangeHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Read-only view of a departure row kept for history.
 * The live table was renamed to archive_departures in Sprint 22.
 *
 * @property int $id
 * @property string $reference
 */
class Departure extends Model
{
    protected $table = 'archive_departures';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function (): void {
            throw new LogicException('Archived departures are read-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Archived departures are read-only.');
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
        $reference = $this->getAttribute('reference');

        return is_string($reference) ? $reference : '';
    }
}
