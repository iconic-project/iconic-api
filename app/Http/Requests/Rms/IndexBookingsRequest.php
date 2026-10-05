<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\BookingSegment;
use App\Enums\BookingStatus;
use App\Enums\MainChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBookingsRequest extends FormRequest
{
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
            'segment' => ['sometimes', Rule::enum(BookingSegment::class)],
            'status' => ['sometimes', Rule::enum(BookingStatus::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'arriving_from' => ['sometimes', 'date_format:Y-m-d'],
            'arriving_to' => ['sometimes', 'date_format:Y-m-d'],
            'departing_from' => ['sometimes', 'date_format:Y-m-d'],
            'departing_to' => ['sometimes', 'date_format:Y-m-d'],
            'in_house_on' => ['sometimes', 'date_format:Y-m-d'],
            'owner_id' => ['sometimes', 'integer', 'exists:users,id'],
            'channel' => ['sometimes', Rule::enum(MainChannel::class)],
            'departure_id' => ['sometimes', 'integer', 'exists:departures,id'],
            'group_id' => ['sometimes', 'integer', 'exists:groups,id'],
            'q' => ['sometimes', 'string', 'max:255'],
            'mine' => ['sometimes', 'boolean'],
            'overdue' => ['sometimes', 'boolean'],
            'pending_payment' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
