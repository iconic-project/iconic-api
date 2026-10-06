<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Sentences the panel prints. The panel does not write its own definitions.
 */
final class MetricCatalogue
{
    /**
     * @return array<string, array{sentence: string, filters_on: string, excludes: string}>
     */
    public static function all(): array
    {
        return [
            'occupancy' => [
                'sentence' => 'Sold berths divided by sellable berths, per departure and as that ratio over the window. A sellable berth is a room that is not blocked. A charter counts as the whole property.',
                'filters_on' => 'departure date',
                'excludes' => 'Blocked rooms are not sellable. Expired holds are free. People are not listed.',
            ],
            'revpab' => [
                'sentence' => 'Stay revenue divided by sellable berths. Stay revenue is the room charge on bookings that hold a sold berth.',
                'filters_on' => 'departure date',
                'excludes' => 'Extras and Galápagos fees are excluded. Blocked rooms are not sellable.',
            ],
            'adr' => [
                'sentence' => 'Stay revenue divided by berths sold. Stay revenue is the room charge on bookings that hold a sold berth.',
                'filters_on' => 'departure date',
                'excludes' => 'Extras and Galápagos fees are excluded.',
            ],
            'lead_time' => [
                'sentence' => 'Average and median whole days from the Galápagos sale date to the departure date, on bookings that hold a sold berth. The sale date is created_at shifted to Pacific/Galapagos (UTC−6, no daylight saving).',
                'filters_on' => 'departure date',
                'excludes' => 'Bookings that do not hold a sold berth.',
            ],
            'channel_mix' => [
                'sentence' => 'Sold bookings and their stay revenue by channel of origin. Channels in the commission scan\'s trade list are named Trade.',
                'filters_on' => 'departure date',
                'excludes' => 'Extras and Galápagos fees are excluded from revenue.',
            ],
            'nationality_mix' => [
                'sentence' => 'Guest counts by country code on bookings that hold a sold berth.',
                'filters_on' => 'departure date',
                'excludes' => 'No passenger or contact is named. Only the country code and the count are returned.',
            ],
            'nps' => [
                'sentence' => 'Average score, plus promoter, passive and detractor counts. A score below the alert threshold is a detractor. A score at or above the review-request threshold, and not a detractor, is a promoter. The rest are passive.',
                'filters_on' => 'response date',
                'excludes' => 'Free text and guest names are excluded.',
            ],
            'commissions' => [
                'sentence' => 'Commission amounts in blocked, earned, payable and paid, using the accrual status. Paid is the recorded payout. The set is approved agencies, the same population as the agencies screen.',
                'filters_on' => 'departure date',
                'excludes' => 'Agencies that are not approved.',
            ],
            'cash' => [
                'sentence' => 'Collected and the deposit share come from Payments & Revenue. The deposit share is deposit receipts as a whole percent of collected.',
                'filters_on' => 'payment date for collected and deposits; departure date for pending and overdue',
                'excludes' => 'Collected counts settled deposit and balance receipts only, the same kinds as Payments & Revenue.',
            ],
        ];
    }

    /**
     * @return array{sentence: string, filters_on: string, excludes: string}
     */
    public static function get(string $key): array
    {
        return self::all()[$key];
    }
}
