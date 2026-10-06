<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     window: array{from: string, to: string},
 *     scope: array{property: int|null, channel: string|null, agency: int|null},
 *     metrics: array{
 *         occupancy: array{sold_berths: int, sellable_berths: int, occupancy: string|null, stays: list<array{id: int, date: string, property_code: string, sold_berths: int, sellable_berths: int, occupancy: string|null}>, definition: array{sentence: string, filters_on: string, excludes: string}},
 *         revpab: array{stay_revenue: int, sellable_berths: int, revpab: int|null, definition: array{sentence: string, filters_on: string, excludes: string}},
 *         adr: array{stay_revenue: int, berths_sold: int, adr: int|null, definition: array{sentence: string, filters_on: string, excludes: string}},
 *         lead_time: array{average_days: string|null, median_days: string|null, bookings: int, definition: array{sentence: string, filters_on: string, excludes: string}},
 *         channel_mix: array{rows: list<array{channel: string, group: string, bookings: int, revenue: int}>, definition: array{sentence: string, filters_on: string, excludes: string}},
 *         nationality_mix: array{rows: list<array{country_code: string, guests: int}>, unknown: int, definition: array{sentence: string, filters_on: string, excludes: string}},
 *         nps: array{average_score: string|null, responses: int, promoters: int, passives: int, detractors: int, definition: array{sentence: string, filters_on: string, excludes: string}},
 *         commissions: array{blocked: int, earned: int, payable: int, paid: int, definition: array{sentence: string, filters_on: string, excludes: string}},
 *         cash: array{collected: int, pending: int, overdue: int, deposit_share_pct: int|null, definition: array{sentence: string, filters_on: string, excludes: string}}
 *     }
 * } $resource
 */
#[SchemaName('MetricsResource')]
class MetricsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     window: array{from: string, to: string},
     *     scope: array{property: int|null, channel: string|null, agency: int|null},
     *     metrics: array{
     *         occupancy: array{sold_berths: int, sellable_berths: int, occupancy: string|null, stays: list<array{id: int, date: string, property_code: string, sold_berths: int, sellable_berths: int, occupancy: string|null}>, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         revpab: array{stay_revenue: int, sellable_berths: int, revpab: int|null, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         adr: array{stay_revenue: int, berths_sold: int, adr: int|null, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         lead_time: array{average_days: string|null, median_days: string|null, bookings: int, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         channel_mix: array{rows: list<array{channel: string, group: string, bookings: int, revenue: int}>, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         nationality_mix: array{rows: list<array{country_code: string, guests: int}>, unknown: int, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         nps: array{average_score: string|null, responses: int, promoters: int, passives: int, detractors: int, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         commissions: array{blocked: int, earned: int, payable: int, paid: int, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         cash: array{collected: int, pending: int, overdue: int, deposit_share_pct: int|null, definition: array{sentence: string, filters_on: string, excludes: string}}
     *     }
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
