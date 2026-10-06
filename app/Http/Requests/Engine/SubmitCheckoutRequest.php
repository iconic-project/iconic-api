<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use App\Enums\CheckoutPath;
use App\Models\CheckoutSession;
use App\Support\Engine\EngineSessionId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubmitCheckoutRequest extends FormRequest
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
            'path' => ['required', Rule::enum(CheckoutPath::class)],
            'expected_total' => ['required', 'integer', 'min:0'],
            'session_id' => EngineSessionId::rules(),
            'attribution' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->staySession()) {
            return;
        }

        $validator->after(function (Validator $after): void {
            $after->errors()->add('token', 'This reservation shape has been retired.');
        });
    }

    private function staySession(): bool
    {
        return $this->sessionModel()?->isStay() === true;
    }

    private function sessionModel(): ?CheckoutSession
    {
        $token = $this->route('token');

        return is_string($token) ? CheckoutSession::findByToken($token) : null;
    }
}
