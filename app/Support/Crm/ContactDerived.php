<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\AgencyStatus;
use App\Enums\BookingStatus;
use App\Enums\ConsentPurpose;
use App\Enums\ContactLifecycle;
use App\Enums\ContactSegment;
use App\Enums\ContactType;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Contact;
use App\Support\BusinessTime;
use App\Support\Config\Documents\CrmRules;
use InvalidArgumentException;

final class ContactDerived
{
    /**
     * @return list<string>
     */
    public static function soldStatuses(): array
    {
        return [
            BookingStatus::Confirmed->value,
            BookingStatus::FullyPaid->value,
            BookingStatus::InHouse->value,
            BookingStatus::CheckedOut->value,
            BookingStatus::Overdue->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function sqlStatuses(): array
    {
        return [
            BookingStatus::Requested->value,
            BookingStatus::PendingPayment->value,
            BookingStatus::OnHoldAgency->value,
        ];
    }

    /**
     * Check-out of the latest sold stay that has already started.
     */
    public static function lastStayCheckOutSql(?string $today = null): string
    {
        $today = self::calendarDay($today);

        return '(
            SELECT bookings.check_out
            FROM bookings
            WHERE '.self::soldWhere().'
              AND bookings.check_in <= \''.$today.'\'
            ORDER BY bookings.check_in DESC, bookings.id DESC
            LIMIT 1
        )';
    }

    /**
     * Check-in of the next sold stay that has not started.
     */
    public static function nextStayCheckInSql(?string $today = null): string
    {
        $today = self::calendarDay($today);

        return '(
            SELECT MIN(bookings.check_in)
            FROM bookings
            WHERE '.self::soldWhere().'
              AND bookings.check_in > \''.$today.'\'
        )';
    }

    public static function staysCountSql(): string
    {
        return '(SELECT COUNT(*) FROM bookings WHERE '.self::soldWhere().')';
    }

    public static function nightsCountSql(): string
    {
        return '(SELECT COALESCE(SUM(bookings.nights), 0) FROM bookings WHERE '.self::soldWhere().')';
    }

