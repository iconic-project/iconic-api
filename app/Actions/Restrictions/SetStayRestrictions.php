<?php

declare(strict_types=1);

namespace App\Actions\Restrictions;

use App\Actions\Action;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\StayRestriction;
use App\Models\User;
use App\Services\Engine\EngineFeedVersion;
use App\Support\History\History;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of stay_restrictions. from and to are inclusive calendar dates.
 * An empty room_type_ids list writes one property-wide row per night (room_type_id null).
 * A row that is all defaults is deleted.
 */
final class SetStayRestrictions extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?User $actor = null): void
    {
        $this->transaction(function () use ($data, $actor): void {
            $property = $this->property($data);
            $typeIds = $this->typeIds($property, $data);
            $from = $this->date($data['from'] ?? null, 'from');
            $to = $this->date($data['to'] ?? null, 'to');

            if ($to->lessThan($from)) {
                throw ValidationException::withMessages([
                    'to' => ['The end date is on or after the start date. The range is inclusive.'],
                ]);
            }

            $fields = $this->fields($data);
            $weekdays = $this->weekdays($data);
            $targets = $this->targets($from, $to, $weekdays, $typeIds);
            $existing = $this->existing($property, $from, $to, $typeIds);

            $plan = [];

            foreach ($targets as $target) {
                $key = $target['night'].'|'.($target['room_type_id'] ?? 'all');
                $row = $existing[$key] ?? new StayRestriction([
                    'property_id' => $property->id,
                    'room_type_id' => $target['room_type_id'],
                    'night' => $target['night'],
                    'stop_sell' => false,
                    'closed_to_arrival' => false,
                    'closed_to_departure' => false,
                ]);

                $this->apply($row, $fields);

                if ($row->min_stay !== null && $row->max_stay !== null && $row->min_stay > $row->max_stay) {
                    throw ValidationException::withMessages([
                        'min_stay' => ['Minimum stay cannot be longer than maximum stay.'],
                    ]);
                }

                $plan[] = $row;
            }

            foreach ($plan as $row) {
                if ($row->isDefault()) {
                    if ($row->exists) {
                        $row->delete();
                    }

                    continue;
                }

                $row->save();
            }

            $reason = $data['reason'] ?? null;
            $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

            History::record(
                $property,
                'restrictions.set',
                after: [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'inclusive' => true,
                    'weekdays' => $weekdays,
                    'room_type_ids' => $typeIds,
                    'fields' => $fields,
                    'nights' => count($targets),
                ],
                reason: $reason,
                actor: $actor,
                system: $actor === null,
            );
        });

        EngineFeedVersion::bump();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function property(array $data): Property
    {
        $id = $data['property_id'] ?? null;
        $property = is_numeric($id) ? Property::query()->find((int) $id) : null;

        if (! $property instanceof Property) {
            throw ValidationException::withMessages([
                'property_id' => ['Choose a property.'],
            ]);
        }

        return $property;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function typeIds(Property $property, array $data): array
    {
        $raw = $data['room_type_ids'] ?? [];

        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'room_type_ids' => ['Room types must be a list. An empty list is the whole property.'],
            ]);
        }

        $ids = [];

        foreach ($raw as $id) {
            if (! is_numeric($id)) {
                throw ValidationException::withMessages([
                    'room_type_ids' => ['Each room type must belong to the property.'],
                ]);
            }

            $ids[] = (int) $id;
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $found = RoomType::query()
            ->where('property_id', $property->id)
            ->whereIn('id', $ids)
            ->pluck('id');

        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'room_type_ids' => ['Each room type must belong to the property.'],
            ]);
        }

        return $ids;
    }

    private function date(mixed $value, string $key): CarbonImmutable
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $key => ['Use a date (YYYY-MM-DD). The range is inclusive.'],
            ]);
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                $key => ['Use a date (YYYY-MM-DD). The range is inclusive.'],
            ]);
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $keys = ['stop_sell', 'closed_to_arrival', 'closed_to_departure', 'min_stay', 'max_stay', 'note'];
        $fields = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key];
            }
        }

        if ($fields === []) {
            throw ValidationException::withMessages([
                'stop_sell' => ['Set at least one restriction.'],
            ]);
        }

        foreach (['min_stay', 'max_stay'] as $key) {
            if (! array_key_exists($key, $fields) || $fields[$key] === null) {
                continue;
            }

            if (! is_numeric($fields[$key]) || (int) $fields[$key] < 1) {
                throw ValidationException::withMessages([
                    $key => ['Stay length must be at least 1 night.'],
                ]);
            }
        }

        if (array_key_exists('note', $fields) && $fields['note'] !== null && ! is_string($fields['note'])) {
            throw ValidationException::withMessages([
                'note' => ['The note must be text.'],
            ]);
        }

        if (isset($fields['note']) && is_string($fields['note']) && strlen($fields['note']) > 500) {
            throw ValidationException::withMessages([
                'note' => ['The note may not be greater than 500 characters.'],
            ]);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>|null
     */
    private function weekdays(array $data): ?array
    {
        if (! array_key_exists('weekdays', $data)) {
            return null;
        }

        $raw = $data['weekdays'];

        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'weekdays' => ['Weekdays are ISO numbers, Monday 1 through Sunday 7.'],
            ]);
        }

        $days = [];

        foreach ($raw as $day) {
            if (! is_numeric($day) || (int) $day < 1 || (int) $day > 7) {
                throw ValidationException::withMessages([
                    'weekdays' => ['Weekdays are ISO numbers, Monday 1 through Sunday 7.'],
                ]);
            }

            $days[] = (int) $day;
        }

        return array_values(array_unique($days));
    }

    /**
     * @param  list<int>|null  $weekdays
     * @param  list<int>  $typeIds
     * @return list<array{night: string, room_type_id: int|null}>
     */
    private function targets(CarbonImmutable $from, CarbonImmutable $to, ?array $weekdays, array $typeIds): array
    {
        $scopes = $typeIds === [] ? [null] : $typeIds;
        $targets = [];
        $cursor = $from;

        while ($cursor->lessThanOrEqualTo($to)) {
            if ($weekdays === null || in_array($cursor->dayOfWeekIso, $weekdays, true)) {
                foreach ($scopes as $typeId) {
                    $targets[] = [
                        'night' => $cursor->toDateString(),
                        'room_type_id' => $typeId,
                    ];
                }
            }

            $cursor = $cursor->addDay();
        }

        return $targets;
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<string, StayRestriction>
     */
    private function existing(Property $property, CarbonImmutable $from, CarbonImmutable $to, array $typeIds): array
    {
        $query = StayRestriction::query()
            ->where('property_id', $property->id)
            ->whereBetween('night', [$from->toDateString(), $to->toDateString()]);

        if ($typeIds === []) {
            $query->whereNull('room_type_id');
        } else {
            $query->whereIn('room_type_id', $typeIds);
        }

        $indexed = [];

        foreach ($query->get() as $row) {
            $indexed[$row->night->toDateString().'|'.($row->room_type_id ?? 'all')] = $row;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function apply(StayRestriction $row, array $fields): void
    {
        foreach (['stop_sell', 'closed_to_arrival', 'closed_to_departure'] as $key) {
            if (array_key_exists($key, $fields)) {
                $row->{$key} = $fields[$key] === true || $fields[$key] === 1 || $fields[$key] === '1';
            }
        }

        foreach (['min_stay', 'max_stay'] as $key) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }

            $row->{$key} = $fields[$key] === null ? null : (int) $fields[$key];
        }

        if (array_key_exists('note', $fields)) {
            $note = $fields['note'];
            $row->note = is_string($note) && trim($note) !== '' ? trim($note) : null;
        }
    }
}
