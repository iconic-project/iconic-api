<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Enums\DepartureStatus;
use App\Enums\EngineLabelCode;
use App\Enums\ItineraryStatus;
use App\Enums\OfferChannel;
use App\Enums\OfferStatus;
use App\Http\Resources\Engine\EngineDepartureResource;
use App\Http\Resources\Engine\EngineItineraryResource;
use App\Http\Resources\Engine\EngineOfferResource;
use App\Http\Resources\Engine\EngineRatesResource;
use App\Http\Resources\Engine\EngineSettingsResource;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Offer;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Availability;
use App\Support\Bookings\SoldOn;
use App\Support\Inventory\DepartureSnapshot;
use App\Support\Iso;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class EngineFeed
{
    public function __construct(
        private Availability $availability,
        private CurrentConfig $config,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     itineraries: list<array<string, mixed>>,
     *     departures: list<array<string, mixed>>,
     *     rates: array<string, mixed>,
     *     settings: array<string, mixed>,
     *     offers: list<array<string, mixed>>
     * }
     */
    public function payload(): array
    {
        $version = EngineFeedVersion::current();

        /** @var array{
         *     generated_at: string,
         *     itineraries: list<array<string, mixed>>,
         *     departures: list<array<string, mixed>>,
         *     rates: array<string, mixed>,
         *     settings: array<string, mixed>,
         *     offers: list<array<string, mixed>>
         * } $payload
         */
        $payload = Cache::remember(
            EngineFeedVersion::payloadKey($version),
            15,
            fn (): array => $this->assemble(),
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function etag(array $payload): string
    {
        $copy = $payload;
        unset($copy['generated_at']);

        return hash('sha256', (string) json_encode($copy));
    }

    public function isVisible(Departure $departure): bool
    {
        $departure->loadMissing(['property.cabins', 'itinerary']);
        $snapshots = $this->availability->forDepartures(collect([$departure]));
        $snapshot = $snapshots[$departure->id] ?? null;

        if (! $snapshot instanceof DepartureSnapshot) {
            return false;
        }

        return $this->snapshotIsVisible($departure, $snapshot);
    }

    /**
     * @return array{
     *     generated_at: string,
     *     itineraries: list<array<string, mixed>>,
     *     departures: list<array<string, mixed>>,
     *     rates: array<string, mixed>,
     *     settings: array<string, mixed>,
     *     offers: list<array<string, mixed>>
     * }
     */
    private function assemble(): array
    {
        $itineraries = Itinerary::query()
            ->where('status', ItineraryStatus::Published)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $departures = Departure::query()
            ->with(['property.cabins', 'itinerary'])
            ->where('status', '!=', DepartureStatus::Hidden)
            ->whereHas('itinerary', fn ($query) => $query->where('status', ItineraryStatus::Published))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $snapshots = $this->availability->forDepartures($departures);
        $publicOffers = $this->publicBadgeOffers();
        $today = SoldOn::today();

        $visible = [];

        foreach ($departures as $departure) {
            $snapshot = $snapshots[$departure->id];

            if (! $this->snapshotIsVisible($departure, $snapshot)) {
                continue;
            }

            $visible[] = [
                'departure' => $departure,
                'snapshot' => $snapshot,
                'offer_codes' => $this->offerCodesFor($departure, $publicOffers, $today),
            ];
        }

        return [
            'generated_at' => (string) Iso::utc(now()),
            'itineraries' => $itineraries
                ->map(fn (Itinerary $itinerary): array => (new EngineItineraryResource($itinerary))->resolve())
                ->values()
                ->all(),
            'departures' => array_map(
                fn (array $row): array => (new EngineDepartureResource($row))->resolve(),
                $visible,
            ),
            'rates' => (new EngineRatesResource($this->config->rates()))->resolve(),
            'settings' => (new EngineSettingsResource([
                'settings' => $this->config->engineSettings(),
                'rules' => $this->config->businessRules(),
            ]))->resolve(),
            'offers' => $publicOffers
                ->map(fn (Offer $offer): array => (new EngineOfferResource($offer))->resolve())
                ->values()
                ->all(),
        ];
    }

    private function snapshotIsVisible(Departure $departure, DepartureSnapshot $snapshot): bool
    {
        if ($departure->status === DepartureStatus::Hidden) {
            return false;
        }

        if ($departure->itinerary->status !== ItineraryStatus::Published) {
            return false;
        }

        $code = $snapshot->engineLabel['code'];

        return $code !== EngineLabelCode::NotShown->value
            && $code !== EngineLabelCode::Chartered->value;
    }

    /**
     * @return Collection<int, Offer>
     */
    private function publicBadgeOffers(): Collection
    {
        $today = SoldOn::today();

        return Offer::query()
            ->where('status', OfferStatus::Live)
            ->where('is_promo_code', false)
            ->whereIn('channel', [OfferChannel::D2C, OfferChannel::All])
            ->orderBy('code')
            ->get()
            ->filter(fn (Offer $offer): bool => ! $offer->isDerivedExpired($today) && $offer->enginePlacement() === 'badge')
            ->values();
    }

    /**
     * @param  Collection<int, Offer>  $offers
     * @return list<string>
     */
    private function offerCodesFor(Departure $departure, Collection $offers, string $today): array
    {
        if ($departure->festive) {
            return [];
        }

        $codes = [];

        foreach ($offers as $offer) {
            if ($this->appliesToDeparture($offer, $departure, $today)) {
                $codes[] = $offer->code;
            }
        }

        return $codes;
    }

    private function appliesToDeparture(Offer $offer, Departure $departure, string $today): bool
    {
        $itineraryCode = $departure->itinerary->code;

        if (! in_array($itineraryCode, $offer->itinerary_codes, true)) {
            return false;
        }

        $travelDate = $departure->date->toDateString();

        if ($offer->travel_from instanceof CarbonInterface && $offer->travel_from->toDateString() > $travelDate) {
            return false;
        }

        if ($offer->travel_to instanceof CarbonInterface && $offer->travel_to->toDateString() < $travelDate) {
            return false;
        }

        if ($offer->booking_from instanceof CarbonInterface && $offer->booking_from->toDateString() > $today) {
            return false;
        }

        if ($offer->booking_to instanceof CarbonInterface && $offer->booking_to->toDateString() < $today) {
            return false;
        }

        return $offer->cabin_types !== [];
    }
}
