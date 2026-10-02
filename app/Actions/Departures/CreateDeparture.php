<?php

declare(strict_types=1);

namespace App\Actions\Departures;

use App\Actions\Action;
use App\Enums\DepartureStatus;
use App\Enums\ReferenceType;
use App\Models\Departure;
use App\Models\Property;
use App\Services\Engine\EngineFeedVersion;
use App\Services\References\ReferenceService;
use App\Support\Departures\PropertyDateConflict;
use App\Support\History\History;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

final class CreateDeparture extends Action
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $historyContext
     */
    public function handle(array $data, array $historyContext = []): Departure
    {
        $property = Property::query()->findOrFail((int) $data['property_id']);
        $date = self::calendarDate($data['date']);

        if ($date->dayOfWeek !== CarbonInterface::SUNDAY) {
            throw ValidationException::withMessages([
                'date' => [PropertyDateConflict::sundayMessage($date)],
            ]);
        }

        return PropertyDateConflict::guard($property, $date, function () use ($data, $historyContext, $date): Departure {
            $departure = $this->transaction(function () use ($data, $historyContext, $date): Departure {
                $departure = Departure::query()->create([
                    'reference' => app(ReferenceService::class)->next(ReferenceType::Departure),
                    'date' => $date->toDateString(),
                    'property_id' => $data['property_id'],
                    'itinerary_id' => $data['itinerary_id'],
                    'status' => $data['status'] ?? DepartureStatus::Closed,
                    'urgency_threshold' => $data['urgency_threshold'] ?? 3,
                    'waitlist_enabled' => $data['waitlist_enabled'] ?? true,
                    'public_note' => $data['public_note'] ?? null,
                    'festive' => $data['festive'] ?? false,
                ]);

                History::record($departure, 'departure.created', extraContext: $historyContext);

                return $departure;
            });

            EngineFeedVersion::bump();

            return $departure;
        });
    }

    public static function calendarDate(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value->format('Y-m-d')) ?: CarbonImmutable::parse($value->format('Y-m-d'));
        }

        $string = is_string($value) ? $value : (string) $value;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', substr($string, 0, 10));

        if (! $date instanceof CarbonImmutable) {
            throw ValidationException::withMessages([
                'date' => ['The date is not a valid calendar date.'],
            ]);
        }

        return $date;
    }
}
