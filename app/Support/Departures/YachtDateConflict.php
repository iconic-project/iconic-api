<?php

declare(strict_types=1);

namespace App\Support\Departures;

use App\Models\Departure;
use App\Models\Yacht;
use App\Support\Dates\Format;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class YachtDateConflict
{
    public static function sundayMessage(DateTimeInterface $date): string
    {
        return 'Iconic sails Sunday → Sunday. '.Format::calendar($date).' is not a Sunday.';
    }

    public static function duplicateMessage(string $yachtCode, DateTimeInterface $date, ?string $reference): string
    {
        $base = $yachtCode.' already has a departure on '.Format::calendar($date);

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
    public static function guard(Yacht $yacht, DateTimeInterface $date, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'departures_yacht_id_date_unique')) {
                throw $exception;
            }

            throw self::toValidation($yacht, $date);
        }
    }

    public static function toValidation(Yacht $yacht, DateTimeInterface $date): ValidationException
    {
        $winner = Departure::query()
            ->where('yacht_id', $yacht->id)
            ->whereDate('date', $date->format('Y-m-d'))
            ->first();

        return ValidationException::withMessages([
            'date' => [self::duplicateMessage($yacht->code, $date, $winner?->reference)],
        ]);
    }
}
