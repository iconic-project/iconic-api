<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms\Concerns;

use App\Enums\CabinCategory;
use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Models\Offer;
use App\Support\Offers\OfferGuardrails;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

trait ValidatesOfferFields
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function offerFieldRules(bool $required, ?Offer $existing = null): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'code' => [$presence, 'string', 'max:20', Rule::unique('offers', 'code')->ignore($existing?->id)],
            'name' => [$presence, 'string', 'max:255'],
            'type' => [$presence, Rule::enum(OfferType::class)],
            'value' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'value_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'channel' => [$presence, Rule::enum(OfferChannel::class)],
            'partner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cabin_types' => [$presence, 'array', 'min:1'],
            // TODO(Sprint 18): room type pricing (09 H8)
            'cabin_types.*' => [Rule::enum(CabinCategory::class)],
            'itinerary_codes' => [$presence, 'array', 'min:1'],
            'itinerary_codes.*' => ['string', 'max:10'],
            'booking_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'booking_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'travel_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'travel_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'combinable' => ['sometimes', 'boolean'],
            'is_promo_code' => ['sometimes', 'boolean'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:18'],
            'show_on_card' => ['sometimes', 'boolean'],
            'show_on_departures' => ['sometimes', 'boolean'],
            'price_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'terms' => ['sometimes', 'nullable', 'string'],
            'as_draft' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareOfferFields(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => strtoupper(trim($code))]);
        }

        foreach (['booking_from', 'booking_to', 'travel_from', 'travel_to', 'partner', 'badge', 'price_line', 'terms', 'value_text'] as $field) {
            if ($this->exists($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    protected function withOfferGuardrails(Validator $validator, ?Offer $existing = null): void
    {
        $validator->after(function (Validator $validator) use ($existing): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $merged = $existing instanceof Offer
                ? array_merge($existing->attributesForGuardrails(), $this->validated())
                : $this->validated();

            try {
                OfferGuardrails::validate(OfferGuardrails::normalize($merged), $existing);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add((string) $field, (string) $message);
                    }
                }
            }
        });
    }
}
