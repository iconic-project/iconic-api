<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Models\Agency;
use App\Services\Config\CurrentConfig;

/**
 * Agency view of the engine stay search and calendar. Public amounts are
 * replaced with Agency::netOf. The public calendar cache is left alone.
 */
final class PortalNetPrices
{
    public function __construct(private CurrentConfig $config) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function availability(Agency $agency, array $payload): array
    {
        $types = $payload['room_types'] ?? [];

        if (is_array($types)) {
            foreach ($types as $index => $type) {
                if (! is_array($type)) {
                    continue;
                }

                $quotes = $type['quotes'] ?? [];

                if (is_array($quotes)) {
                    foreach ($quotes as $quoteIndex => $quote) {
                        if (is_array($quote)) {
                            $quotes[$quoteIndex] = $this->quote($agency, $quote);
                        }
                    }

                    $type['quotes'] = $quotes;
                }

                $types[$index] = $type;
            }

            $payload['room_types'] = $types;
        }

        $payload['commission_pct'] = $agency->commission_pct;
        $payload['stay'] = $this->stay();

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function calendar(Agency $agency, array $payload): array
    {
        $nights = $payload['nights'] ?? [];

        if (is_array($nights)) {
            foreach ($nights as $index => $night) {
                if (! is_array($night)) {
                    continue;
                }

                if (isset($night['from_price']) && is_int($night['from_price'])) {
                    $night['from_price'] = $agency->netOf($night['from_price']);
                }

                $nights[$index] = $night;
            }

            $payload['nights'] = $nights;
        }

        $payload['commission_pct'] = $agency->commission_pct;
        $payload['stay'] = $this->stay();

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function quote(Agency $agency, array $quote): array
    {
        foreach (['total', 'deposit', 'total_including_charged_taxes'] as $key) {
            if (isset($quote[$key]) && is_int($quote[$key])) {
                $quote[$key] = $agency->netOf($quote[$key]);
            }
        }

        $quote['night_lines'] = $this->lines($agency, $quote['night_lines'] ?? [], [
            'base', 'extras', 'single', 'dow', 'supplements', 'plan_adjust', 'total',
        ]);
        $quote['lines'] = $this->lines($agency, $quote['lines'] ?? [], ['amount']);
        $quote['tax_lines'] = $this->lines($agency, $quote['tax_lines'] ?? [], ['amount']);

        return $quote;
    }

    /**
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private function lines(Agency $agency, mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $netted = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $netted[] = $row;

                continue;
            }

            foreach ($keys as $key) {
                if (isset($row[$key]) && is_int($row[$key])) {
                    $row[$key] = $agency->netOf($row[$key]);
                }
            }

            $netted[] = $row;
        }

        return $netted;
    }

    /**
     * @return array{min_nights: int, max_nights: int, max_rooms: int}
     */
    private function stay(): array
    {
        $stay = $this->config->businessRules()->stay;

        return [
            'min_nights' => $stay->minNights,
            'max_nights' => $stay->maxNights,
            'max_rooms' => $stay->maxRoomsPerBooking,
        ];
    }
}
