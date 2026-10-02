<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Services\Config\CurrentConfig;
use Illuminate\Validation\Validator;

final class EnginePartyRules
{
    public function __construct(private readonly CurrentConfig $config) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function apply(Validator $validator, array $rows): void
    {
        $guests = $this->config->engineSettings()->guests;
        $party = 0;
        $seen = [];

        if (count($rows) > 9) {
            $validator->errors()->add('cabins', 'A property has 9 cabins.');
        }

        foreach ($rows as $index => $row) {
            $adults = max(0, (int) ($row['adults'] ?? 0));
            $children = max(0, (int) ($row['children'] ?? 0));
            $code = isset($row['cabin_code']) ? (string) $row['cabin_code'] : '';
            $field = 'cabins.'.$index;
            $party += $adults + $children;

            if ($code !== '') {
                if (in_array($code, $seen, true)) {
                    $validator->errors()->add($field.'.cabin_code', 'This cabin is selected twice.');
                }

                $seen[] = $code;
            }

            if ($adults + $children > $guests->maxPerCabin) {
                $validator->errors()->add(
                    $field.'.adults',
                    'A cabin takes up to '.$guests->maxPerCabin.' guests.',
                );
            }

            if ($adults + $children < 1) {
                $validator->errors()->add($field.'.adults', 'A cabin cannot be empty.');
            }

            if ($guests->adultRequiredWithChildren && $children > 0 && $adults < 1) {
                $validator->errors()->add(
                    $field.'.adults',
                    'A child may not occupy a cabin without an adult.',
                );
            }
        }

        if ($party > $guests->maxPerProperty) {
            $validator->errors()->add('cabins', 'A property takes up to '.$guests->maxPerProperty.' guests.');
        }
    }
}
