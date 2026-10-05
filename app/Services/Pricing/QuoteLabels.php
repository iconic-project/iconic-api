<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Renders quote-line keys. The pricer stores the key and the parameters.
 */
final class QuoteLabels
{
    /**
     * @param  array<string, int|string>  $params
     */
    public static function render(string $key, array $params = []): string
    {
        $catalog = self::catalog();
        $params = self::withPlurals($key, $params, $catalog);
        $template = $catalog[$key] ?? $key;
        $replace = [];

        foreach ($params as $name => $value) {
            $replace[':'.$name] = (string) $value;
        }

        return strtr($template, $replace);
    }

    /**
     * @param  array<string, int|string>  $params
     * @param  array<string, string>  $catalog
     * @return array<string, int|string>
     */
    private static function withPlurals(string $key, array $params, array $catalog): array
    {
        if (array_key_exists('nights', $params)) {
            $nights = (int) $params['nights'];
            $params['night_label'] = $catalog[$nights === 1 ? 'night' : 'nights'] ?? '';
        }

        if (array_key_exists('adults', $params)) {
            $adults = (int) $params['adults'];
            $params['adult_label'] = $catalog[$adults === 1 ? 'adult' : 'adults'] ?? '';
        }

        if (array_key_exists('children', $params)) {
            $children = (int) $params['children'];
            $params['child_label'] = $catalog[$children === 1 ? 'child' : 'children'] ?? '';
        }

        if ($key !== 'room') {
            return $params;
        }

        $seasonCount = (int) ($params['season_count'] ?? 1);
        $params['season_label'] = $catalog[$seasonCount === 1 ? 'season' : 'seasons'] ?? '';

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(): array
    {
        /** @var array<string, string> $catalog */
        $catalog = require dirname(__DIR__, 3).'/lang/en/pricing.php';

        return $catalog;
    }
}
