<?php

declare(strict_types=1);

namespace App\Support\Offers;

use App\Enums\CabinCategory;
use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Models\Itinerary;
use App\Models\Offer;
use App\Support\Dates\Format;
use App\Support\Money;
use Carbon\CarbonInterface;

final class OfferPresentation
{
    public static function benefit(Offer $offer): string
    {
        return match ($offer->type) {
            OfferType::Credit => Money::format((int) $offer->value).' ancillary credit / cabin',
            OfferType::Amount => Money::format((int) $offer->value).' off / cabin',
            OfferType::Percent => ((int) $offer->value).'% off cabin rate',
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

        $cabins = collect($offer->cabin_types)
            // TODO(Sprint 18): room type pricing (09 H8)
            ->map(fn (string $code): string => $code === CabinCategory::Owner->value ? "Owner's" : 'Suites')
            ->implode(' + ');

        $nonFestive = Itinerary::query()->where('festive', false)->pluck('code')->sort()->values();
        $selected = collect($offer->itinerary_codes)->sort()->values();

        $named = Itinerary::query()
            ->whereIn('code', $offer->itinerary_codes)
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->implode(', ');

        $itineraries = $nonFestive->isNotEmpty() && $selected->all() === $nonFestive->all()
            ? 'All non-festive itineraries'
            : ($named !== '' ? $named : implode(', ', $offer->itinerary_codes));

        return $channel.' · '.$cabins.' · '.$itineraries;
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

        if ($badge && ($offer->show_on_card || $offer->show_on_departures)) {
            return 'badge';
        }

        return 'price_line_only';
    }
}
