<?php

declare(strict_types=1);

namespace App\Support\Engine;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Signed stay quote. Checkout compares the payload with the stay it is about to charge.
 */
final class QuoteToken
{
    public const int MINUTES = 15;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function issue(array $payload): string
    {
        $payload['expires_at'] = now()->addMinutes(self::MINUTES)->utc()->toIso8601String();

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'quote_token' => ['The quote could not be signed.'],
            ]);
        }

        return Crypt::encryptString($json);
    }

    /**
     * @return array<string, mixed>
     */
    public static function open(string $token): array
    {
        try {
            $json = Crypt::decryptString($token);
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages([
                'quote_token' => ['This quote has expired.'],
            ]);
        }

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'quote_token' => ['This quote has expired.'],
            ]);
        }

        $expires = $payload['expires_at'] ?? null;

        if (! is_string($expires) || CarbonImmutable::parse($expires)->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'quote_token' => ['This quote has expired.'],
            ]);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
