<?php

declare(strict_types=1);

namespace App\Support\Offers;

use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Models\Offer;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use Illuminate\Validation\ValidationException;

final class OfferGuardrails
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalize(array $attributes): array
    {
        if (isset($attributes['code']) && is_string($attributes['code'])) {
            $attributes['code'] = strtoupper(trim($attributes['code']));
        }

        $channel = self::channel($attributes['channel'] ?? null);
        $isPromo = (bool) ($attributes['is_promo_code'] ?? false);

        if ($channel === OfferChannel::B2B || $isPromo) {
            $attributes['show_on_card'] = false;
            $attributes['show_on_calendar'] = false;
        }

        foreach (['applies_to_room_types', 'applies_to_rate_plans'] as $list) {
            if (! array_key_exists($list, $attributes)) {
                continue;
            }

            $codes = self::stringList($attributes[$list]);
            $attributes[$list] = $codes === [] ? null : $codes;
        }

        if (array_key_exists('min_nights', $attributes) && ($attributes['min_nights'] === '' || $attributes['min_nights'] === null)) {
            $attributes['min_nights'] = null;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function validate(array $attributes, ?Offer $existing = null): void
    {
        $errors = [];

        $type = self::type($attributes['type'] ?? null);
        $channel = self::channel($attributes['channel'] ?? null);

        if ($type === OfferType::Value) {
            $text = trim((string) ($attributes['value_text'] ?? ''));

            if ($text === '') {
                $errors['value_text'] = ['Describe the value-add.'];
            }
        } elseif ($type instanceof OfferType) {
            $value = $attributes['value'] ?? null;

            if (! is_numeric($value) || (int) $value <= 0) {
                $errors['value'] = ['Enter a value above 0.'];
            }
        }

        if ($type === OfferType::Commission && $channel === OfferChannel::D2C) {
            $errors['channel'] = ['Partner commission offers must be B2B or All channels.'];
        }

        $bookingFrom = self::dateString($attributes['booking_from'] ?? null);
        $bookingTo = self::dateString($attributes['booking_to'] ?? null);

        if ($bookingFrom !== null && $bookingTo !== null && $bookingFrom > $bookingTo) {
            $errors['booking_to'] = ['Booking window ends before it starts.'];
        }

        $stayFrom = self::dateString($attributes['stay_from'] ?? null);
        $stayTo = self::dateString($attributes['stay_to'] ?? null);

        if ($stayFrom !== null && $stayTo !== null && $stayFrom > $stayTo) {
            $errors['stay_to'] = ['Stay window ends before it starts.'];
        }

        if (array_key_exists('min_nights', $attributes) && $attributes['min_nights'] !== null && $attributes['min_nights'] !== '') {
            $minNights = (int) $attributes['min_nights'];

            if ($minNights < 1) {
                $errors['min_nights'] = ['Minimum nights must be at least 1.'];
            }
        }

        $roomTypes = self::stringList($attributes['applies_to_room_types'] ?? []);

        if ($roomTypes !== []) {
            $known = RoomType::query()->whereIn('code', $roomTypes)->pluck('code')->all();
            $missing = array_values(array_diff($roomTypes, $known));

            if ($missing !== []) {
                $errors['applies_to_room_types'] = ['Unknown room type '.implode(', ', $missing).'.'];
            }
        }

        $plans = self::stringList($attributes['applies_to_rate_plans'] ?? []);

        if ($plans !== []) {
            $knownPlans = [];

            foreach (app(CurrentConfig::class)->rates()->ratePlans as $plan) {
                $knownPlans[] = $plan->code;
            }

            $missingPlans = array_values(array_diff($plans, $knownPlans));

            if ($missingPlans !== []) {
                $errors['applies_to_rate_plans'] = ['Unknown rate plan '.implode(', ', $missingPlans).'.'];
            }
        }

        if ($existing instanceof Offer && $existing->getOriginal('first_live_at') !== null) {
            $proposed = isset($attributes['code']) && is_string($attributes['code'])
                ? strtoupper(trim($attributes['code']))
                : $existing->code;
            $original = (string) ($existing->getOriginal('code') ?? $existing->code);

            if ($proposed !== $original) {
                $errors['code'] = ['The offer code cannot change after the offer has gone live. Pause it and create a new offer.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function type(mixed $value): ?OfferType
    {
        if ($value instanceof OfferType) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return OfferType::tryFrom($value);
        }

        return null;
    }

    private static function channel(mixed $value): ?OfferChannel
    {
        if ($value instanceof OfferChannel) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return OfferChannel::tryFrom($value);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) ? $value : null;
    }
}
