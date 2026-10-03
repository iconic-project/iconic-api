<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use Illuminate\Validation\Validator;
use LogicException;

trait ValidatesStay
{
    private ?StayDates $resolvedStay = null;

    /**
     * @return array<string, array<int, string>>
     */
    protected function stayFieldRules(): array
    {
        return [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
        ];
    }

    protected function validateStay(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['check_in', 'check_out'])) {
                return;
            }

            $stay = StayDates::of(
                (string) $this->input('check_in'),
                (string) $this->input('check_out'),
            );
            $maxNights = app(StayClock::class)->maxNights();

            if ($stay->nights() > $maxNights) {
                $validator->errors()->add(
                    'check_out',
                    'A stay cannot be longer than '.$maxNights.' nights.',
                );

                return;
            }

            $this->resolvedStay = $stay;
        });
    }

    public function stayDates(): StayDates
    {
        if (! $this->resolvedStay instanceof StayDates) {
            throw new LogicException('Stay dates are available after validation.');
        }

        return $this->resolvedStay;
    }
}
