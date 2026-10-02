<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Actions\Departures\CreateDeparture;
use App\Enums\DepartureStatus;
use App\Enums\SeasonPattern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateSeasonRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'property_ids' => ['required', 'array', 'min:1'],
            'property_ids.*' => ['integer', 'distinct', 'exists:properties,id'],
            'pattern' => ['required', Rule::enum(SeasonPattern::class)],
            'festive_window' => ['required', 'boolean'],
            'status' => ['required', Rule::in([DepartureStatus::Closed->value, DepartureStatus::OnSale->value])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $after): void {
            if ($after->errors()->isNotEmpty()) {
                return;
            }

            $from = CreateDeparture::calendarDate($this->input('from'));
            $to = CreateDeparture::calendarDate($this->input('to'));

            if ($to->gt($from->addMonthsNoOverflow(18))) {
                $after->errors()->add('to', 'The range cannot be longer than 18 months.');
            }
        });
    }
}
