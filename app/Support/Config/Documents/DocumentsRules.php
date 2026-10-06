<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class DocumentsRules
{
    public function __construct(
        public int $preArrivalDaysBefore,
        public int $voucherDaysBefore,
    ) {}

    /**
     * @return array{pre_arrival_days_before: int, voucher_days_before: int}
     */
    public function toArray(): array
    {
        return [
            'pre_arrival_days_before' => $this->preArrivalDaysBefore,
            'voucher_days_before' => $this->voucherDaysBefore,
        ];
    }
}
