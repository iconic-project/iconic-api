<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms\Concerns;

use App\Actions\Departures\CreateDeparture;
use App\Models\Departure;
use App\Support\Departures\PropertyDateConflict;
use Carbon\CarbonInterface;
use Illuminate\Validation\Validator;

trait ValidatesDepartureDate
{
    protected function validateSundayAndUniqueness(
        Validator $validator,
        ?int $ignoreId = null,
        mixed $dateInput = null,
        mixed $propertyId = null,
    ): void {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $dateInput ??= $this->input('date');
        $propertyId ??= $this->input('property_id');

        if (! is_string($dateInput) || $dateInput === '') {
            return;
        }

        $date = CreateDeparture::calendarDate($dateInput);

        if ($date->dayOfWeek !== CarbonInterface::SUNDAY) {
            $validator->errors()->add('date', PropertyDateConflict::sundayMessage($date));

            return;
        }

        if (! is_numeric($propertyId)) {
            return;
        }

        $existing = Departure::query()
            ->with('property')
            ->where('property_id', (int) $propertyId)
            ->whereDate('date', $date->toDateString())
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->first();

        if ($existing instanceof Departure) {
            $validator->errors()->add(
                'date',
                PropertyDateConflict::duplicateMessage($existing->property->code, $date, $existing->reference),
            );
        }
    }
}
