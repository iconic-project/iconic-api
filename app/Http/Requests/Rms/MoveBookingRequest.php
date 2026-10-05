<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

class MoveBookingRequest extends PreviewMoveBookingRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'confirm_total' => ['required', 'integer'],
            'override_restrictions' => ['sometimes', 'boolean'],
            'restriction_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
