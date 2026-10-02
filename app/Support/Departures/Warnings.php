<?php

declare(strict_types=1);

namespace App\Support\Departures;

use App\Models\Departure;
use App\Support\Dates\Format;

final class Warnings
{
    /**
     * @return list<string>
     */
    public static function for(Departure $departure): array
    {
        $departure->loadMissing(['property', 'itinerary']);

        $warnings = [];

        $twin = Departure::query()
            ->where('property_id', '!=', $departure->property_id)
            ->whereDate('date', $departure->date->toDateString())
            ->with('property')
            ->first();

        if ($twin instanceof Departure && $twin->festive !== $departure->festive) {
            $code = $twin->property->code;
            $on = Format::calendar($twin->date);
            $warnings[] = $twin->festive
                ? "{$code}'s departure on {$on} is festive."
                : "{$code}'s departure on {$on} is not festive.";
        }

        $itinerary = $departure->itinerary;

        if ($departure->festive && ! $itinerary->festive) {
            $warnings[] = 'This departure is festive but itinerary '.$itinerary->code.' is not.';
        } elseif (! $departure->festive && $itinerary->festive) {
            $warnings[] = 'This departure is not festive but itinerary '.$itinerary->code.' is festive.';
        }

        return $warnings;
    }
}
