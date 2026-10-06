<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Actions\Crm\ArchiveCampaign;
use App\Actions\Crm\SaveCampaign;
use App\Enums\OfferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCampaignRequest;
use App\Http\Requests\Crm\UpdateCampaignRequest;
use App\Http\Resources\Crm\AttributionModelResource;
use App\Http\Resources\Crm\CampaignBookingPageResource;
use App\Http\Resources\Crm\CampaignIndexResource;
use App\Http\Resources\Crm\CampaignOffersResource;
use App\Models\Booking;
use App\Models\Campaign;
use App\Models\Offer;
use App\Support\Crm\AttributionModel;
use App\Support\Crm\CampaignMeasures;
use App\Support\Offers\OfferPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CampaignController extends Controller
{
    public function index(): CampaignIndexResource
    {
        $this->authorize('viewAny', Campaign::class);
        $measures = CampaignMeasures::byCampaign();
        $campaigns = Campaign::query()->with(['offer', 'owner'])->orderBy('name')->orderBy('id')->get();

        return new CampaignIndexResource([
            'data' => $campaigns->map(fn (Campaign $campaign): array => $this->card($campaign, $measures[$campaign->id] ?? $this->emptyMeasures()))->all(),
            'meta' => [
                'notes' => [
                    'sends' => 'Marketing email is not built yet',
                ],
            ],
        ]);
    }

    public function offersWithoutCampaign(): CampaignOffersResource
    {
        $this->authorize('viewAny', Campaign::class);

        $covered = Campaign::query()->where('status', 'ACTIVE')->whereNotNull('offer_id')->pluck('offer_id');
        $offers = Offer::query()
            ->whereIn('status', [OfferStatus::Live, OfferStatus::Pending])
            ->when($covered->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $covered))
            ->orderBy('code')
            ->get()
            ->filter(fn (Offer $offer): bool => ! $offer->isDerivedExpired())
            ->values();

        return new CampaignOffersResource([
            'data' => $offers->map(fn (Offer $offer): array => $this->offer($offer))->all(),
        ]);
    }

    public function attributionModel(): AttributionModelResource
    {
        $this->authorize('viewAny', Campaign::class);

        return new AttributionModelResource([
            'data' => AttributionModel::rows(),
            'conflict' => AttributionModel::CONFLICT,
        ]);
    }

    public function store(StoreCampaignRequest $request, SaveCampaign $save): JsonResponse
    {
        $this->authorize('create', Campaign::class);
        $actor = $request->user();

        if ($actor === null) {
            throw new HttpException(403, 'You cannot manage campaigns.');
        }

        $campaign = $save->create($this->payload($request), $actor);

        return response()->json(['id' => $campaign->id, 'name' => $campaign->name, 'status' => $campaign->status->value], 201);
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign, SaveCampaign $save): JsonResponse
    {
        $this->authorize('update', $campaign);
        $actor = $request->user();

        if ($actor === null) {
            throw new HttpException(403, 'You cannot manage campaigns.');
        }

        $saved = $save->update($campaign, $this->payload($request, partial: true), $actor);

        return response()->json(['id' => $saved->id, 'media_spend' => $saved->media_spend]);
    }

    public function archive(Request $request, Campaign $campaign, ArchiveCampaign $archive): JsonResponse
    {
        $this->authorize('update', $campaign);
        $actor = $request->user();

        if ($actor === null) {
            throw new HttpException(403, 'You cannot manage campaigns.');
        }

        $saved = $archive->handle($campaign, $actor);

        return response()->json(['id' => $saved->id, 'status' => $saved->status->value]);
    }

    public function bookings(Request $request, Campaign $campaign): CampaignBookingPageResource
    {
        $this->authorize('view', $campaign);
        $page = CampaignMeasures::bookings($campaign, $request->integer('per_page', 25));

        return new CampaignBookingPageResource([
            'data' => collect($page->items())->map(function (Booking $booking): array {
                return [
                    'id' => $booking->id,
                    'reference' => $booking->reference ?? $booking->request_reference,
                    'check_in' => $booking->check_in->toDateString(),
                    'status' => $booking->status->value,
                    'charges_total' => (int) $booking->getAttribute('charges_total'),
                    'measures' => [
                        'redeemed' => (bool) $booking->getAttribute('counts_redeemed'),
                        'first_touch' => (bool) $booking->getAttribute('counts_first'),
                        'last_touch' => (bool) $booking->getAttribute('counts_last'),
                    ],
                ];
            })->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * @param  array{redeemed: int, revenue: int, first_count: int, first_revenue: int, last_count: int, last_revenue: int, trade: int, roas: string|null}  $measures
     * @return array<string, mixed>
     */
    private function card(Campaign $campaign, array $measures): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'audience' => $campaign->audience,
            'media_spend' => $campaign->media_spend,
            'status' => $campaign->status->value,
            'utm_campaign' => $campaign->utm_campaign,
            'owner' => $campaign->owner === null ? null : [
                'id' => $campaign->owner->id,
                'name' => $campaign->owner->name,
            ],
            'offer' => $campaign->offer === null ? null : $this->offer($campaign->offer),
            'redeemed' => $measures['redeemed'],
            'revenue' => $measures['revenue'],
            'attributed_first' => [
                'count' => $measures['first_count'],
                'revenue' => $measures['first_revenue'],
            ],
            'attributed_last' => [
                'count' => $measures['last_count'],
                'revenue' => $measures['last_revenue'],
            ],
            'trade' => $measures['trade'],
            'roas' => $measures['roas'],
            'sends' => null,
            'clicks' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'code' => $offer->code,
            'name' => $offer->name,
            'type' => $offer->type->value,
            'value_text' => OfferPresentation::benefit($offer),
            'channel' => $offer->channel->value,
            'status' => $offer->derivedStatus(),
            'booking_window' => [
                'from' => $this->day($offer->booking_from),
                'to' => $this->day($offer->booking_to),
            ],
            'travel_window' => [
                'from' => $this->day($offer->stay_from),
                'to' => $this->day($offer->stay_to),
            ],
        ];
    }

    private function day(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return null;
    }

    /**
     * @return array{redeemed: int, revenue: int, first_count: int, first_revenue: int, last_count: int, last_revenue: int, trade: int, roas: string|null}
     */
    private function emptyMeasures(): array
    {
        return [
            'redeemed' => 0,
            'revenue' => 0,
            'first_count' => 0,
            'first_revenue' => 0,
            'last_count' => 0,
            'last_revenue' => 0,
            'trade' => 0,
            'roas' => null,
        ];
    }

    /**
     * @return array{name: string, offer_id: int|null, utm_campaign: string|null, audience: string|null, media_spend: int}
     */
    private function payload(StoreCampaignRequest|UpdateCampaignRequest $request, bool $partial = false): array
    {
        $data = [];

        if (! $partial || $request->exists('name')) {
            $data['name'] = $request->string('name')->toString();
        }

        if (! $partial || $request->exists('offer_id')) {
            $data['offer_id'] = $request->filled('offer_id') ? $request->integer('offer_id') : null;
        }

        if (! $partial || $request->exists('utm_campaign')) {
            $utm = $request->input('utm_campaign');
            $data['utm_campaign'] = is_string($utm) && $utm !== '' ? $utm : null;
        }

        if (! $partial || $request->exists('audience')) {
            $audience = $request->input('audience');
            $data['audience'] = is_string($audience) && $audience !== '' ? $audience : null;
        }

        if (! $partial || $request->exists('media_spend')) {
            $data['media_spend'] = $request->integer('media_spend', 0);
        }

        return $data;
    }
}
