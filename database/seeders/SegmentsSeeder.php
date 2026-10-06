<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SegmentKind;
use App\Models\Segment;
use App\Support\Crm\SegmentVocabulary;
use App\Support\Crm\Suppression;
use Illuminate\Database\Seeder;

class SegmentsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            SegmentVocabulary::assertValid($definition['conditions']);

            Segment::query()->updateOrCreate(
                ['key' => $definition['key']],
                $definition,
            );
        }
    }

    /**
     * @return list<array{key: string, name: string, sentence: string, conditions: array<string, mixed>, dimensions: list<array{axis: string, label: string}>, kind: SegmentKind, system: bool, active: bool, feeds: string}>
     */
    private function definitions(): array
    {
        return [
            $this->row(
                'warm_dreamers',
                'Warm dreamers',
                'Viewed at least 2 itineraries and has not submitted a booking request.',
                [
                    'match' => 'all',
                    'items' => [
                        ['field' => 'event_count', 'operator' => 'gte', 'value' => 2, 'event' => 'view_itinerary', 'within_days' => null],
                        ['field' => 'booking_count', 'operator' => 'eq', 'value' => 0, 'within_days' => null],
                    ],
                ],
                [
                    ['axis' => 'BEHAVIOUR', 'label' => 'engine: view_itinerary ×2'],
                    ['axis' => 'INTEREST', 'label' => 'wildlife / photography'],
                    ['axis' => 'LOCATION', 'label' => 'US · UK'],
                ],
                SegmentKind::Marketing,
                'Nurture to Request · engine personalisation',
            ),
            $this->row(
                'abandoned_checkout',
                'Abandoned checkout',
                'Started checkout in the last 14 days and has not submitted a booking request.',
                [
                    'match' => 'all',
                    'items' => [
                        ['field' => 'event_count', 'operator' => 'gte', 'value' => 1, 'event' => 'begin_checkout', 'within_days' => 14],
                        ['field' => 'booking_count', 'operator' => 'eq', 'value' => 0, 'within_days' => 14],
                    ],
                ],
                [
                    ['axis' => 'BEHAVIOUR', 'label' => 'engine: abandon_checkout'],
                    ['axis' => 'PROFILE', 'label' => 'est. value > USD 25k'],
                ],
                SegmentKind::Marketing,
                'Cart-recovery emails 1–3 · high-intent audience',
            ),
            $this->row(
                'holding_not_paid',
                'Holding — not paid',
                'Has a booking in REQUESTED whose hold has not expired.',
                [
                    'match' => 'all',
                    'items' => [
                        ['field' => 'active_hold', 'operator' => 'eq', 'value' => true],
                    ],
                ],
                [
                    ['axis' => 'BEHAVIOUR', 'label' => 'rms: booking.created'],
                    ['axis' => 'BEHAVIOUR', 'label' => 'link not clicked'],
                ],
                SegmentKind::Operational,
                'Request to Deposit · sales task at 24 h silence',
            ),
            $this->row(
                'festive_prospects',
                'Festive prospects',
                'Viewed a departure.',
                [
                    'match' => 'all',
                    'items' => [
                        ['field' => 'event_count', 'operator' => 'gte', 'value' => 1, 'event' => 'view_departure', 'within_days' => null],
                    ],
                ],
                [
                    ['axis' => 'INTEREST', 'label' => 'festive weeks'],
                    ['axis' => 'PROMOTION', 'label' => 'OPENING-27 exposed'],
                    ['axis' => 'LOCATION', 'label' => 'US East'],
                ],
                SegmentKind::Marketing,
                'Festive branch — scarcity only when the RMS feed says cabins are genuinely low',
            ),
            $this->row(
                'families_6_17',
                'Families 6–17',
                'A guest on one of their bookings is aged 6 to 17 at arrival.',
                [
                    'match' => 'all',
                    'items' => [
                        ['field' => 'guest_age', 'operator' => 'between', 'value' => [6, 17]],
                    ],
                ],
                [
                    ['axis' => 'PROFILE', 'label' => 'family party'],
                    ['axis' => 'INTEREST', 'label' => 'child-friendly'],
                    ['axis' => 'BEHAVIOUR', 'label' => 'child-rate FAQ read'],
                ],
                SegmentKind::Marketing,
                'Family-angle nurture · child-discount messaging',
            ),
            $this->row(
                'past_guests_high_ltv',
                'Past guests — HIGH LTV',
                'Past guest whose lifetime value is in the HIGH band.',
                [
                    'match' => 'all',
                    'items' => [
                        ['field' => 'lifecycle', 'operator' => 'eq', 'value' => 'PAST_GUEST'],
                        ['field' => 'ltv_band', 'operator' => 'eq', 'value' => 'HIGH'],
                    ],
                ],
                [
                    ['axis' => 'PROFILE', 'label' => 'HIGH LTV'],
                    ['axis' => 'BEHAVIOUR', 'label' => 'NPS ≥ 8'],
                ],
                SegmentKind::Marketing,
                'Re-engagement (personal outreach) · referral programme',
            ),
            $this->row(
                'advisors_non_producing',
                'Advisors — non-producing',
                'Approved travel advisor with no booking in the last 90 days.',
                [
                    'match' => 'all',
                    'items' => [
                        ['field' => 'lifecycle', 'operator' => 'eq', 'value' => 'AGENT'],
                        ['field' => 'booking_count', 'operator' => 'eq', 'value' => 0, 'within_days' => 90],
                    ],
                ],
                [
                    ['axis' => 'PROFILE', 'label' => 'travel advisor'],
                    ['axis' => 'BEHAVIOUR', 'label' => 'no bookings 90 d'],
                ],
                SegmentKind::Operational,
                'B2B Partner Activation · quarterly review list',
            ),
            $this->row(
                'dach_luxury',
                'DACH luxury',
                'Country is Germany, Austria or Switzerland.',
                [
                    'match' => 'all',
                    'items' => [
                        ['field' => 'country', 'operator' => 'in', 'value' => ['DE', 'AT', 'CH']],
                    ],
                ],
                [
                    ['axis' => 'LOCATION', 'label' => 'DACH'],
                    ['axis' => 'PROFILE', 'label' => 'luxury traveller'],
                    ['axis' => 'INTEREST', 'label' => 'expedition'],
                ],
                SegmentKind::Marketing,
                'Geo-nurture branch — all sends in English',
            ),
            $this->row(
                'suppressed',
                'Suppressed',
                'Marketing consent withdrawn or never given, an erasure, or a hard bounce.',
                Suppression::conditions(),
                [
                    ['axis' => 'BEHAVIOUR', 'label' => 'suppression'],
                    ['axis' => 'PROFILE', 'label' => 'all types'],
                ],
                SegmentKind::Operational,
                'Excluded from every marketing send — transactional only',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @param  list<array{axis: string, label: string}>  $dimensions
     * @return array{key: string, name: string, sentence: string, conditions: array<string, mixed>, dimensions: list<array{axis: string, label: string}>, kind: SegmentKind, system: bool, active: bool, feeds: string}
     */
    private function row(
        string $key,
        string $name,
        string $sentence,
        array $conditions,
        array $dimensions,
        SegmentKind $kind,
        string $feeds,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'sentence' => $sentence,
            'conditions' => $conditions,
            'dimensions' => $dimensions,
            'kind' => $kind,
            'system' => true,
            'active' => true,
            'feeds' => $feeds,
        ];
    }
}
