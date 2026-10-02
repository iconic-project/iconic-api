<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexCalendarRequest extends FormRequest
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
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'property_id' => ['sometimes', 'integer', 'exists:properties,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            [$from, $to] = $this->range();

            if ($to->lt($from)) {
                $validator->errors()->add('to', 'The end date must be on or after the start date.');
            }

            if ($from->addMonths(18)->lt($to)) {
                $validator->errors()->add('to', 'The calendar range cannot exceed 18 months.');
            }
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $today = BusinessTime::now()->startOfDay();

        $from = $this->filled('from')
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('from'))
            : $today;

        $to = $this->filled('to')
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('to'))
            : $from->addMonths(6);

        return [$from, $to];
    }
}
