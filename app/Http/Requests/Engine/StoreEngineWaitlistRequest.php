<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use App\Enums\PreferredChannel;
use App\Support\Engine\EngineSessionId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEngineWaitlistRequest extends FormRequest
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
            'room_type' => ['required', 'string', 'max:32'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'contact' => ['required', 'array'],
            'contact.name' => ['required', 'string', 'max:255'],
            'contact.email' => ['required', 'email', 'max:255'],
            'contact.phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'contact.preferred_channel' => ['sometimes', Rule::enum(PreferredChannel::class)],
            'adults' => ['required', 'integer', 'min:1', 'max:36'],
            'children' => ['required', 'integer', 'min:0', 'max:36'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'session_id' => EngineSessionId::rules(),
        ];
    }
}
