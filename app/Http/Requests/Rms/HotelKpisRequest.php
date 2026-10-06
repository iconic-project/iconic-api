<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\ChannelOfOriginGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HotelKpisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['property', 'room_type', 'channel'] as $key) {
            if ($this->input($key) === '') {
                $merged[$key] = null;
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'property' => ['nullable', 'integer', 'exists:properties,id'],
            'room_type' => ['nullable', 'integer', 'exists:room_types,id'],
            'channel' => ['nullable', Rule::enum(ChannelOfOriginGroup::class)],
        ];
    }

    public function propertyId(): ?int
    {
        return $this->filled('property') ? $this->integer('property') : null;
    }

    public function roomTypeId(): ?int
    {
        return $this->filled('room_type') ? $this->integer('room_type') : null;
    }

    public function channel(): ?ChannelOfOriginGroup
    {
        $channel = $this->validated('channel');

        return is_string($channel) ? ChannelOfOriginGroup::from($channel) : null;
    }
}
