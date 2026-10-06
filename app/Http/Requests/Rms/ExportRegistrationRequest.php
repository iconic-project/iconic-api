<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Validation\Rule;

class ExportRegistrationRequest extends FrontDeskDateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['required', 'string', Rule::in(['csv', 'pdf'])],
        ];
    }
}
