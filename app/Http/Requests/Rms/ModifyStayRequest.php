<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

class ModifyStayRequest extends PreviewModifyStayRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
