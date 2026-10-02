<?php

declare(strict_types=1);

namespace App\Actions\Departures;

use App\Actions\Action;
use App\Exceptions\ConflictException;
use App\Models\Departure;
use App\Models\Property;
use App\Services\Engine\EngineFeedVersion;
use App\Support\Departures\PropertyDateConflict;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class UpdateDeparture extends Action
{
    /** @var list<string> */
    private const HISTORY_EXCLUDED = [
        'updated_at',
        'updated_by',
        'created_at',
        'created_by',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Departure $departure, array $data): Departure
    {
        $propertyId = (int) ($data['property_id'] ?? $departure->property_id);
        $date = array_key_exists('date', $data)
            ? CreateDeparture::calendarDate($data['date'])
            : $departure->date;

        if ($date->dayOfWeek !== CarbonInterface::SUNDAY) {
            throw ValidationException::withMessages([
                'date' => [PropertyDateConflict::sundayMessage($date)],
            ]);
        }

        $property = Property::query()->findOrFail($propertyId);

        return PropertyDateConflict::guard($property, $date, function () use ($departure, $data, $date): Departure {
            $changed = false;

            $departure = $this->transaction(function () use ($departure, $data, $date, &$changed): Departure {
                $departure = DepartureLocks::lock((int) $departure->id);

                $propertyId = (int) ($data['property_id'] ?? $departure->property_id);
                $dateChanging = array_key_exists('date', $data)
                    && $date->toDateString() !== $departure->date->toDateString();
                $propertyChanging = array_key_exists('property_id', $data)
                    && $propertyId !== $departure->property_id;

                if ($dateChanging || $propertyChanging) {
                    $locked = DepartureLocks::dateAndPropertyCount(DepartureLocks::claimsFor($departure));

                    if ($locked > 0) {
                        throw new ConflictException(DepartureLocks::dateAndPropertyMessage($locked));
                    }
                }

                foreach ($data as $key => $value) {
                    $departure->setAttribute($key, $value);
                }

                if (! $departure->isDirty()) {
                    return $departure;
                }

                $departure->save();
                $changed = true;

                $changes = $departure->getChanges();
                $previous = $departure->getPrevious();

                $contentBefore = [];
                $contentAfter = [];

                foreach ($changes as $key => $value) {
                    if ($key === 'status' || in_array($key, self::HISTORY_EXCLUDED, true)) {
                        continue;
                    }

                    $contentBefore[$key] = $previous[$key] ?? null;
                    $contentAfter[$key] = $value;
                }

                if ($contentBefore !== []) {
                    History::record($departure, 'departure.updated', $contentBefore, $contentAfter);
                }

                if (array_key_exists('status', $changes)) {
                    History::record(
                        $departure,
                        'departure.status_changed',
                        ['status' => $previous['status'] ?? null],
                        ['status' => $changes['status']],
                    );
                }

                return $departure;
            });

            if ($changed) {
                EngineFeedVersion::bump();
            }

            return $departure;
        });
    }
}
