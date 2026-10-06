<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use App\Enums\DeliveryKind;

final class AlertKeys
{
    public static function overdue(int $bookingId, string $dueDate): string
    {
        return 'overdue:'.$bookingId.':'.$dueDate;
    }

    public static function cap(int $bookingId): string
    {
        return 'cap:'.$bookingId;
    }

    public static function wire(int $paymentId): string
    {
        return 'wire:'.$paymentId;
    }

    public static function sla(string $taskKey): string
    {
        return 'sla:'.$taskKey;
    }

    public static function delivery(?int $documentId, int $bookingId, DeliveryKind $kind): string
    {
        if ($documentId !== null) {
            return 'delivery:'.$documentId;
        }

        return 'delivery:'.$bookingId.':'.$kind->value;
    }

    public static function confirmedOnCheckIn(int $bookingId): string
    {
        return 'confirmed-on-check-in:'.$bookingId;
    }

    public static function arrivalNotCheckedIn(int $bookingId): string
    {
        return 'arrival-not-checked-in:'.$bookingId;
    }

    public static function inHousePastCheckOut(int $bookingId): string
    {
        return 'in-house-past-check-out:'.$bookingId;
    }

    public static function checkOutStillOpen(int $bookingId): string
    {
        return 'check-out-still-open:'.$bookingId;
    }

    public static function ledgerStripe(string $paymentIntent): string
    {
        return 'ledger:stripe:'.$paymentIntent;
    }

    public static function ledgerPaid(int $bookingId): string
    {
        return 'ledger:paid:'.$bookingId;
    }

    public static function ledgerRefund(string $chargeId): string
    {
        return 'ledger:refund:'.$chargeId;
    }

    public static function leakTrade(int $bookingId): string
    {
        return 'leak:trade:'.$bookingId;
    }

    public static function leakAdvisor(int $bookingId): string
    {
        return 'leak:advisor:'.$bookingId;
    }

    public static function leakCap(int $bookingId): string
    {
        return 'leak:cap:'.$bookingId;
    }

    public static function leakTerms(int $agencyId): string
    {
        return 'leak:terms:'.$agencyId;
    }

    public static function occupancyRun(int $propertyId, string $firstNight, string $lastNight): string
    {
        return 'occupancy:'.$propertyId.':'.$firstNight.':'.$lastNight;
    }

    public static function manifestData(int $subjectId): string
    {
        return 'manifest-data:'.$subjectId;
    }

    public static function npsReply(int $guestResponseId): string
    {
        return 'nps-reply:'.$guestResponseId;
    }
}
