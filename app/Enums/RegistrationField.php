<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Booking;
use App\Models\Guest;

enum RegistrationField: string
{
    case FullName = 'full_name';
    case Nationality = 'nationality';
    case Dob = 'dob';
    case DocumentType = 'document_type';
    case DocumentNumber = 'document_number';
    case Address = 'address';
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
    case Room = 'room';

    public function label(): string
    {
        return match ($this) {
            self::FullName => 'full name',
            self::Nationality => 'nationality',
            self::Dob => 'date of birth',
            self::DocumentType => 'document type',
            self::DocumentNumber => 'document number',
            self::Address => 'address',
            self::CheckIn => 'check-in',
            self::CheckOut => 'check-out',
            self::Room => 'room',
        };
    }

    public function sensitive(): bool
    {
        return match ($this) {
            self::Nationality, self::Dob, self::DocumentNumber => true,
            default => false,
        };
    }

    public function onGuest(): bool
    {
        return match ($this) {
            self::CheckIn, self::CheckOut, self::Room => false,
            default => true,
        };
    }

    /**
     * Whether the guest or booking has a value for this field.
     * document_type and document_number read passport_no. address has no guest column.
     */
    public function present(Guest $guest, Booking $booking): bool
    {
        return match ($this) {
            self::FullName => $guest->first_name !== '' && $guest->last_name !== '',
            self::Nationality => $guest->nationality !== null && $guest->nationality !== '',
            self::Dob => $guest->dob !== null,
            self::DocumentType, self::DocumentNumber => $guest->passport_no !== null && $guest->passport_no !== '',
            self::Address => false,
            self::CheckIn, self::CheckOut => true,
            self::Room => $booking->room_id !== null,
        };
    }

    public function presentOnBooking(Booking $booking): bool
    {
        return match ($this) {
            self::CheckIn, self::CheckOut => true,
            self::Room => $booking->room_id !== null,
            default => false,
        };
    }

    public function cell(Guest $guest, Booking $booking): string
    {
        return match ($this) {
            self::FullName => trim($guest->first_name.' '.$guest->last_name),
            self::Nationality => (string) ($guest->nationality ?? ''),
            self::Dob => $guest->dob?->toDateString() ?? '',
            self::DocumentType => ($guest->passport_no !== null && $guest->passport_no !== '') ? 'passport' : '',
            self::DocumentNumber => (string) ($guest->passport_no ?? ''),
            self::Address => '',
            self::CheckIn => $booking->stay()->checkIn()->toDateString(),
            self::CheckOut => $booking->stay()->checkOut()->toDateString(),
            self::Room => $booking->room_id === null ? '' : $booking->room->label,
        };
    }
}
