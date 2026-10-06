<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $kind
 * @property int $version
 * @property string $reason
 * @property Carbon $generated_at
 * @property int|null $generated_by
 * @property int $passengers
 * @property int $complete
 * @property string $snapshot_hash
 * @property string|null $pdf_path
 * @property string|null $csv_path
 * @property string|null $xlsx_path
 * @property Carbon|null $purged_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $generatedBy
 */
#[Fillable([
    'kind',
    'version',
    'reason',
    'generated_at',
    'generated_by',
    'passengers',
    'complete',
    'snapshot_hash',
    'pdf_path',
    'csv_path',
    'xlsx_path',
    'purged_at',
])]
class Manifest extends Model
{
    use HasAuditColumns, SerializesDatesAsUtc;

    /** @var list<string> */
    private const PURGE_COLUMNS = [
        'purged_at',
        'pdf_path',
        'csv_path',
        'xlsx_path',
        'updated_at',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => 'string',
            'reason' => 'string',
            'generated_at' => 'datetime',
            'passengers' => 'integer',
            'complete' => 'integer',
            'version' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            foreach (array_keys($this->getDirty()) as $column) {
                if (! in_array($column, self::PURGE_COLUMNS, true)) {
                    throw new LogicException('Manifest columns are immutable.');
                }
            }

            foreach (['pdf_path', 'csv_path', 'xlsx_path'] as $path) {
                if ($this->isDirty($path) && $this->getAttribute($path) !== null) {
                    throw new LogicException('Manifest file paths can only be cleared.');
                }
            }

            if ($this->isDirty('purged_at') && ($this->getOriginal('purged_at') !== null || $this->purged_at === null)) {
                throw new LogicException('Manifest purged_at can only be set once.');
            }
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Manifests are append-only.');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function historyLabel(): string
    {
        return $this->kind.' v'.$this->version;
    }
}
