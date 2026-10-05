<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

final readonly class RoomRate
{
    public function __construct(
        public string $roomType,
        public string $season,
        public int $nightly,
    ) {}

    /**
     * @return array{room_type: string, season: string, nightly: int}
     */
    public function toArray(): array
    {
        return [
            'room_type' => $this->roomType,
            'season' => $this->season,
            'nightly' => $this->nightly,
        ];
    }
}
