<?php

declare(strict_types=1);

namespace App\Services\Pricing;

enum QuoteType: string
{
    case Room = 'ROOM';
    case Charter = 'CHARTER';
}
