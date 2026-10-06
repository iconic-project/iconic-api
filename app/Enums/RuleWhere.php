<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleWhere: string
{
    case Here = 'here';
    case Rates = 'rates';
    case EngineSettings = 'engine_settings';
    case Locked = 'locked';
}
