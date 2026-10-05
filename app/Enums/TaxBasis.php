<?php

declare(strict_types=1);

namespace App\Enums;

enum TaxBasis: string
{
    case PerStay = 'PER_STAY';
    case PerNight = 'PER_NIGHT';
    case PerPersonPerNight = 'PER_PERSON_PER_NIGHT';
    case PctOfRoom = 'PCT_OF_ROOM';
}
