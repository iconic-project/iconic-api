<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Staff permissions are code, not data. Adding a case is a code change and
 * needs a default decision for the Manager and Sales Exec roles.
 */
enum Permission: string
{
    case PanelRms = 'panel.rms';
    case PanelCrm = 'panel.crm';

    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case RecordsActOnAny = 'records.act_on_any';

    case BookingsViewAll = 'bookings.view_all';
    case BookingsCreate = 'bookings.create';
    case BookingsChangeStatus = 'bookings.change_status';
    case BookingsMove = 'bookings.move';
    case BookingsFrontDesk = 'bookings.front_desk';
    case BookingsDelete = 'bookings.delete';
    case BookingsOverrideRestrictions = 'bookings.override_restrictions';
    case BookingsWaivePenalty = 'bookings.waive_penalty';

    case RequestsConfirm = 'requests.confirm';
    case RequestsRelease = 'requests.release';

    case DeparturesManage = 'departures.manage';
    case PropertiesManage = 'properties.manage';
    case ItinerariesManage = 'itineraries.manage';
    case BlocksManage = 'blocks.manage';
    case InventoryManageRestrictions = 'inventory.manage_restrictions';

    case RatesManage = 'rates.manage';
    case RulesView = 'rules.view';
    case RulesManage = 'rules.manage';
    case EngineSettingsManage = 'engine_settings.manage';
    case EngineCopyManage = 'engine_copy.manage';
    case OffersManage = 'offers.manage';
    case OffersApprove = 'offers.approve';
    case ExtrasManage = 'extras.manage';
    case AgenciesManage = 'agencies.manage';

    case GuestsViewSensitive = 'guests.view_sensitive';
    case GuestExperienceManage = 'guest_experience.manage';

    case PipelineMoveStage = 'pipeline.move_stage';
    case ContactsManage = 'contacts.manage';
    case ContactsMerge = 'contacts.merge';
    case ConsentsRecord = 'consents.record';
    case CampaignsManage = 'campaigns.manage';
    case SyncRetry = 'sync.retry';

    case PaymentsRecord = 'payments.record';
    case PaymentsMarkWireReceived = 'payments.mark_wire_received';
    case RefundsExecute = 'refunds.execute';

    case RefundsApprove = 'refunds.approve';
    case CommissionsOverrideCap = 'commissions.override_cap';
    case CommissionsRecordPayout = 'commissions.record_payout';
    case BookingsOverdueDecision = 'bookings.overdue_decision';

    case PrivacyManage = 'privacy.manage';

    public function label(): string
    {
        return match ($this) {
            self::PanelRms => 'Access RMS',
            self::PanelCrm => 'Access CRM',
            self::UsersManage => 'Manage users',
            self::RolesManage => 'Manage roles',
            self::RecordsActOnAny => 'Act on any record',
            self::BookingsViewAll => 'View all reservations',
            self::BookingsCreate => 'Create reservation',
            self::BookingsChangeStatus => 'Change reservation status',
            self::BookingsMove => 'Move reservation',
            self::BookingsFrontDesk => 'Front desk check-in and check-out',
            self::BookingsDelete => 'Delete reservation',
            self::BookingsOverrideRestrictions => 'Override sell restrictions',
            self::BookingsWaivePenalty => 'Waive a stay-change penalty',
            self::RequestsConfirm => 'Confirm requests',
            self::RequestsRelease => 'Release requests',
            self::DeparturesManage => 'Manage departures',
            self::PropertiesManage => 'Manage properties',
            self::ItinerariesManage => 'Manage itineraries',
            self::BlocksManage => 'Manage internal blocks',
            self::InventoryManageRestrictions => 'Manage sell restrictions',
            self::RatesManage => 'Edit rates, deposit terms and discount rules',
            self::RulesView => 'View business rules',
            self::RulesManage => 'View and adjust business rules',
            self::EngineSettingsManage => 'Manage engine settings',
            self::EngineCopyManage => 'Edit engine copy',
            self::OffersManage => 'Manage offers',
            self::OffersApprove => 'Approve offers',
            self::ExtrasManage => 'Manage extras catalog',
            self::AgenciesManage => 'Manage agencies',
            self::GuestsViewSensitive => 'View sensitive guest data',
            self::GuestExperienceManage => 'Record guest preferences',
            self::PipelineMoveStage => 'Move lead stage',
            self::ContactsManage => 'Manage contacts',
            self::ContactsMerge => 'Merge contacts',
            self::ConsentsRecord => 'Record contact consent',
            self::CampaignsManage => 'Manage campaigns',
            self::SyncRetry => 'Retry failed sync work',
            self::PaymentsRecord => 'Record a payment',
            self::PaymentsMarkWireReceived => 'Mark wire received',
            self::RefundsExecute => 'Execute refunds',
            self::RefundsApprove => 'Approve refunds',
            self::CommissionsOverrideCap => 'Approve commission above cap',
            self::CommissionsRecordPayout => 'Record a commission payout',
            self::BookingsOverdueDecision => 'OPS-007 overdue decisions',
            self::PrivacyManage => 'Manage subject requests',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::PanelRms,
            self::PanelCrm => 'sections',
            self::UsersManage,
            self::RolesManage,
            self::RecordsActOnAny => 'admin',
            self::BookingsViewAll,
            self::BookingsCreate,
            self::BookingsChangeStatus,
            self::BookingsMove,
            self::BookingsFrontDesk,
            self::BookingsDelete,
            self::BookingsOverrideRestrictions,
            self::BookingsWaivePenalty => 'bookings',
            self::RequestsConfirm,
            self::RequestsRelease => 'requests',
            self::DeparturesManage,
            self::PropertiesManage,
            self::ItinerariesManage,
            self::BlocksManage,
            self::InventoryManageRestrictions => 'inventory',
            self::RatesManage,
            self::RulesView,
            self::RulesManage,
            self::EngineSettingsManage,
            self::EngineCopyManage,
            self::OffersManage,
            self::OffersApprove,
            self::ExtrasManage,
            self::AgenciesManage => 'commercial',
            self::GuestsViewSensitive,
            self::GuestExperienceManage => 'guests',
            self::PipelineMoveStage,
            self::ContactsManage,
            self::ContactsMerge,
            self::ConsentsRecord,
            self::CampaignsManage,
            self::SyncRetry => 'crm',
            self::PaymentsRecord,
            self::PaymentsMarkWireReceived,
            self::RefundsExecute,
            self::CommissionsRecordPayout => 'finance',
            self::RefundsApprove,
            self::CommissionsOverrideCap,
            self::BookingsOverdueDecision => 'director',
            self::PrivacyManage => 'admin',
        };
    }

    public function isFlag(): bool
    {
        return in_array($this->group(), ['finance', 'director'], true);
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'sections' => 'Sections',
            'admin' => 'Admin',
            'bookings' => 'Bookings',
            'requests' => 'Requests',
            'inventory' => 'Inventory',
            'commercial' => 'Commercial',
            'guests' => 'Guests',
            'crm' => 'CRM',
            'finance' => 'Finance',
            'director' => 'Director',
            default => throw new InvalidArgumentException("Unknown permission group [{$group}]."),
        };
    }
}
