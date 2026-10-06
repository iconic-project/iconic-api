<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use App\Enums\ConsentDocument;
use App\Enums\PreferredChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateCheckoutRequest extends FormRequest
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
            'quote_token' => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^\+/'],
            'preferred_channel' => ['required', Rule::enum(PreferredChannel::class)],
            'travel_advisor' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'marketing' => ['sometimes', 'boolean'],
            'declarations' => ['required', 'array', 'min:1'],
            'declarations.*' => ['required', Rule::enum(ConsentDocument::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $after): void {
            $accepted = array_map(
                static fn (mixed $value): string => is_string($value) ? $value : '',
                is_array($this->input('declarations')) ? $this->input('declarations') : [],
            );

            foreach ([ConsentDocument::Privacy, ConsentDocument::Insurance] as $document) {
                if (! in_array($document->value, $accepted, true)) {
                    $after->errors()->add('declarations', $document->label().' must be accepted.');
                }
            }
        });
    }
}
