<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Enums\ConfigKind;
use App\Enums\RoomTypeStatus;
use App\Http\Requests\Rms\PriceCheckRequest;
use App\Http\Resources\Rms\PriceCheckResource;
use App\Http\Resources\Rms\RatesCurrentResource;
use App\Models\RoomType;
use App\Services\Config\ConfigValidator;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\GuestsInvalid;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\StayQuoteInput;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayReservationQuote;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Stays\StayDates;

class RatesController extends ConfigController
{
    protected function kind(): ConfigKind
    {
        return ConfigKind::Rates;
    }

    public function current(CurrentConfig $current): RatesCurrentResource
    {
        $this->authorizeView();

        $version = $current->version($this->kind())->load('publisher');

        return new RatesCurrentResource($version);
    }

    public function priceCheck(
        PriceCheckRequest $request,
        ConfigValidator $validator,
        CurrentConfig $current,
        StayQuoter $quoter,
    ): PriceCheckResource {
        $this->authorizeView();

        /** @var array<string, mixed> $document */
        $document = $request->validated('document');

        $validator->assertValid($this->kind(), $document);

        $draft = RatesDocument::fromArray($document);
        $versionId = (int) $current->version($this->kind())->id;

        /** @var list<array<string, mixed>>|null $stays */
        $stays = $request->validated('stays');

        if (! is_array($stays) || $stays === []) {
            $stays = $this->referenceStays();
        }

        $scenarios = [];

        foreach ($stays as $index => $stay) {
            $input = $this->stayInput($stay, $current);
            $room = RoomType::query()
                ->where('code', $input->roomType)
                ->where('status', RoomTypeStatus::Active)
                ->orderBy('id')
                ->first();

            if (! $room instanceof RoomType) {
                $publishedQuote = new NoRate('No room type '.$input->roomType.'.');
                $draftQuote = $publishedQuote;
            } else {
                $publishedQuote = $quoter->quote($room, $this->withVersion($input, $versionId));
                $draftQuote = $quoter->quote($room, $this->withVersion($input, null), $draft);
            }

            $nights = $input->stay->nights();
            $nightWord = $nights === 1 ? 'night' : 'nights';
            $key = isset($stay['id']) && is_string($stay['id']) && $stay['id'] !== ''
                ? $stay['id']
                : 'stay-'.$index;

            $scenarios[] = [
                'key' => $key,
                'label' => $input->roomType.' · '.$input->stay->checkIn()->toDateString().' · '.$nights.' '.$nightWord,
                'input' => [
                    'room_type' => $input->roomType,
                    'check_in' => $input->stay->checkIn()->toDateString(),
                    'check_out' => $input->stay->checkOut()->toDateString(),
                    'nights' => $nights,
                    'adults' => $input->adults,
                    'child_ages' => $input->childAges,
                    'rate_plan' => $input->ratePlan,
                ],
                'published' => $this->present($publishedQuote),
                'draft' => $this->present($draftQuote),
                'difference' => $this->difference($publishedQuote, $draftQuote),
            ];
        }

        return new PriceCheckResource($scenarios);
    }

    /**
     * @param  array<string, mixed>  $stay
     */
    private function stayInput(array $stay, CurrentConfig $current): StayQuoteInput
    {
        if (isset($stay['child_ages']) && is_array($stay['child_ages'])) {
            $ages = array_values(array_map(static fn (mixed $age): int => (int) $age, $stay['child_ages']));
        } else {
            $count = (int) ($stay['children'] ?? 0);
            $ages = $count > 0
                ? array_fill(0, $count, $current->engineSettings()->guests->childMinAge)
                : [];
        }

        return new StayQuoteInput(
            StayDates::forNights((string) $stay['check_in'], (int) $stay['nights']),
            (string) $stay['room_type'],
            (int) $stay['adults'],
            $ages,
            (string) $stay['rate_plan'],
        );
    }

    private function withVersion(StayQuoteInput $input, ?int $versionId): StayQuoteInput
    {
        return new StayQuoteInput(
            $input->stay,
            $input->roomType,
            $input->adults,
            $input->childAges,
            $input->ratePlan,
            $input->promo,
            $versionId,
            $input->onlineDeposit,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StayReservationQuote|NoRate|GuestsInvalid $quote): array
    {
        if ($quote instanceof StayReservationQuote) {
            return $quote->quote->toArray();
        }

        if ($quote instanceof GuestsInvalid) {
            return ['errors' => $quote->errors];
        }

        return $quote->toArray();
    }

    private function difference(
        StayReservationQuote|NoRate|GuestsInvalid $published,
        StayReservationQuote|NoRate|GuestsInvalid $draft,
    ): ?int {
        if (! $published instanceof StayReservationQuote || ! $draft instanceof StayReservationQuote) {
            return null;
        }

        return $draft->quote->total - $published->quote->total;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function referenceStays(): array
    {
        $path = base_path('docs/requirements/examples/hotel-seed-data.json');
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! is_array($decoded['reference_quotes'] ?? null)) {
            return [];
        }

        $stays = [];

        foreach ($decoded['reference_quotes'] as $quote) {
            if (! is_array($quote) || ! is_array($quote['input'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $input */
            $input = $quote['input'];
            $stays[] = [
                'id' => (string) ($quote['id'] ?? ''),
                'room_type' => (string) ($input['room_type'] ?? ''),
                'check_in' => (string) ($input['check_in'] ?? ''),
                'nights' => (int) ($input['nights'] ?? 0),
                'adults' => (int) ($input['adults'] ?? 0),
                'children' => (int) ($input['children'] ?? 0),
                'rate_plan' => (string) ($input['rate_plan'] ?? ''),
            ];
        }

        return $stays;
    }
}
