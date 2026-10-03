<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesStay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StayRequest extends FormRequest
{
    use ValidatesStay;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return $this->stayFieldRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateStay($validator);
    }
}
