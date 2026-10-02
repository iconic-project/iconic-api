<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine\Concerns;

use App\Models\Departure;
use App\Models\Room;
use App\Support\Engine\CabinCodes;
use Illuminate\Validation\Validator;

trait NormalizesEngineCabins
{
    protected function departureForCabins(): ?Departure
    {
        $id = $this->input('departure_id');

        if (! is_numeric($id)) {
            return null;
        }

        return Departure::query()->with(['property.cabins', 'itinerary'])->find((int) $id);
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('cabins');

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (! isset($row['cabin_code']) && isset($row['code'])) {
                $rows[$index]['cabin_code'] = $row['code'];
            }
        }

        $this->merge(['cabins' => $rows]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function cabinPartyRules(): array
    {
        return [
            'cabins' => ['required', 'array', 'min:1'],
            'cabins.*.cabin_code' => ['required_without:cabins.*.code', 'string', 'max:32'],
            'cabins.*.code' => ['required_without:cabins.*.cabin_code', 'string', 'max:32'],
            'cabins.*.adults' => ['required', 'integer', 'min:0', 'max:36'],
            'cabins.*.children' => ['required', 'integer', 'min:0', 'max:36'],
        ];
    }

    protected function validateAndNormalizeCabins(Validator $validator): void
    {
        $validator->after(function (Validator $after): void {
            $departure = $this->departureForCabins();
            $rows = $this->input('cabins');

            if (! $departure instanceof Departure || ! is_array($rows)) {
                return;
            }

            $normalized = [];

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $code = isset($row['cabin_code'])
                    ? (string) $row['cabin_code']
                    : (isset($row['code']) ? (string) $row['code'] : '');
                $cabin = CabinCodes::resolve($departure, $code);

                if (! $cabin instanceof Room) {
                    $after->errors()->add('cabins.'.$index.'.cabin_code', 'Pick a cabin.');

                    continue;
                }

                $row['cabin_code'] = $cabin->code;
                $normalized[] = $row;
            }

            if ($after->errors()->isEmpty()) {
                $this->merge(['cabins' => $normalized]);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cabinRows(): array
    {
        $rows = $this->input('cabins');

        return is_array($rows) ? array_values($rows) : [];
    }
}
