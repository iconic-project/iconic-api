<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Bookings\Transitions;

enum BookingStatus: string
{
    case Requested = 'REQUESTED';
    case PendingPayment = 'PENDING_PAYMENT';
    case Confirmed = 'CONFIRMED';
    case FullyPaid = 'FULLY_PAID';
    case InHouse = 'IN_HOUSE';
    case CheckedOut = 'CHECKED_OUT';
    case NoShow = 'NO_SHOW';
    case Overdue = 'OVERDUE';
    case OnHoldAgency = 'ON_HOLD_AGENCY';
    case Waitlisted = 'WAITLISTED';
    case Released = 'RELEASED';
    case Cancelled = 'CANCELLED';
    case CancelledPostpaid = 'CANCELLED_POSTPAID';

    /**
     * Whether this status occupies rooms (G9). Task 04 reuses this.
     * RELEASED / CANCELLED / CANCELLED_POSTPAID free the room.
     */
    public function holdsInventory(): bool
    {
        return ! in_array($this, [
            self::Released,
            self::Cancelled,
            self::CancelledPostpaid,
        ], true);
    }

    public function isConfirmedOrLater(): bool
    {
        return in_array($this, [
            self::Confirmed,
            self::FullyPaid,
            self::InHouse,
            self::CheckedOut,
            self::NoShow,
            self::Overdue,
        ], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return Transitions::targets($this);
    }
}
