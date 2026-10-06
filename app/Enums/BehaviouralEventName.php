<?php

declare(strict_types=1);

namespace App\Enums;

enum BehaviouralEventName: string
{
    case SearchAvailability = 'search_availability';
    case ViewItinerary = 'view_itinerary';
    case SelectDeparture = 'select_departure';
    case ViewItineraryDetail = 'view_itinerary_detail';
    case ViewRouteMap = 'view_route_map';
    case BeginCheckout = 'begin_checkout';
    case BeginBookingRequest = 'begin_booking_request';
    case SelectPaymentPath = 'select_payment_path';
    case ApplyPromotion = 'apply_promotion';
    case RemovePromotion = 'remove_promotion';
    case PromoInvalid = 'promo_invalid';
    case BookingFormInvalid = 'booking_form_invalid';
    case SubmitBookingRequest = 'submit_booking_request';
    case AbandonCart = 'abandon_cart';
    case CharterInquirySubmit = 'charter_inquiry_submit';
    case ViewDeparture = 'view_departure';
    case PageView = 'page_view';
    case SearchPerformed = 'search_performed';
    case RoomTypeViewed = 'room_type_viewed';
    case IdentityStitched = 'identity.stitched';

    public function isClient(): bool
    {
        return $this !== self::IdentityStitched;
    }

    public function touchesInventory(): bool
    {
        return $this === self::BeginCheckout || $this === self::SubmitBookingRequest;
    }

    public function side(): string
    {
        return $this->touchesInventory() ? 'RMS + CRM' : 'CRM';
    }

    /**
     * @return list<string>
     */
    public static function acceptedFromClient(): array
    {
        $accepted = [];

        foreach (self::cases() as $case) {
            if ($case->isClient()) {
                $accepted[] = $case->value;
            }
        }

        return $accepted;
    }
}
