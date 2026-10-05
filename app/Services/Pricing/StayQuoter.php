<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\ConfigKind;
use App\Enums\RoomTypeStatus;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Stays\StayDates;

/**
 * Loads published config, checks the party against the room, then prices the stay.
 * A draft rates document replaces the published rates. Rules and engine settings stay published.
 */
final class StayQuoter
{
    public function __construct(
        private CurrentConfig $config,
        private RoomPricer $pricer,
        private BookingDiscounts $discounts,
        private TaxCalculator $taxes,
    ) {}

    public function quote(
        RoomType $roomType,
        StayQuoteInput $input,
        ?RatesDocument $draft = null,
    ): StayReservationQuote|NoRate|GuestsInvalid {
        $invalid = $this->validateGuests($roomType, $input);

        if ($invalid instanceof GuestsInvalid) {
            return $invalid;
        }

        $rates = $draft ?? $this->config->rates();
        $priced = $this->pricer->quote($rates, $roomType, $input);

        if ($priced instanceof NoRate) {
            return $priced;
        }

        $priced = $this->withBands($priced, $rates, $input->ratePlan);
        $applied = $this->discounts->applyToStay($priced, $input->onlineDeposit);
        $party = new StayParty($input->adults, $input->childAges);
        $taxLines = $this->taxes->forStay(
            $applied['quote'],
            $party,
            $this->config->businessRules()->taxes,
        );

        return new StayReservationQuote(
            $roomType,
            $applied['quote']->withTaxes($taxLines),
            $applied['warnings'],
        );
    }

    /**
     * One quote per room. Group rules are not applied.
     *
     * @param  list<array{room_type: string, adults: int, child_ages?: list<int>, rate_plan?: string|null, promo?: string|null, online_deposit?: bool}>  $rooms
     */
    public function quoteRooms(StayDates $stay, array $rooms, ?RatesDocument $draft = null): StayRoomsQuote
    {
        $rates = $draft ?? $this->config->rates();
        $versionId = $draft instanceof RatesDocument
            ? null
            : (int) $this->config->version(ConfigKind::Rates)->id;
        $quoted = [];

        foreach ($rooms as $spec) {
            $code = $spec['room_type'];
            $roomType = RoomType::query()
                ->where('code', $code)
                ->where('status', RoomTypeStatus::Active)
                ->orderBy('id')
                ->first();

            if (! $roomType instanceof RoomType) {
                $quoted[] = [
                    'room_type' => $code,
                    'result' => new NoRate('No room type '.$code.'.'),
                ];

                continue;
            }

            $plan = $spec['rate_plan'] ?? null;

            if (! is_string($plan) || $plan === '') {
                $plan = $this->defaultPlan($rates);
            }

            $ages = [];

            foreach ($spec['child_ages'] ?? [] as $age) {
                $ages[] = (int) $age;
            }

            $promo = $spec['promo'] ?? null;

            $quoted[] = [
                'room_type' => $code,
                'result' => $this->quote($roomType, new StayQuoteInput(
                    $stay,
                    $roomType->code,
                    (int) $spec['adults'],
                    $ages,
                    $plan,
                    is_string($promo) && $promo !== '' ? $promo : null,
                    $versionId,
                    (bool) ($spec['online_deposit'] ?? false),
                ), $draft),
            ];
        }

        return new StayRoomsQuote(
            $stay,
            $quoted,
            $this->sum($quoted, 'total'),
            $this->sum($quoted, 'deposit'),
            $this->sum($quoted, 'totalIncludingChargedTaxes'),
        );
    }

    private function validateGuests(RoomType $roomType, StayQuoteInput $input): ?GuestsInvalid
    {
        $errors = [];
        $children = count($input->childAges);

        if ($input->adults < 1) {
            $errors[] = 'At least 1 adult is required.';
        }

        if ($input->adults > $roomType->max_adults) {
            $errors[] = 'This room takes at most '.$roomType->max_adults.' '.$this->noun($roomType->max_adults, 'adult', 'adults').'.';
        }

        if ($children > $roomType->max_children) {
            $errors[] = 'This room takes at most '.$roomType->max_children.' '.$this->noun($roomType->max_children, 'child', 'children').'.';
        }

        if ($input->adults + $children > $roomType->max_occupancy) {
            $errors[] = 'This room takes at most '.$roomType->max_occupancy.' '.$this->noun($roomType->max_occupancy, 'guest', 'guests').'.';
        }

        $guests = $this->config->engineSettings()->guests;
        $underAge = false;

        foreach ($input->childAges as $age) {
            if ($age < $guests->childMinAge) {
                $underAge = true;
            }

            if ($age > $guests->childMaxAge) {
                $errors[] = 'A child aged '.$age.' is over the maximum age of '.$guests->childMaxAge.'.';
            }
        }

        if ($underAge && $guests->underAgeMessage !== '') {
            $errors[] = $guests->underAgeMessage;
        }

        return $errors === [] ? null : new GuestsInvalid($errors);
    }

    private function withBands(StayQuote $quote, RatesDocument $rates, string $planCode): StayQuote
    {
        foreach ($rates->ratePlans as $plan) {
            if ($plan->code !== $planCode) {
                continue;
            }

            $bands = $this->config->businessRules()->cancellationSets[$plan->cancellation] ?? [];

            return $quote->withTerms(QuoteTerms::forPlan($plan, $bands));
        }

        return $quote;
    }

    private function noun(int $count, string $one, string $many): string
    {
        return $count === 1 ? $one : $many;
    }

    private function defaultPlan(RatesDocument $rates): string
    {
        foreach ($rates->ratePlans as $plan) {
            if ($plan->isDefault) {
                return $plan->code;
            }
        }

        $first = $rates->ratePlans[0] ?? null;

        return $first instanceof RatePlan ? $first->code : '';
    }

    /**
     * @param  list<array{room_type: string, result: StayReservationQuote|NoRate|GuestsInvalid}>  $rooms
     */
    private function sum(array $rooms, string $field): ?int
    {
        $total = 0;

        foreach ($rooms as $room) {
            $result = $room['result'];

            if (! $result instanceof StayReservationQuote) {
                return null;
            }

            $total += match ($field) {
                'deposit' => $result->quote->deposit,
                'totalIncludingChargedTaxes' => $result->quote->totalIncludingChargedTaxes,
                default => $result->quote->total,
            };
        }

        return $total;
    }
}
