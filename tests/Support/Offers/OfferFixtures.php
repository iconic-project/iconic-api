<?php

declare(strict_types=1);

namespace Tests\Support\Offers;

final class OfferFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'TEST10',
            'name' => 'Test percent',
            'type' => 'PCT',
            'value' => 10,
            'channel' => 'D2C',
            'combinable' => false,
            'is_promo_code' => false,
            'badge' => 'TEST',
            'show_on_card' => true,
            'show_on_calendar' => true,
            'price_line' => 'Test 10%',
            'terms' => 'Terms.',
        ], $overrides);
    }
}
