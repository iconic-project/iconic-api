<?php

declare(strict_types=1);

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Read-only view of a cabin claim kept with its delete trigger.
 * The live table was renamed to archive_cabin_claims in Sprint 22.
 *
 * @property int $id
 */
class CabinClaim extends Model
{
    protected $table = 'archive_cabin_claims';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function (): void {
            throw new LogicException('Archived cabin claims are read-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Archived cabin claims are read-only.');
        });
    }
}
