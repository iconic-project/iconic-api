<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OfferChannel;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ReferenceType;
use App\Models\Offer;
use App\Models\User;
use App\Services\References\ReferenceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoOffersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DB::transaction(function (): void {
            $director = $this->carolina();
            $approvedAt = now();

            foreach ($this->rows() as $row) {
                Offer::query()->firstOrCreate(
                    ['reference' => $row['reference']],
                    [
                        ...$row,
                        'approved_by' => $director?->id,
                        'approved_at' => $approvedAt,
                        'approval_reason' => 'Sprint 8 placeholder seed',
                        'first_live_at' => $approvedAt,
                        'needs_reapproval' => false,
                    ],
                );
            }

            app(ReferenceService::class)->ensureAtLeast(ReferenceType::Offer, 9);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            [
                'reference' => 'OF-001',
                'code' => 'OPENING-27',
                'name' => 'Opening season credit',
                'type' => OfferType::Credit,
                'value' => 500,
                'value_text' => null,
                'channel' => OfferChannel::D2C,
                'partner' => null,
                'booking_from' => null,
                'booking_to' => null,
                'stay_from' => '2027-11-01',
                'stay_to' => '2027-12-31',
                'combinable' => false,
                'is_promo_code' => false,
                'badge' => 'OPENING OFFER',
                'show_on_card' => true,
                'show_on_calendar' => true,
                'price_line' => 'Opening season credit — on-board ancillaries',
                'terms' => 'USD 500 ancillary credit per Suite on non-festive departures in November and December 2027. Not combinable with other offers.',
                'status' => OfferStatus::Live,
            ],
            [
                'reference' => 'OF-002',
                'code' => 'VIRTUOSO-EARLY',
                'name' => 'Virtuoso early booking',
                'type' => OfferType::Commission,
                'value' => 2,
                'value_text' => null,
                'channel' => OfferChannel::B2B,
                'partner' => 'Virtuoso network',
                'booking_from' => '2027-01-01',
                'booking_to' => '2027-03-31',
                'stay_from' => null,
                'stay_to' => null,
                'combinable' => false,
                'is_promo_code' => false,
                'badge' => null,
                'show_on_card' => false,
                'show_on_calendar' => false,
                'price_line' => null,
                'terms' => 'Extra 2% partner commission on bookings made in Q1 2027.',
                'status' => OfferStatus::Live,
            ],
            [
                'reference' => 'OF-003',
                'code' => 'ICONIC10',
                'name' => 'Iconic welcome',
                'type' => OfferType::Percent,
                'value' => 10,
                'value_text' => null,
                'channel' => OfferChannel::D2C,
                'partner' => null,
                'booking_from' => null,
                'booking_to' => null,
                'stay_from' => null,
                'stay_to' => null,
                'combinable' => true,
                'is_promo_code' => true,
                'badge' => null,
                'show_on_card' => false,
                'show_on_calendar' => false,
                'price_line' => 'Iconic welcome −10%',
                'terms' => 'Placeholder promo code. Not for production.',
                'status' => OfferStatus::Live,
            ],
            [
                'reference' => 'OF-004',
                'code' => 'ADVISOR5',
                'name' => 'Travel advisor',
                'type' => OfferType::Percent,
                'value' => 5,
                'value_text' => null,
                'channel' => OfferChannel::D2C,
                'partner' => null,
                'booking_from' => null,
                'booking_to' => null,
                'stay_from' => null,
                'stay_to' => null,
                'combinable' => true,
                'is_promo_code' => true,
                'badge' => null,
                'show_on_card' => false,
                'show_on_calendar' => false,
                'price_line' => 'Travel advisor −5%',
                'terms' => 'Placeholder promo code. Not for production.',
                'status' => OfferStatus::Live,
            ],
            [
                'reference' => 'OF-005',
                'code' => 'EARLY500',
                'name' => 'Early booking credit',
                'type' => OfferType::Amount,
                'value' => 500,
                'value_text' => null,
                'channel' => OfferChannel::D2C,
                'partner' => null,
                'booking_from' => null,
                'booking_to' => null,
                'stay_from' => null,
                'stay_to' => null,
                'combinable' => true,
                'is_promo_code' => true,
                'badge' => null,
                'show_on_card' => false,
                'show_on_calendar' => false,
                'price_line' => 'Early booking −USD 500 pp',
                'terms' => 'Placeholder promo code. Not valid on festive departures. Not for production.',
                'status' => OfferStatus::Live,
            ],
            [
                'reference' => 'OF-006',
                'code' => 'SHOULDER15',
                'name' => 'Shoulder season',
                'type' => OfferType::Percent,
                'value' => 15,
                'value_text' => null,
                'channel' => OfferChannel::D2C,
                'partner' => null,
                'booking_from' => null,
                'booking_to' => null,
                'stay_from' => '2027-11-14',
                'stay_to' => '2027-11-14',
                'combinable' => true,
                'is_promo_code' => false,
                'badge' => 'SHOULDER SEASON',
                'show_on_card' => true,
                'show_on_calendar' => true,
                'price_line' => 'Shoulder season −15%',
                'terms' => 'Placeholder departure offer. Not for production.',
                'status' => OfferStatus::Live,
            ],
            [
                'reference' => 'OF-007',
                'code' => 'EARLY10-1205',
                'name' => 'Early booking',
                'type' => OfferType::Percent,
                'value' => 10,
                'value_text' => null,
                'channel' => OfferChannel::D2C,
                'partner' => null,
                'booking_from' => null,
                'booking_to' => null,
                'stay_from' => '2027-12-05',
                'stay_to' => '2027-12-05',
                'combinable' => true,
                'is_promo_code' => false,
                'badge' => 'EARLY BOOKING',
                'show_on_card' => true,
                'show_on_calendar' => true,
                'price_line' => 'Early booking −10%',
                'terms' => 'Placeholder departure offer. Not for production.',
                'status' => OfferStatus::Live,
            ],
            [
                'reference' => 'OF-008',
                'code' => 'LAST12',
                'name' => 'Last cabins',
                'type' => OfferType::Percent,
                'value' => 12,
                'value_text' => null,
                'channel' => OfferChannel::D2C,
                'partner' => null,
                'booking_from' => null,
                'booking_to' => null,
                'stay_from' => '2028-01-02',
                'stay_to' => '2028-01-02',
                'combinable' => true,
                'is_promo_code' => false,
                'badge' => 'LAST CABINS',
                'show_on_card' => true,
                'show_on_calendar' => true,
                'price_line' => 'Last cabins −12%',
                'terms' => 'Placeholder departure offer. Not for production.',
                'status' => OfferStatus::Live,
            ],
            [
                'reference' => 'OF-009',
                'code' => 'EARLY10-0116',
                'name' => 'Early booking',
                'type' => OfferType::Percent,
                'value' => 10,
                'value_text' => null,
                'channel' => OfferChannel::D2C,
                'partner' => null,
                'booking_from' => null,
                'booking_to' => null,
                'stay_from' => '2028-01-16',
                'stay_to' => '2028-01-16',
                'combinable' => true,
                'is_promo_code' => false,
                'badge' => 'EARLY BOOKING',
                'show_on_card' => true,
                'show_on_calendar' => true,
                'price_line' => 'Early booking −10%',
                'terms' => 'Placeholder departure offer. Not for production.',
                'status' => OfferStatus::Live,
            ],
        ];
    }

    private function carolina(): ?User
    {
        return User::query()
            ->get()
            ->first(fn (User $user): bool => str_starts_with($user->name, 'Carolina'));
    }
}
