<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\DeliveryKind;
use App\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class DeliveryKey
{
    public static function forPortalInvite(int $agencyUserId, Carbon $sentAt): string
    {
        return 'portal-invite:'.$agencyUserId.':'.$sentAt->format('Y-m-d\TH:i:s.u');
    }

    public static function forDocument(Document $document): string
    {
        $kind = DeliveryKind::fromDocument($document->kind);

        if ($kind === DeliveryKind::Receipt) {
            return 'receipt:'.(int) $document->payment_id;
        }

        return strtolower($kind->value).':'.$document->id;
    }

    public static function forReminder(int $bookingId, string $dueDate, int $days): string
    {
        return 'reminder:'.$bookingId.':'.$dueDate.':'.$days;
    }

    public static function forPreArrival(int $bookingId, string $checkIn): string
    {
        return 'pre-arrival:'.$bookingId.':'.$checkIn;
    }

    public static function forPretrip(int $bookingId, string $departureDate): string
    {
        return 'pretrip:'.$bookingId.':'.$departureDate;
    }

    public static function forVoucher(int $bookingId, string $departureDate): string
    {
        return 'voucher:'.$bookingId.':'.$departureDate;
    }

    public static function forPaymentLink(int $linkId): string
    {
        return 'payment_link:'.$linkId;
    }

    public static function resendDocument(int $documentId): string
    {
        return 'resend:'.$documentId.':'.Str::uuid()->toString();
    }

    public static function resendReminder(int $bookingId, string $dueDate, int $days): string
    {
        return 'resend:reminder:'.$bookingId.':'.$dueDate.':'.$days.':'.Str::uuid()->toString();
    }

    public static function resendPaymentLink(int $linkId): string
    {
        return 'resend:payment_link:'.$linkId.':'.Str::uuid()->toString();
    }

    public static function blocked(string $key): string
    {
        return $key.':blocked';
    }
}
