<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

final readonly class Season
{
    public function __construct(
        public string $code,
        public string $name,
        public string $from,
        public string $to,
    ) {}

    /**
     * @return array{code: string, name: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }

    public function contains(string $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }
}
