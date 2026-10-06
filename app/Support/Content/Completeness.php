<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Enums\RoomTypeStatus;
use App\Models\Property;
use App\Models\RoomType;

/**
 * Engine-facing content checks. Every gap is blocking: a room type
 * with any gap is not shown on the engine (09 H20).
 */
final readonly class Completeness
{
    /**
     * @param  list<string>  $missing
     * @param  list<string>  $blocking
     */
    public function __construct(
        public int $pct,
        public array $missing,
        public array $blocking,
    ) {}

    public static function forProperty(Property $property): self
    {
        return self::score([
            ['hero photo', self::filled($property->hero_image_path) && self::filled($property->hero_alt)],
            ['description', self::filled($property->description)],
            ['address', self::filled($property->address_line_1)],
            ['policies', self::filled($property->policies_text)],
            ['SEO title', self::filled($property->meta_title)],
            ['SEO description', self::filled($property->meta_description)],
        ]);
    }

    public static function forRoomType(RoomType $type): self
    {
        return self::score([
            ['photo', self::hasPhoto($type)],
            ['description', self::filled($type->description)],
            ['bed setup', self::filled($type->bed_setup)],
            ['SEO title', self::filled($type->meta_title)],
            ['SEO description', self::filled($type->meta_description)],
        ]);
    }

    public static function engineVisible(RoomType $type): bool
    {
        return $type->status === RoomTypeStatus::Active
            && self::forRoomType($type)->blocking === [];
    }

    /**
     * @return array{pct: int, missing: list<string>, blocking: list<string>}
     */
    public function toArray(): array
    {
        return [
            'pct' => $this->pct,
            'missing' => $this->missing,
            'blocking' => $this->blocking,
        ];
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $checks
     */
    private static function score(array $checks): self
    {
        $missing = [];
        $blocking = [];

        foreach ($checks as [$label, $present]) {
            if ($present) {
                continue;
            }

            $missing[] = $label;
            $blocking[] = $label;
        }

        $total = count($checks);
        $pct = $total === 0 ? 100 : (int) round((($total - count($missing)) / $total) * 100);

        return new self($pct, $missing, $blocking);
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function hasPhoto(RoomType $type): bool
    {
        foreach ($type->photos ?? [] as $photo) {
            if (self::filled($photo['path']) && self::filled($photo['alt'])) {
                return true;
            }
        }

        return false;
    }
}
