<?php

declare(strict_types=1);

namespace App\Support\Waitlist;

use App\Mail\Waitlist\WaitlistOfferMail;
use App\Models\Delivery;
use App\Models\WaitlistEntry;
use InvalidArgumentException;

final class WaitlistOfferCopy
{
    public static function subject(WaitlistEntry $entry): string
    {
        return self::sentence($entry);
    }

    public static function sentence(WaitlistEntry $entry): string
    {
        $entry->loadMissing('roomType');

        return 'A '.$entry->roomType->name.' is free — '.self::range($entry);
    }

    public static function url(WaitlistEntry $entry): string
    {
        $base = rtrim((string) config('iconic.engine_url'), '/');
        $query = http_build_query([
            'check_in' => $entry->check_in->toDateString(),
            'check_out' => $entry->check_out->toDateString(),
            'adults' => $entry->adults,
            'rooms' => 1,
        ]);

        return $base.'/book/rooms?'.$query;
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

    public static function range(WaitlistEntry $entry): string
    {
        $in = $entry->check_in;
        $out = $entry->check_out;

        if ($in->month === $out->month && $in->year === $out->year) {
            return $in->format('D j').' – '.$out->format('D j M Y');
        }

        return $in->format('D j M Y').' – '.$out->format('D j M Y');
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
