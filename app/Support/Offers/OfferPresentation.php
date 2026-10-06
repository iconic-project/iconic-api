<?php

declare(strict_types=1);

namespace App\Support\Offers;

use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Models\Offer;
use App\Support\Dates\Format;
use App\Support\Money;
use Carbon\CarbonInterface;

final class OfferPresentation
{
    public static function benefit(Offer $offer): string
    {
        return match ($offer->type) {
            OfferType::Credit => Money::format((int) $offer->value).' ancillary credit per room',
            OfferType::Amount => Money::format((int) $offer->value).' off per room',
            OfferType::Percent => ((int) $offer->value).'% off room rate',
            OfferType::Value => $offer->value_text !== null && $offer->value_text !== ''
                ? $offer->value_text
                : 'Value-add',
            OfferType::Commission => '+'.((int) $offer->value).'% partner commission',
        };
    }

    public static function scope(Offer $offer): string
    {
        $channel = $offer->channel === OfferChannel::All
            ? 'All channels'
            : $offer->channel->value.($offer->partner !== null && $offer->partner !== '' ? ' · '.$offer->partner : '');

        $rooms = $offer->applies_to_room_types === null || $offer->applies_to_room_types === []
            ? 'All room types'
            : implode(', ', $offer->applies_to_room_types);

        $plans = $offer->applies_to_rate_plans === null || $offer->applies_to_rate_plans === []
            ? 'All rate plans'
            : implode(', ', $offer->applies_to_rate_plans);

        $label = $channel.' · '.$rooms.' · '.$plans;

        if ($offer->min_nights !== null && $offer->min_nights > 0) {
            $label .= ' · min '.$offer->min_nights.' nights';
        }

        return $label;
    }

    public static function window(?CarbonInterface $from, ?CarbonInterface $to): string
    {
        if ($from === null && $to === null) {
            return 'Any';
        }

        $start = $from instanceof CarbonInterface ? Format::calendar($from) : '…';
        $end = $to instanceof CarbonInterface ? Format::calendar($to) : '…';

        return $start.' → '.$end;
    }

    public static function enginePlacement(Offer $offer): string
    {
        if ($offer->channel === OfferChannel::B2B || $offer->is_promo_code) {
            return 'not_public';
        }

        $badge = $offer->badge !== null && $offer->badge !== '';

        if ($badge && ($offer->show_on_card || $offer->show_on_calendar)) {
            return 'badge';
        }

        return 'price_line_only';
    }
}
