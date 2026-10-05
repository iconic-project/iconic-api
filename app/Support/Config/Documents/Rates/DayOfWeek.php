<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

final readonly class DayOfWeek
{
    public function __construct(
        public int $monday,
        public int $tuesday,
        public int $wednesday,
        public int $thursday,
        public int $friday,
        public int $saturday,
        public int $sunday,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::percent($data, 1),
            self::percent($data, 2),
            self::percent($data, 3),
            self::percent($data, 4),
            self::percent($data, 5),
            self::percent($data, 6),
            self::percent($data, 7),
        );
    }

    /**
     * ISO weekday keys. A missing day is 0.
     *
     * @return array<int, int>
     */
    public function toArray(): array
    {
        return [
            1 => $this->monday,
            2 => $this->tuesday,
            3 => $this->wednesday,
            4 => $this->thursday,
            5 => $this->friday,
            6 => $this->saturday,
            7 => $this->sunday,
        ];
    }

    /**
     * Whole-percent adjustment for an ISO weekday (Monday = 1). Unknown days are 0.
     */
    public function onIsoWeekday(int $isoWeekday): int
    {
        return $this->toArray()[$isoWeekday] ?? 0;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function percent(array $data, int $day): int
    {
        if (! array_key_exists($day, $data) && ! array_key_exists((string) $day, $data)) {
            return 0;
        }

        $value = $data[$day] ?? $data[(string) $day] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
