<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\AgencyStatus;
use App\Enums\CabinCategory;
use App\Enums\MainChannel;
use App\Enums\OfferType;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Offer;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\SoldOn;
use App\Support\History\History;
use Illuminate\Validation\ValidationException;

final class FreezeCommission
{
    public function __construct(private CurrentConfig $config) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{agency_id: int, commission_pct: int, commission_approved: bool, over_cap: bool, offer_codes: list<string>}|null
     */
    // TODO(Sprint 18): room type pricing (09 H8)
    public function resolve(array $data, ?Departure $departure, ?CabinCategory $category): ?array
    {
        $agencyId = $data['agency_id'] ?? null;

        if ($agencyId === null || $agencyId === '') {
            return null;
        }

        $channel = $data['main_channel'] instanceof MainChannel
            ? $data['main_channel']
            : MainChannel::from((string) $data['main_channel']);

        if (! $channel->isTrade()) {
            throw ValidationException::withMessages([
                'agency_id' => ['An agency can only be attached to a trade channel.'],
            ]);
        }

        $agency = Agency::query()->find((int) $agencyId);

        if (! $agency instanceof Agency) {
            throw ValidationException::withMessages([
                'agency_id' => ['The agency is not available.'],
            ]);
        }

        if ($agency->status !== AgencyStatus::Approved) {
            throw ValidationException::withMessages([
                'agency_id' => ['The agency must be approved before it can be sold against.'],
            ]);
        }

        $pct = isset($data['commission_pct']) && $data['commission_pct'] !== ''
            ? (int) $data['commission_pct']
            : $agency->commission_pct;
        $offerCodes = [];

        if ($departure instanceof Departure && $category instanceof CabinCategory) {
            $commOffers = Offer::applicableTo(
                $departure,
                $category,
                $channel->segment(),
                SoldOn::today(),
            )->filter(fn (Offer $offer): bool => $offer->type === OfferType::Commission);

            foreach ($commOffers as $offer) {
                $pct += (int) $offer->value;
                $offerCodes[] = $offer->code;
            }
        }

        $cap = $this->config->businessRules()->commission->capPct;
        $overCap = $pct > $cap;

        return [
            'agency_id' => $agency->id,
            'commission_pct' => $pct,
            'commission_approved' => ! $overCap,
            'over_cap' => $overCap,
            'offer_codes' => $offerCodes,
        ];
    }

    /**
     * @param  array{agency_id: int, commission_pct: int, commission_approved: bool, over_cap: bool, offer_codes: list<string>}  $commission
     */
    public function recordHold(Booking $booking, array $commission): void
    {
        if (! $commission['over_cap']) {
            return;
        }

        $cap = $this->config->businessRules()->commission->capPct;
        $pct = $commission['commission_pct'];
        $named = $commission['offer_codes'] === []
            ? ''
            : ' · '.implode(', ', $commission['offer_codes']);

        History::record($booking, 'booking.commission_held', after: [
            'what' => 'HELD — commission '.$pct.' % above '.$cap.' % cap'.$named.' · Director alert sent (FIN-005)',
            'commission_pct' => $pct,
            'cap_pct' => $cap,
            'commission_offers' => $commission['offer_codes'],
        ], system: true);
    }
}