    /**
     * Room type of the same stay as lastStayCheckOutSql().
     */
    public static function lastRoomTypeSql(?string $today = null): string
    {
        $today = self::calendarDay($today);

        return '(
            SELECT room_types.name
            FROM bookings
            INNER JOIN room_types ON room_types.id = bookings.room_type_id
            WHERE '.self::soldWhere().'
              AND bookings.check_in <= \''.$today.'\'
            ORDER BY bookings.check_in DESC, bookings.id DESC
            LIMIT 1
        )';
    }

    public static function lifetimeValueSql(): string
    {
        $statuses = self::inList(self::soldStatuses());

        return 'COALESCE((
            SELECT SUM('.Booking::chargesTotalSql().')
            FROM bookings
            WHERE bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
              AND bookings.status IN ('.$statuses.')
        ), 0)';
    }

    public static function segmentSql(CrmRules $crm): string
    {
        $ltv = self::lifetimeValueSql();

        return 'CASE
            WHEN ('.$ltv.') > '.$crm->segmentHighLtv.' THEN \''.ContactSegment::High->value.'\'
            WHEN ('.$ltv.') >= '.$crm->segmentMidLtv.' THEN \''.ContactSegment::Mid->value.'\'
            ELSE \''.ContactSegment::New->value.'\'
        END';
    }

    public static function lifecycleSql(?string $today = null): string
    {
        $today = self::calendarDay($today);
        $sold = self::inList(self::soldStatuses());
        $sql = self::inList(self::sqlStatuses());
        $inStay = self::inList([
            BookingStatus::Confirmed->value,
            BookingStatus::FullyPaid->value,
            BookingStatus::Overdue->value,
        ]);
        $owned = 'bookings.contact_id = contacts.id AND bookings.deleted_at IS NULL';

        return 'CASE
            WHEN contacts.type = \''.ContactType::TravelAgent->value.'\'
              AND EXISTS (
                SELECT 1 FROM agencies
                WHERE LOWER(agencies.email) = contacts.email
                  AND agencies.status = \''.AgencyStatus::Approved->value.'\'
              ) THEN \''.ContactLifecycle::Agent->value.'\'
            WHEN EXISTS (
                SELECT 1 FROM bookings
                WHERE '.$owned.'
                  AND bookings.status = \''.BookingStatus::InHouse->value.'\'
            ) OR EXISTS (
                SELECT 1 FROM bookings
                WHERE '.$owned.'
                  AND bookings.status IN ('.$inStay.')
                  AND bookings.check_in <= \''.$today.'\'
                  AND bookings.check_out >= \''.$today.'\'
            ) THEN \''.ContactLifecycle::Guest->value.'\'
            WHEN EXISTS (
                SELECT 1 FROM bookings
                WHERE '.$owned.'
                  AND bookings.status IN ('.$sold.')
                  AND bookings.check_in > \''.$today.'\'
            ) THEN \''.ContactLifecycle::Booked->value.'\'
            WHEN EXISTS (
                SELECT 1 FROM bookings
                WHERE '.$owned.'
                  AND bookings.status IN ('.$sql.')
            ) THEN \''.ContactLifecycle::Sql->value.'\'
            WHEN EXISTS (
                SELECT 1 FROM bookings
                WHERE '.$owned.'
                  AND bookings.status = \''.BookingStatus::CheckedOut->value.'\'
            ) OR EXISTS (
                SELECT 1 FROM bookings
                WHERE '.$owned.'
                  AND bookings.status IN ('.$sold.')
                  AND bookings.check_out < \''.$today.'\'
            ) THEN \''.ContactLifecycle::PastGuest->value.'\'
            WHEN (
                contacts.engine_identified_at IS NOT NULL
                OR ('.self::marketingConsentSql().') = 1
            ) THEN \''.ContactLifecycle::Mql->value.'\'
            ELSE \''.ContactLifecycle::Prospect->value.'\'
        END';
    }

    public static function npsSql(): string
    {
        return '(
            SELECT guest_responses.score
            FROM guest_responses
            INNER JOIN bookings ON bookings.id = guest_responses.booking_id
            INNER JOIN guests ON guests.id = guest_responses.guest_id
            WHERE bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
              AND contacts.email IS NOT NULL
              AND guests.email IS NOT NULL
              AND LOWER(TRIM(guests.email)) = contacts.email
            ORDER BY guest_responses.responded_at DESC, guest_responses.id DESC
            LIMIT 1
        )';
    }

    public static function marketingConsentSql(): string
    {
        return 'COALESCE((
            SELECT CASE WHEN contact_consents.granted = 1 THEN 1 ELSE 0 END
            FROM contact_consents
            WHERE contact_consents.contact_id = contacts.id
              AND contact_consents.purpose = \''.ConsentPurpose::Marketing->value.'\'
            ORDER BY contact_consents.captured_at DESC, contact_consents.id DESC
            LIMIT 1
        ), 0)';
    }

    public static function firstBookingColumnSql(string $column): string
    {
        if (! in_array($column, ['main_channel', 'channel_of_origin'], true)) {
            throw new InvalidArgumentException('First-booking column must be main_channel or channel_of_origin.');
        }

        return '(
            SELECT bookings.'.$column.'
            FROM bookings
            WHERE bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
            ORDER BY bookings.id ASC
            LIMIT 1
        )';
    }

    /**
     * CRM contact whose email matches the agency. Same match the partner journey enrols.
     * A missing contact is left missing.
     */
    public static function contactForAgency(Agency $agency): ?Contact
    {
        $email = Contact::normalizeEmail($agency->email);

        if ($email === null) {
            return null;
        }

        return Contact::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    private static function soldWhere(): string
    {
        return 'bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
              AND bookings.status IN ('.self::inList(self::soldStatuses()).')';
    }

    private static function calendarDay(?string $today): string
    {
        $today ??= BusinessTime::now()->toDateString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new InvalidArgumentException('Stay SQL needs a Y-m-d date.');
        }

        return $today;
    }

    /**
     * @param  list<string>  $values
     */
    private static function inList(array $values): string
    {
        return implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            $values,
        ));
    }
}
