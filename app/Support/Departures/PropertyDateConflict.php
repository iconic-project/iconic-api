<?php

declare(strict_types=1);

namespace App\Support\Departures;

use App\Models\Departure;
use App\Models\Property;
use App\Support\Dates\Format;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class PropertyDateConflict
{
    public static function sundayMessage(DateTimeInterface $date): string
    {
        return 'Iconic sails Sunday → Sunday. '.Format::calendar($date).' is not a Sunday.';
    }

    public static function duplicateMessage(string $propertyCode, DateTimeInterface $date, ?string $reference): string
    {
        $base = $propertyCode.' already has a departure on '.Format::calendar($date);

        if (is_string($reference) && $reference !== '') {
            return $base.' ('.$reference.').';
        }

        return $base.'.';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function guard(Property $property, DateTimeInterface $date, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'departures_property_id_date_unique')) {
                throw $exception;
            }

            throw self::toValidation($property, $date);
        }
    }

    public static function toValidation(Property $property, DateTimeInterface $date): ValidationException
    {
        $winner = Departure::query()
            ->where('property_id', $property->id)
            ->whereDate('date', $date->format('Y-m-d'))
            ->first();

        return ValidationException::withMessages([
            'date' => [self::duplicateMessage($property->code, $date, $winner?->reference)],
        ]);
    }
}
