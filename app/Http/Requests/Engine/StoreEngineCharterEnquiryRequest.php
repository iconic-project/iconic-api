<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use App\Enums\PreferredChannel;
use App\Services\Config\CurrentConfig;
use App\Support\Engine\EngineSessionId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEngineCharterEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $contact = $this->input('contact');

        if (! is_array($contact)) {
            return;
        }

        if (! isset($contact['name']) && (isset($contact['first_name']) || isset($contact['last_name']))) {
            $contact['name'] = trim((string) ($contact['first_name'] ?? '').' '.(string) ($contact['last_name'] ?? ''));
        }

        $this->merge(['contact' => $contact]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'departure_id' => ['required_without:preferred_from', 'nullable', 'integer', 'exists:departures,id'],
            'preferred_from' => ['required_without:departure_id', 'nullable', 'date'],
            'preferred_to' => ['required_with:preferred_from', 'nullable', 'date', 'after_or_equal:preferred_from'],
            'guests' => ['required', 'integer', 'min:1'],
            'contact' => ['required', 'array'],
            'contact.name' => ['required', 'string', 'max:255'],
            'contact.first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact.last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact.email' => ['required', 'email', 'max:255'],
            'contact.phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'contact.preferred_channel' => ['sometimes', Rule::enum(PreferredChannel::class)],
            'message' => ['required', 'string', 'max:4000'],
            'session_id' => EngineSessionId::rules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $after): void {
            $max = app(CurrentConfig::class)->engineSettings()->guests->maxPerProperty;
            $guests = (int) $this->input('guests');

            if ($guests > $max) {
                $after->errors()->add('guests', 'A property takes up to '.$max.' guests.');
            }
        });
    }
}
