<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\ChannelOfOrigin;
use App\Enums\MainChannel;
use App\Enums\PreferredChannel;
use Illuminate\Validation\Rule;

class StoreReservationRequest extends QuoteReservationRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client' => ['required', 'array'],
            'client.name' => ['required', 'string', 'max:255'],
            'client.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'client.phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'client.country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'client.preferred_channel' => ['sometimes', Rule::enum(PreferredChannel::class)],
            'main_channel' => ['required', Rule::enum(MainChannel::class)],
            'channel_of_origin' => ['required', Rule::enum(ChannelOfOrigin::class)],
            'group' => ['sometimes', 'nullable', 'array'],
            'group.existing_group_id' => ['sometimes', 'integer', 'exists:groups,id'],
            'group.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'internal_notes' => ['sometimes', 'nullable', 'string'],
            'agency_id' => ['sometimes', 'nullable', 'integer', 'exists:agencies,id'],
            'commission_pct' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:30'],
            'override_restrictions' => ['sometimes', 'boolean'],
            'restriction_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_arrival_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'expected_total' => $this->filled('check_in')
                ? ['required', 'integer', 'min:0']
                : ['sometimes', 'integer', 'min:0'],
        ];
    }
}
