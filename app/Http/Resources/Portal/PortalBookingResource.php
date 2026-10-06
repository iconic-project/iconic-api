<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\RoomType;
use App\Support\Agencies\PortalPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class PortalBookingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     reference: string|null,
     *     check_in: string,
     *     check_out: string,
     *     departure_date: string,
     *     room_type: array{code: string, name: string}|null,
     *     status: BookingStatus,
     *     lead_guest: string,
     *     net_due: int,
     *     payment_state: string,
     *     open_payment_kinds: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        $stay = $this->resource->stay();
        $checkIn = $stay->checkIn()->toDateString();
        $type = $this->resource->getRelationValue('roomType');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'check_in' => $checkIn,
            'check_out' => $stay->checkOut()->toDateString(),
            'departure_date' => $checkIn,
            'room_type' => $type instanceof RoomType ? [
                'code' => $type->code,
                'name' => $type->name,
            ] : null,
            'status' => $this->bookingStatus(),
            'lead_guest' => PortalPreview::leadGuestName($this->resource),
            'net_due' => PortalPreview::netDue($this->resource),
            'payment_state' => $this->resource->paymentStateWords(),
            'open_payment_kinds' => $this->resource->openPaymentKinds(),
        ];
    }

    private function bookingStatus(): BookingStatus
    {
        return $this->status;
    }
}
