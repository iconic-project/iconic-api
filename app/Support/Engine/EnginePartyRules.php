<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\Room;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use Illuminate\Validation\Validator;

final class EnginePartyRules
{
    public function __construct(private readonly CurrentConfig $config) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function apply(Validator $validator, array $rows): void
    {
        $guests = $this->config->engineSettings()->guests;
        $party = 0;
        $seen = [];

        if (count($rows) > 9) {
            $validator->errors()->add('rooms', 'A property has 9 rooms.');
        }

        foreach ($rows as $index => $row) {
            $adults = max(0, (int) ($row['adults'] ?? 0));
            $children = is_array($row['child_ages'] ?? null)
                ? count($row['child_ages'])
                : max(0, (int) ($row['children'] ?? 0));
            $field = 'rooms.'.$index;
            $party += $adults + $children;
            $identity = $this->identity($row);

            if ($identity !== '') {
                if (in_array($identity, $seen, true)) {
                    $validator->errors()->add($field, 'This room is selected twice.');
                }

                $seen[] = $identity;
            }

            $limit = $this->occupancyLimit($row);

            if ($limit !== null && $adults + $children > $limit) {
                $validator->errors()->add(
                    $field.'.adults',
                    'A room takes up to '.$limit.' guests.',
                );
            }

            if ($adults + $children < 1) {
                $validator->errors()->add($field.'.adults', 'A room cannot be empty.');
            }

            if ($guests->adultRequiredWithChildren && $children > 0 && $adults < 1) {
                $validator->errors()->add(
                    $field.'.adults',
                    'A child may not occupy a room without an adult.',
                );
            }
        }

        if ($party > $guests->maxPerProperty) {
            $validator->errors()->add('rooms', 'A property takes up to '.$guests->maxPerProperty.' guests.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function identity(array $row): string
    {
        if (isset($row['room_id']) && is_numeric($row['room_id'])) {
            return 'id:'.(string) $row['room_id'];
        }

        $code = $this->roomCode($row);

        return $code === '' ? '' : 'code:'.$code;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function occupancyLimit(array $row): ?int
    {
        $typeCode = isset($row['room_type']) ? trim((string) $row['room_type']) : '';

        if ($typeCode !== '') {
            $type = RoomType::query()->where('code', $typeCode)->first();

            if ($type instanceof RoomType) {
                return $type->max_occupancy;
            }
        }

        $code = $this->roomCode($row);

        if ($code !== '') {
            $room = Room::query()->with('roomType')->where('code', $code)->first();

            if ($room instanceof Room) {
                return $room->roomType->max_occupancy;
            }
        }

        if (isset($row['room_id']) && is_numeric($row['room_id'])) {
            $room = Room::query()->with('roomType')->find((int) $row['room_id']);

            if ($room instanceof Room) {
                return $room->roomType->max_occupancy;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function roomCode(array $row): string
    {
        if (isset($row['room_code']) && is_string($row['room_code'])) {
            return $row['room_code'];
        }

        $legacy = 'cab'.'in_code';
        $code = $row[$legacy] ?? null;

        return is_string($code) ? $code : '';
    }
}
