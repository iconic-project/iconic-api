<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BookingSegment;
use App\Enums\BookingType;
use App\Enums\CabinState;
use App\Enums\MainChannel;
use App\Models\Cabin;
use App\Models\Departure;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Availability;
use App\Support\BusinessTime;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Inventory\DepartureSnapshot;
use Illuminate\Validation\ValidationException;

final class ReservationQuoter
{
    public function __construct(
        private CabinPricer $pricer,
        private CurrentConfig $config,
        private Availability $availability,
        private BookingDiscounts $discounts,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function quote(array $input, ?Departure $departure = null): ReservationQuote
    {
        $departure ??= Departure::query()
            ->with(['property.cabins', 'itinerary'])
            ->findOrFail((int) $input['departure_id']);

        $departure->loadMissing(['property.cabins']);

        $type = $input['type'] instanceof BookingType
            ? $input['type']
            : BookingType::from((string) $input['type']);
        $backToBack = (bool) ($input['back_to_back'] ?? false);
        $rows = $input['cabins'] ?? [];

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'cabins' => ['At least one party is required.'],
            ]);
        }

        $snapshots = $this->availability->forDepartures(collect([$departure]));
        $snapshot = $snapshots[$departure->id];
        $guests = $this->config->engineSettings()->guests;
        $rates = $input['rates'] ?? $this->config->rates();
        $rates = $rates instanceof RatesDocument ? $rates : $this->config->rates();
        $year = (int) $departure->date->format('Y');

        $context = $this->context($input);

        $parties = $type === BookingType::Charter
            ? [$this->quoteCharter($departure, $rows[0], $snapshot, $backToBack, $year, $guests->maxPerProperty, $rates)]
            : $this->quoteCabins($departure, $rows, $snapshot, $backToBack, $year, $guests->maxPerCabin, $rates, $context);

        $warnings = [];

        foreach ($parties as $party) {
            foreach ($party->warnings as $warning) {
                if (! in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }
            }
        }

        return new ReservationQuote(
            $departure,
            $type,
            $backToBack,
            $parties,
            $warnings,
            QuoteTerms::fromConfig($this->config, $type),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function quoteCharter(
        Departure $departure,
        array $row,
        DepartureSnapshot $snapshot,
        bool $backToBack,
        int $year,
        int $maxPerProperty,
        RatesDocument $rates,
    ): QuotedParty {
        $adults = max(0, (int) ($row['adults'] ?? 0));
        $children = max(0, (int) ($row['children'] ?? 0));
        $errors = [];
        $warnings = [];

        if ($adults < 1) {
            $errors[] = 'At least 1 adult is required.';
        }

        if ($adults + $children > $maxPerProperty) {
            $errors[] = 'Charter capacity is '.$maxPerProperty.' PAX — this party is '.($adults + $children).'.';
        }

        if ($children > $adults) {
            $warnings[] = 'Child rate allows max 1 child per adult (2 per couple).';
        }

        $taken = [];

        foreach ($snapshot->cabins as $cabinRow) {
            if ($cabinRow['state'] !== CabinState::Free->value) {
                $taken[] = $cabinRow['cabin']['label'];
            }
        }

        $available = $taken === [];

        $priced = $this->pricer->quote($rates, new QuoteInput(
            year: $year,
            type: QuoteType::Charter,
            adults: $adults,
            children: $children,
            festive: $departure->festive,
            backToBack: $backToBack,
        ));

        $quote = $priced instanceof Quote ? $priced : null;

        if ($priced instanceof NoRate) {
            $errors[] = $priced->reason;
        }

        return new QuotedParty(
            cabinCode: null,
            cabinLabel: 'Full property',
            adults: $adults,
            children: $children,
            available: $available,
            quote: $quote,
            errors: $errors,
            warnings: $warnings,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{channel: BookingSegment, booking_date: string, online_deposit: bool, promo_code: string|null}  $context
     * @return list<QuotedParty>
     */
    private function quoteCabins(
        Departure $departure,
        array $rows,
        DepartureSnapshot $snapshot,
        bool $backToBack,
        int $year,
        int $maxPerCabin,
        RatesDocument $rates,
        array $context,
    ): array {
        $used = [];
        $parties = [];

        foreach ($rows as $index => $row) {
            $code = isset($row['cabin_code']) ? (string) $row['cabin_code'] : '';
            $adults = max(0, (int) ($row['adults'] ?? 0));
            $children = max(0, (int) ($row['children'] ?? 0));
            $errors = [];
            $warnings = [];
            $cabin = $departure->property->cabins->first(
                fn (Cabin $item): bool => $item->code === $code,
            );

            if ($code === '' || ! $cabin instanceof Cabin) {
                $errors[] = 'Pick a cabin for cabin '.($index + 1).'.';
            } elseif (in_array($code, $used, true)) {
                $errors[] = ($cabin->label).' is selected twice.';
            } else {
                $used[] = $code;
            }

            if ($adults < 1) {
                $errors[] = 'At least 1 adult is required.';
            }

            if ($adults + $children > $maxPerCabin) {
                $errors[] = 'A suite takes up to '.$maxPerCabin.' guests — '.($adults + $children).' entered. Split into more cabins or book as a group.';
            }

            if ($children > $adults) {
                $warnings[] = 'Child rate allows max 1 child per adult (2 per couple).';
            }

            $available = false;
            $label = $cabin instanceof Cabin ? $cabin->label : ($code !== '' ? $code : 'Cabin '.($index + 1));

            if ($cabin instanceof Cabin) {
                foreach ($snapshot->cabins as $cabinRow) {
                    if ($cabinRow['cabin']['code'] === $cabin->code) {
                        $available = $cabinRow['state'] === CabinState::Free->value;
                        break;
                    }
                }
            }

            $quote = null;

            if ($cabin instanceof Cabin) {
                $priced = $this->pricer->quote($rates, new QuoteInput(
                    year: $year,
                    type: QuoteType::Cabin,
                    category: $cabin->category,
                    adults: $adults,
                    children: $children,
                    festive: $departure->festive,
                    backToBack: $backToBack,
                ));

                if ($priced instanceof Quote) {
                    $quote = $this->withDiscounts($priced, $departure, $cabin, $context, $adults, $children, $warnings);
                } else {
                    $errors[] = $priced->reason;
                }
            }

            $parties[] = new QuotedParty(
                cabinCode: $cabin instanceof Cabin ? $cabin->code : ($code !== '' ? $code : null),
                cabinLabel: $label,
                adults: $adults,
                children: $children,
                available: $available,
                quote: $quote,
                errors: $errors,
                warnings: $warnings,
                cabin: $cabin instanceof Cabin ? $cabin : null,
            );
        }

        return $parties;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{channel: BookingSegment, booking_date: string, online_deposit: bool, promo_code: string|null}
     */
    private function context(array $input): array
    {
        $channel = BookingSegment::D2C;

        if (isset($input['channel']) && $input['channel'] instanceof BookingSegment) {
            $channel = $input['channel'];
        } elseif (isset($input['main_channel']) && $input['main_channel'] !== '') {
            $main = $input['main_channel'] instanceof MainChannel
                ? $input['main_channel']
                : MainChannel::from((string) $input['main_channel']);
            $channel = $main->segment();
        }

        $bookingDate = isset($input['booking_date']) && is_string($input['booking_date']) && $input['booking_date'] !== ''
            ? $input['booking_date']
            : BusinessTime::now()->toDateString();

        $promo = $input['promo_code'] ?? null;

        return [
            'channel' => $channel,
            'booking_date' => $bookingDate,
            'online_deposit' => (bool) ($input['online_deposit'] ?? false),
            'promo_code' => is_string($promo) && trim($promo) !== '' ? $promo : null,
        ];
    }

    /**
     * @param  array{channel: BookingSegment, booking_date: string, online_deposit: bool, promo_code: string|null}  $context
     * @param  list<string>  $warnings
     */
    private function withDiscounts(
        Quote $priced,
        Departure $departure,
        Cabin $cabin,
        array $context,
        int $adults,
        int $children,
        array &$warnings,
    ): Quote {
        if ($departure->festive) {
            return $priced;
        }

        $applied = $this->discounts->apply(
            $priced,
            $departure,
            $cabin->category,
            $context['channel'],
            $context['booking_date'],
            $adults,
            $children,
            $context['online_deposit'],
            $context['promo_code'],
        );

        foreach ($applied['warnings'] as $warning) {
            if (! in_array($warning, $warnings, true)) {
                $warnings[] = $warning;
            }
        }

        return $applied['quote'];
    }
}
