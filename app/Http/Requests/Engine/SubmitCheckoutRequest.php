<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use App\Enums\CheckoutPath;
use App\Enums\ConsentDocument;
use App\Enums\PreferredChannel;
use App\Models\CheckoutSession;
use App\Models\Room;
use App\Support\Countries;
use App\Support\Engine\CabinCodes;
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
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^\+/'],
            'preferred_channel' => ['required', Rule::enum(PreferredChannel::class)],
            'travel_advisor' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'marketing' => ['sometimes', 'boolean'],
            'png_collected' => ['required', 'boolean'],
            'tct_collected' => ['required', 'boolean'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'path' => ['required', Rule::enum(CheckoutPath::class)],
            'expected_total' => ['required', 'integer', 'min:0'],
            'declarations' => ['required', 'array', 'min:1'],
            'declarations.*' => ['required', Rule::enum(ConsentDocument::class)],
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.cabin_code' => ['required', 'string', 'max:32'],
            'guests.*.nationality' => ['required', 'string', Rule::in(Countries::codes())],
            'guests.*.ecuador_resident' => ['required', 'boolean'],
            'session_id' => EngineSessionId::rules(),
            'attribution' => ['sometimes', 'nullable', 'array'],
            'attribution.first_touch' => ['sometimes', 'nullable', 'array'],
            'attribution.last_touch' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $after): void {
            $path = $this->input('path');
            $path = is_string($path) ? CheckoutPath::tryFrom($path) : null;
            $accepted = array_map(
                static fn (mixed $value): string => is_string($value) ? $value : '',
                is_array($this->input('declarations')) ? $this->input('declarations') : [],
            );

            foreach ($this->requiredDocuments($path) as $document) {
                if (! in_array($document->value, $accepted, true)) {
                    $after->errors()->add('declarations', $document->label().' must be accepted.');
                }
            }

            $session = $this->sessionModel();

            if (! $session instanceof CheckoutSession) {
                return;
            }

            $expected = [];

            foreach ($session->cabins as $row) {
                $expected[$row['cabin_code']] = (int) $row['adults'] + (int) $row['children'];
            }

            $counts = [];
            $guests = $this->input('guests');

            if (! is_array($guests)) {
                return;
            }

            $session->loadMissing('departure.property.cabins');
            $departure = $session->departure;
            $normalized = [];

            foreach ($guests as $index => $guest) {
                if (! is_array($guest)) {
                    continue;
                }

                $raw = (string) ($guest['cabin_code'] ?? '');
                $cabin = CabinCodes::resolve($departure, $raw);
                $code = $cabin instanceof Room ? $cabin->code : $raw;
                $guest['cabin_code'] = $code;
                $normalized[] = $guest;

                if (! isset($expected[$code])) {
                    $after->errors()->add('guests.'.$index.'.cabin_code', 'This cabin is not on the checkout.');

                    continue;
                }

                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }

            if ($after->errors()->isEmpty()) {
                $this->merge(['guests' => $normalized]);
            }

            foreach ($expected as $code => $count) {
                if (($counts[$code] ?? 0) !== $count) {
                    $after->errors()->add('guests', 'Guest count for '.$code.' must match the held party.');
                }
            }
        });
    }

    /**
     * @return list<ConsentDocument>
     */
    private function requiredDocuments(?CheckoutPath $path): array
    {
        if ($path === CheckoutPath::PayDeposit) {
            return [
                ConsentDocument::Terms,
                ConsentDocument::Cancellation,
                ConsentDocument::Privacy,
                ConsentDocument::Insurance,
            ];
        }

        return [
            ConsentDocument::Privacy,
            ConsentDocument::Insurance,
        ];
    }

    private function sessionModel(): ?CheckoutSession
    {
        $token = $this->route('token');

        return is_string($token) ? CheckoutSession::findByToken($token) : null;
    }
}
