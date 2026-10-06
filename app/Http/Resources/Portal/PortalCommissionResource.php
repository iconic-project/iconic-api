<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Enums\CommissionAccrualStatus;
use App\Models\Booking;
use App\Models\CommissionPayout;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Commissions\Accrual;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class PortalCommissionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     reference: string|null,
     *     check_in: string,
     *     check_out: string,
     *     room_type: array{code: string, name: string}|null,
     *     rate: int|null,
     *     commission_amount: int,
     *     payable_date: string,
     *     status: CommissionAccrualStatus,
     *     payout: array{paid_on: string, reference: string|null}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $rules = app(CurrentConfig::class)->businessRules();
        $stay = $this->resource->stay();
        $type = $this->resource->getRelationValue('roomType');

        return [
            'reference' => $this->reference,
            'check_in' => $stay->checkIn()->toDateString(),
            'check_out' => $stay->checkOut()->toDateString(),
            'room_type' => $type instanceof RoomType ? [
                'code' => $type->code,
                'name' => $type->name,
            ] : null,
            'rate' => $this->commission_pct,
            'commission_amount' => $this->resource->commissionAmount(),
            'payable_date' => Accrual::payableDate($this->resource, $rules)->toDateString(),
            'status' => Accrual::status($this->resource, $rules),
            'payout' => $this->commissionPayout instanceof CommissionPayout ? [
                'paid_on' => $this->commissionPayout->paid_on->toDateString(),
                'reference' => $this->reference,
            ] : null,
        ];
    }
}
