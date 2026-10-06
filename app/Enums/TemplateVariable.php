<?php

declare(strict_types=1);

namespace App\Enums;

enum TemplateVariable: string
{
    case FirstName = 'first_name';
    case BookingReference = 'booking_reference';
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
    case Nights = 'nights';
    case RoomType = 'room_type';
    case PropertyName = 'property_name';
    case CheckInTime = 'check_in_time';
    case CheckOutTime = 'check_out_time';
    case BalanceDueDate = 'balance_due_date';
    case DepositLink = 'deposit_link';
    case CompleteLink = 'complete_link';
    case UnsubscribeLink = 'unsubscribe_link';

    public function isLink(): bool
    {
        return match ($this) {
            self::DepositLink, self::CompleteLink, self::UnsubscribeLink => true,
            default => false,
        };
    }
}
