<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Models\Contact;

final class UnsubscribeLink
{
    public static function token(Contact $contact): string
    {
        return hash_hmac('sha256', (string) $contact->id, (string) config('app.key'));
    }

    public static function for(Contact $contact): string
    {
        return rtrim((string) config('iconic.engine_url'), '/').'/unsubscribe/'.self::token($contact);
    }
}
