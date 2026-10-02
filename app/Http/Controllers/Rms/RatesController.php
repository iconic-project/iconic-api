<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Enums\CabinCategory;
use App\Enums\ConfigKind;
use App\Http\Requests\Rms\PriceCheckRequest;
use App\Http\Resources\Rms\PriceCheckResource;
use App\Http\Resources\Rms\RatesCurrentResource;
use App\Services\Config\ConfigValidator;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\CabinPricer;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\Quote;
use App\Services\Pricing\QuoteInput;
use App\Services\Pricing\QuoteType;
use App\Support\Config\Documents\RatesDocument;

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
        CabinPricer $pricer,
    ): PriceCheckResource {
        $this->authorizeView();

        /** @var array<string, mixed> $document */
        $document = $request->validated('document');
        $year = (int) $request->validated('year');

        $validator->assertValid($this->kind(), $document);

        $published = $current->rates();
        $draft = RatesDocument::fromArray($document);

        $scenarios = [];

        foreach (self::scenarios($year) as $key => $scenario) {
            $publishedQuote = $pricer->quote($published, $scenario['input']);
            $draftQuote = $pricer->quote($draft, $scenario['input']);

            $scenarios[] = [
                'key' => $key,
                'label' => $scenario['label'],
                'published' => $publishedQuote->toArray(),
                'draft' => $draftQuote->toArray(),
                'difference' => self::difference($publishedQuote, $draftQuote),
            ];
        }

        return new PriceCheckResource($scenarios);
    }

    /**
     * @return array<string, array{label: string, input: QuoteInput}>
     */
    // TODO(Sprint 18): room type pricing (09 H8)
    private static function scenarios(int $year): array
    {
        return [
            'suite_2_adults' => [
                'label' => 'Suite · 2 adults',
                'input' => new QuoteInput($year, QuoteType::Cabin, CabinCategory::Suite, 2, 0),
            ],
            'suite_single' => [
                'label' => 'Suite · 1 adult (single)',
                'input' => new QuoteInput($year, QuoteType::Cabin, CabinCategory::Suite, 1, 0),
            ],
            'suite_triple' => [
                'label' => 'Suite · 3 adults (triple)',
                'input' => new QuoteInput($year, QuoteType::Cabin, CabinCategory::Suite, 3, 0),
            ],
            'suite_2_adults_1_child' => [
                'label' => 'Suite · 2 adults + 1 child',
                'input' => new QuoteInput($year, QuoteType::Cabin, CabinCategory::Suite, 2, 1),
            ],
            'owner_2_adults' => [
                'label' => "Owner's Suite · 2 adults",
                'input' => new QuoteInput($year, QuoteType::Cabin, CabinCategory::Owner, 2, 0),
            ],
            'suite_2_adults_festive' => [
                'label' => 'Suite · 2 adults · festive',
                'input' => new QuoteInput($year, QuoteType::Cabin, CabinCategory::Suite, 2, 0, festive: true),
            ],
            'charter' => [
                'label' => 'Charter · 1 week',
                'input' => new QuoteInput($year, QuoteType::Charter),
            ],
            'charter_festive' => [
                'label' => 'Charter · festive week',
                'input' => new QuoteInput($year, QuoteType::Charter, festive: true),
            ],
        ];
    }

    private static function difference(Quote|NoRate $published, Quote|NoRate $draft): ?int
    {
        if ($published instanceof NoRate || $draft instanceof NoRate) {
            return null;
        }

        return $draft->total - $published->total;
    }
}
