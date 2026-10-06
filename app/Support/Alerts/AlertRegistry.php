<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use App\Enums\AlertKind;
use App\Enums\AlertSeverity;
use App\Enums\Permission;
use App\Models\User;

final class AlertRegistry
{
    /**
     * @return list<AlertKindDefinition>
     */
    public static function all(): array
    {
        return [
            new AlertKindDefinition(
                AlertKind::OverdueBalance,
                AlertSeverity::Warn,
                [Permission::BookingsOverdueDecision],
                'The overdue flag becomes true (day 1).',
                'The overdue flag clears, or the due date changes.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::CommissionCap,
                AlertSeverity::Warn,
                [Permission::CommissionsOverrideCap],
                'A booking is held at ON_HOLD_AGENCY.',
                'The booking leaves ON_HOLD_AGENCY.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::WireNotReceived,
                AlertSeverity::Warn,
                [Permission::PaymentsMarkWireReceived],
                'An awaiting-wire payment passes its window.',
                'The wire is received or released.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::SlaBreach,
                AlertSeverity::Warn,
                [Permission::RecordsActOnAny],
                'An open system task passes its due time.',
                'The task closes, or its due time is no longer past.',
                'crm',
            ),
            new AlertKindDefinition(
                AlertKind::DeliveryFailed,
                AlertSeverity::Warn,
                [Permission::SyncRetry],
                'A delivery is failed or blocked.',
                'A later delivery of the same document is sent.',
                'crm',
            ),
            new AlertKindDefinition(
                AlertKind::ConfirmedAtCheckIn,
                AlertSeverity::Critical,
                [Permission::BookingsOverdueDecision, Permission::PaymentsRecord],
                'A booking is still CONFIRMED or ON_HOLD_AGENCY on or after its check-in date.',
                'The booking leaves that status.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::LedgerDrift,
                AlertSeverity::Critical,
                [Permission::PaymentsRecord, Permission::RefundsApprove],
                'A Stripe payment, paid figure, or refund total disagrees with the ledger.',
                'The next ledger run finds no difference for that PaymentIntent, booking, or charge.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::CommissionLeakage,
                AlertSeverity::Warn,
                [Permission::AgenciesManage],
                'A trade booking has no agency, an over-cap booking escaped the hold, or an approved agency has no payment terms.',
                'That finding is gone.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::LowOccupancy,
                AlertSeverity::Info,
                [Permission::PropertiesManage, Permission::RatesManage],
                'A run of nights in the low-occupancy window is below the sold-room threshold.',
                'The run is no longer below the threshold.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::ManifestDataOverdue,
                AlertSeverity::Warn,
                [Permission::GuestsViewSensitive],
                'Passenger data is incomplete past the registration deadline, and the stay has not ended.',
                'The passenger data is complete, or the stay has ended.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::NpsLow,
                AlertSeverity::Critical,
                [Permission::GuestExperienceManage],
                'A post-trip score is below nps.alert_below.',
                'The NPS reply task closes.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::ReportFailed,
                AlertSeverity::Warn,
                [Permission::PanelRms],
                'A scheduled report run fails.',
                'A later run of that definition succeeds.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::CharterDepositDue,
                AlertSeverity::Warn,
                [Permission::BookingsOverdueDecision],
                'A charter deposit is unpaid after its due date.',
                'The deposit is settled, or the booking leaves PENDING_PAYMENT.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::ArrivalNotCheckedIn,
                AlertSeverity::Warn,
                [Permission::BookingsFrontDesk],
                'Night audit finds a confirmed or fully paid arrival that is not checked in.',
                'The booking is checked in, or it leaves confirmed and fully paid.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::InHousePastCheckOut,
                AlertSeverity::Warn,
                [Permission::BookingsFrontDesk],
                'Night audit finds an in-house booking past its check-out date.',
                'The booking is checked out.',
                'rms',
            ),
            new AlertKindDefinition(
                AlertKind::CheckOutNotCompleted,
                AlertSeverity::Warn,
                [Permission::BookingsFrontDesk],
                'Night audit finds an in-house booking whose check-out is today.',
                'The booking is checked out.',
                'rms',
            ),
        ];
    }

    public static function get(AlertKind $kind): AlertKindDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->kind === $kind) {
                return $definition;
            }
        }

        throw new \LogicException('Missing alert kind '.$kind->value);
    }

    public static function sees(User $user, AlertKind $kind): bool
    {
        foreach (self::get($kind)->audience as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<AlertKind>
     */
    public static function visibleTo(User $user): array
    {
        $kinds = [];

        foreach (self::all() as $definition) {
            if (self::sees($user, $definition->kind)) {
                $kinds[] = $definition->kind;
            }
        }

        return $kinds;
    }
}
