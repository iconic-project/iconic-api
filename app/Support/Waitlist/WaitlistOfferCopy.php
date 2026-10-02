<?php

declare(strict_types=1);

namespace App\Support\Waitlist;

use App\Enums\CabinCategory;
use App\Mail\Waitlist\WaitlistOfferMail;
use App\Models\Delivery;
use App\Models\WaitlistEntry;
use InvalidArgumentException;

final class WaitlistOfferCopy
{
    public static function subject(WaitlistEntry $entry): string
    {
        $entry->loadMissing('departure.property');

        return 'A cabin is free — '.$entry->departure->property->name.' '.$entry->departure->date->toDateString();
    }

    public static function sentence(WaitlistEntry $entry): string
    {
        $entry->loadMissing('departure.property');
        $category = $entry->cabin_category === CabinCategory::Owner ? "Owner's Suite" : 'Suite';

        return 'A '.$category.' is free on '.$entry->departure->property->name
            .' departing '.$entry->departure->date->toDateString()
            .'. Cabins are first-come and nothing is held for you.';
    }

    public static function url(WaitlistEntry $entry): string
    {
        $entry->loadMissing('departure.itinerary');
        $base = rtrim((string) config('iconic.engine_url'), '/');
        $slug = $entry->departure->itinerary->slug;
        $path = is_string($slug) && $slug !== '' ? '/itineraries/'.$slug : '/';

        return $base.$path.'?departure='.$entry->departure_id;
    }

    public static function mail(Delivery $delivery): WaitlistOfferMail
    {
        $entryId = self::entryId($delivery);
        $entry = WaitlistEntry::query()->find($entryId);

        if (! $entry instanceof WaitlistEntry) {
            throw new InvalidArgumentException('The waitlist entry for this delivery is gone.');
        }

        return new WaitlistOfferMail($delivery, self::sentence($entry), self::url($entry));
    }

    public static function key(WaitlistEntry $entry): string
    {
        return 'waitlist:'.$entry->id;
    }

    public static function taskKey(WaitlistEntry $entry): string
    {
        return 'waitlist-follow-up:'.$entry->id;
    }

    private static function entryId(Delivery $delivery): int
    {
        $id = substr($delivery->idempotency_key, strlen('waitlist:'));

        if ($id === '' || ! ctype_digit($id)) {
            throw new InvalidArgumentException('A waitlist offer is missing its entry id.');
        }

        return (int) $id;
    }
}
