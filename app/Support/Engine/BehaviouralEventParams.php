<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\BehaviouralEventName;
use App\Enums\CheckoutPath;
use Illuminate\Validation\ValidationException;

final class BehaviouralEventParams
{
    /** @var list<string> */
    private const KEYS = [
        'property_code',
        'step',
        'path',
        'currency',
        'value',
        'coupon_code',
        'page_path',
        'count',
        'check_in',
        'check_out',
        'adults',
        'children',
        'rooms',
        'room_type',
    ];

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function filter(BehaviouralEventName $name, array $params, int $index = 0): array
    {
        if (! $name->isClient()) {
            throw ValidationException::withMessages([
                'events.'.$index.'.name' => 'This event name is not accepted from the client.',
            ]);
        }

        $allowed = self::allowedKeys($name);
        $filtered = [];

        foreach ($params as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                continue;
            }

            if (! in_array($key, $allowed, true)) {
                continue;
            }

            $filtered[$key] = self::cast($key, $value, $index);
        }

        return $filtered;
    }

    /**
     * @return list<string>
     */
    public static function allowedKeys(BehaviouralEventName $name): array
    {
        return match ($name) {
            BehaviouralEventName::PageView => ['page_path'],
            BehaviouralEventName::ViewProperty,
            BehaviouralEventName::ViewPropertyDetail,
            BehaviouralEventName::ViewRouteMap,
            BehaviouralEventName::SearchAvailability => ['property_code'],
            BehaviouralEventName::ViewStay,
            BehaviouralEventName::SelectStay => ['property_code'],
            BehaviouralEventName::BeginCheckout,
            BehaviouralEventName::BeginBookingRequest => ['property_code', 'rooms'],
            BehaviouralEventName::SelectPaymentPath => ['path'],
            BehaviouralEventName::ApplyPromotion,
            BehaviouralEventName::RemovePromotion,
            BehaviouralEventName::PromoInvalid => ['coupon_code'],
            BehaviouralEventName::BookingFormInvalid => ['step'],
            BehaviouralEventName::SubmitBookingRequest => [
                'property_code',
                'rooms',
                'path',
                'currency',
                'value',
            ],
            BehaviouralEventName::AbandonCart => ['property_code', 'step', 'rooms'],
            BehaviouralEventName::CharterInquirySubmit => ['property_code', 'value', 'currency'],
            BehaviouralEventName::SearchPerformed => ['check_in', 'check_out', 'adults', 'children', 'rooms'],
            BehaviouralEventName::RoomTypeViewed => ['room_type'],
            BehaviouralEventName::IdentityStitched => ['count'],
        };
    }

    private static function cast(string $key, mixed $value, int $index): mixed
    {
        $field = 'events.'.$index.'.params.'.$key;

        return match ($key) {
            'property_code' => self::stringOf($value, $field, 32),
            'step' => self::stringOf(is_int($value) ? (string) $value : $value, $field, 32),
            'path' => self::paymentPath($value, $field),
            'currency' => self::currency($value, $field),
            'value' => self::intOf($value, $field, 0),
            'coupon_code' => self::coupon($value, $field),
            'page_path' => self::pagePath($value, $field),
            'count' => self::intOf($value, $field, 0),
            'check_in', 'check_out' => self::dateOf($value, $field),
            'adults' => self::intOf($value, $field, 1),
            'children' => self::intOf($value, $field, 0),
            'rooms' => self::intOf($value, $field, 1),
            'room_type' => self::stringOf($value, $field, 32),
            default => throw ValidationException::withMessages([$field => 'This parameter is not accepted.']),
        };
    }

    private static function stringOf(mixed $value, string $field, int $max): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => 'This value must be a string.']);
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > $max) {
            throw ValidationException::withMessages([$field => 'This value is not accepted.']);
        }

        return $trimmed;
    }

    private static function intOf(mixed $value, string $field, int $min): int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min) {
            throw ValidationException::withMessages([$field => 'This value must be an integer.']);
        }

        return $value;
    }

    private static function paymentPath(mixed $value, string $field): string
    {
        $path = is_string($value) ? CheckoutPath::tryFrom($value) : null;

        if (! $path instanceof CheckoutPath) {
            throw ValidationException::withMessages([$field => 'This value is not an accepted payment path.']);
        }

        return $path->value;
    }

    private static function currency(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^[A-Z]{3}$/', strtoupper(trim($value))) !== 1) {
            throw ValidationException::withMessages([$field => 'This value must be a three-letter currency code.']);
        }

        return strtoupper(trim($value));
    }

    private static function coupon(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,32}$/', trim($value)) !== 1) {
            throw ValidationException::withMessages([$field => 'This value is not an accepted coupon code.']);
        }

        return strtoupper(trim($value));
    }

    private static function dateOf(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'This value must be a date.']);
        }

        return $value;
    }

    private static function pagePath(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => 'This value must be a string.']);
        }

        $redacted = PagePath::redact($value);

        if ($redacted === null || ! str_starts_with($redacted, '/') || strlen($redacted) > 200) {
            throw ValidationException::withMessages([$field => 'This value is not an accepted page path.']);
        }

        return $redacted;
    }
}
