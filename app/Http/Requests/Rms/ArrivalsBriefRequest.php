<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Foundation\Http\FormRequest;

class ArrivalsBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'format' => ['sometimes', 'in:html,pdf'],
        ];
    }

    public function arrivalDate(): string
    {
        return (string) $this->validated('date');
    }

    public function briefFormat(): string
    {
        return ($this->validated()['format'] ?? 'html') === 'pdf' ? 'pdf' : 'html';
    }
}
