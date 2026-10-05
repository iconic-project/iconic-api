<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\DealStage;

final class DealStageMap
{
    /**
     * Doc 07 §5 as implemented (M4). Stored stage is QUOTED, not the table's "SQL — QUOTED" label.
     *
     * @return list<array{stage: string, label: string, owner: string, enters_when: string, rms_statuses: list<string>, leaves_when: string}>
     */
    public static function rows(): array
    {
        return [
            self::row(DealStage::NewLead, 'A charter enquiry, or staff open a lead', [], 'Sales qualifies the lead'),
            self::row(DealStage::Qualifying, 'Sales accepts the lead', [], 'Budget, dates and party are confirmed'),
            self::row(DealStage::Quoted, 'A quote is issued', [], 'The client engages on the quote'),
            self::row(DealStage::Negotiation, 'Terms are under discussion', [], 'A request or booking is created'),
            self::row(
                DealStage::DepositPending,
                'A booking is created and still awaits the deposit',
                ['REQUESTED', 'PENDING_PAYMENT', 'ON_HOLD_AGENCY'],
                'The deposit is verified, or the booking is released',
            ),
            self::row(
                DealStage::BookingConfirmed,
                'The deposit is verified, or the booking is in house or overdue',
                ['CONFIRMED', 'FULLY_PAID', 'IN_HOUSE', 'OVERDUE'],
                'The stay checks out, or the booking is cancelled',
            ),
            self::row(DealStage::WonCompleted, 'The stay is checked out', ['CHECKED_OUT'], 'It stays a past guest'),
            self::row(
                DealStage::Lost,
                'Every bound booking is cancelled or released, or a person marks an unbound deal lost',
                ['CANCELLED', 'CANCELLED_POSTPAID', 'RELEASED'],
                'An unbound lost deal can be reopened with a reason',
            ),
        ];
    }

    /**
     * @param  list<string>  $statuses
     * @return array{stage: string, label: string, owner: string, enters_when: string, rms_statuses: list<string>, leaves_when: string}
     */
    private static function row(DealStage $stage, string $enters, array $statuses, string $leaves): array
    {
        return [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'owner' => $stage->owner(),
            'enters_when' => $enters,
            'rms_statuses' => $statuses,
            'leaves_when' => $leaves,
        ];
    }
}
