<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

final readonly class Supplement
{
    public function __construct(
        public string $code,
        public string $label,
        public string $from,
        public string $to,
        public int $perNight,
        public string $basis,
    ) {}

    /**
     * @return array{code: string, label: string, from: string, to: string, per_night: int, basis: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'from' => $this->from,
            'to' => $this->to,
            'per_night' => $this->perNight,
            'basis' => $this->basis,
        ];
    }
}
