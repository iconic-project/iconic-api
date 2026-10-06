<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

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
            'main_channel' => ['sometimes', 'nullable', Rule::enum(MainChannel::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateStay($validator);

        $validator->after(function (Validator $after): void {
            $this->validateStayRooms($after);
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
