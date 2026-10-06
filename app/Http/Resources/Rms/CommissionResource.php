<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Enums\CommissionAccrualStatus;
use App\Models\Booking;
use App\Services\Config\CurrentConfig;
use App\Support\Commissions\Accrual;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class CommissionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     booking_id: int,
     *     reference: string|null,
     *     agency: array{id: int|null, reference: string|null, name: string|null},
     *     commission_pct: int|null,
     *     commission_amount: int,
     *     payable_date: string,
     *     status: CommissionAccrualStatus,
     *     payout: array{amount: int, paid_on: string, bank_reference: string}|null,
     *     check_in: string
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['agency', 'room.roomType', 'property', 'commissionPayout']);
        $rules = app(CurrentConfig::class)->businessRules();

        return [
            'booking_id' => $this->id,
            'reference' => $this->reference,
            'agency' => [
                'id' => $this->agency?->id,
                'reference' => $this->agency?->reference,
                'name' => $this->agency?->name,
            ],
            'commission_pct' => $this->commission_pct,
            'commission_amount' => $this->commissionAmount(),
            'payable_date' => Accrual::payableDate($this->resource, $rules)->toDateString(),
            'status' => Accrual::status($this->resource, $rules),
            'payout' => $this->commissionPayout?->toArrayForApi(),
            'check_in' => $this->stay()->checkIn()->toDateString(),
        ];
    }
}
