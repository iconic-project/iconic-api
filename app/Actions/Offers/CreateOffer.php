<?php

declare(strict_types=1);

namespace App\Actions\Offers;

use App\Actions\Action;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ReferenceType;
use App\Models\Offer;
use App\Models\User;
use App\Services\Engine\EngineFeedVersion;
use App\Services\References\ReferenceService;
use App\Support\History\History;
use App\Support\Offers\OfferGuardrails;

final class CreateOffer extends Action
{
    public function __construct(
        private readonly ReferenceService $references,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): Offer
    {
        $offer = $this->transaction(function () use ($data, $actor): Offer {
            $data = OfferGuardrails::normalize($data);
            $asDraft = (bool) ($data['as_draft'] ?? false);
            unset($data['as_draft']);

            $type = $data['type'] instanceof OfferType
                ? $data['type']
                : OfferType::from((string) $data['type']);

            $status = OfferStatus::Draft;
            $firstLiveAt = null;

            if (! $asDraft) {
                if ($type->isPriceAffecting()) {
                    $status = OfferStatus::Pending;
                } else {
                    $status = OfferStatus::Live;
                    $firstLiveAt = now();
                }
            }

            $offer = Offer::query()->create([
                'reference' => $this->references->next(ReferenceType::Offer),
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $type,
                'value' => $data['value'] ?? null,
                'value_text' => $data['value_text'] ?? null,
                'channel' => $data['channel'],
                'partner' => $data['partner'] ?? null,
                'booking_from' => $data['booking_from'] ?? null,
                'booking_to' => $data['booking_to'] ?? null,
                'stay_from' => $data['stay_from'] ?? null,
                'stay_to' => $data['stay_to'] ?? null,
                'min_nights' => $data['min_nights'] ?? null,
                'applies_to_room_types' => $data['applies_to_room_types'] ?? null,
                'applies_to_rate_plans' => $data['applies_to_rate_plans'] ?? null,
                'combinable' => (bool) ($data['combinable'] ?? false),
                'is_promo_code' => (bool) ($data['is_promo_code'] ?? false),
                'badge' => $data['badge'] ?? null,
                'show_on_card' => (bool) ($data['show_on_card'] ?? false),
                'show_on_calendar' => (bool) ($data['show_on_calendar'] ?? false),
                'price_line' => $data['price_line'] ?? null,
                'terms' => $data['terms'] ?? null,
                'status' => $status,
                'first_live_at' => $firstLiveAt,
            ]);

            History::record($offer, 'offer.created', after: [
                'reference' => $offer->reference,
                'code' => $offer->code,
                'name' => $offer->name,
                'type' => $offer->type->value,
                'status' => $offer->status->value,
            ], actor: $actor);

            return $offer->fresh(['approvedBy']) ?? $offer;
        });

        if ($offer->status === OfferStatus::Live && $offer->enginePlacement() !== 'not_public') {
            EngineFeedVersion::bump();
        }

        return $offer;
    }
}
