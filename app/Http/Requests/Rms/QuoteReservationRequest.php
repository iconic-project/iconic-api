<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\BookingType;
use App\Enums\MainChannel;
use App\Http\Requests\Concerns\ValidatesStay;
use App\Services\Config\CurrentConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuoteReservationRequest extends FormRequest
{
    use ValidatesStay;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        if ($this->filled('check_in')) {
            return [
                ...$this->stayFieldRules(),
                'rooms' => ['required', 'array', 'min:1'],
                'rooms.*.room_type' => ['required', 'string', 'max:16'],
                'rooms.*.room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
                'rooms.*.check_in' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'rooms.*.check_out' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'rooms.*.adults' => ['required', 'integer', 'min:0', 'max:36'],
                'rooms.*.child_ages' => ['sometimes', 'array'],
                'rooms.*.child_ages.*' => ['integer', 'min:0', 'max:120'],
                'rooms.*.rate_plan' => ['sometimes', 'nullable', 'string', 'max:32'],
                'rooms.*.promo' => ['sometimes', 'nullable', 'string', 'max:32'],
                'rooms.*.online_deposit' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'departure_id' => ['required', 'integer', 'exists:departures,id'],
            'type' => ['required', Rule::enum(BookingType::class)],
            'back_to_back' => ['sometimes', 'boolean'],
            'cabins' => ['required', 'array', 'min:1'],
            'cabins.*.cabin_code' => ['nullable', 'string', 'max:16'],
            'cabins.*.adults' => ['required', 'integer', 'min:0', 'max:36'],
            'cabins.*.children' => ['required', 'integer', 'min:0', 'max:36'],
            'main_channel' => ['sometimes', 'nullable', Rule::enum(MainChannel::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cabins.*.adults' => 'adults',
            'cabins.*.children' => 'children',
            'cabins.*.cabin_code' => 'cabin',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cabins.*.adults.required' => 'At least 1 adult is required.',
            'cabins.*.adults.integer' => 'At least 1 adult is required.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->filled('check_in')) {
            $this->validateStay($validator);
        }

        $validator->after(function (Validator $after): void {
            if ($this->filled('check_in')) {
                $this->validateStayRooms($after);

                return;
            }

            $type = $this->input('type');
            $cabins = $this->input('cabins');

            if (! is_array($cabins)) {
                return;
            }

            if ($type === BookingType::Charter->value) {
                if (count($cabins) !== 1) {
                    $after->errors()->add('cabins', 'A charter has one party and no cabin code.');
                }

                if (isset($cabins[0]) && is_array($cabins[0]) && filled($cabins[0]['cabin_code'] ?? null)) {
                    $after->errors()->add('cabins.0.cabin_code', 'A charter has no cabin code.');
                }

                return;
            }

            foreach ($cabins as $index => $row) {
                if (! is_array($row) || blank($row['cabin_code'] ?? null)) {
                    $after->errors()->add('cabins.'.$index.'.cabin_code', 'Pick a cabin.');
                }
            }
        });
    }

    private function validateStayRooms(Validator $validator): void
    {
        $rooms = $this->input('rooms');

        if (! is_array($rooms)) {
            return;
        }

        $max = app(CurrentConfig::class)->businessRules()->stay->maxRoomsPerBooking;

        if (count($rooms) > $max) {
            $validator->errors()->add('rooms', 'A booking can include at most '.$max.' rooms.');
        }

        foreach ($rooms as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $checkIn = $row['check_in'] ?? null;
            $checkOut = $row['check_out'] ?? null;
            $hasIn = is_string($checkIn) && $checkIn !== '';
            $hasOut = is_string($checkOut) && $checkOut !== '';

            if ($hasIn !== $hasOut) {
                $validator->errors()->add(
                    'rooms.'.$index.'.check_out',
                    'Give both check-in and check-out for this room.',
                );
            }

            if ($hasIn && $hasOut && $checkOut <= $checkIn) {
                $validator->errors()->add(
                    'rooms.'.$index.'.check_out',
                    'Check-out must be after check-in.',
                );
            }
        }
    }
}
