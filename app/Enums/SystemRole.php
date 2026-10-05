<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Collection;

enum SystemRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case SalesExec = 'sales-exec';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::SalesExec => 'Sales Exec',
        };
    }

    /**
     * @return Collection<int, Permission>
     */
    public function defaultPermissions(): Collection
    {
        return match ($this) {
            self::Admin => collect(),
            self::Manager => collect([
                Permission::PanelRms,
                Permission::PanelCrm,
                Permission::BookingsViewAll,
                Permission::BookingsCreate,
                Permission::BookingsChangeStatus,
                Permission::BookingsMove,
                Permission::BookingsOverrideRestrictions,
                Permission::RequestsConfirm,
                Permission::RequestsRelease,
                Permission::DeparturesManage,
                Permission::ItinerariesManage,
                Permission::BlocksManage,
                Permission::InventoryManageRestrictions,
                Permission::OffersManage,
                Permission::AgenciesManage,
                Permission::EngineCopyManage,
                Permission::PipelineMoveStage,
                Permission::ContactsManage,
                Permission::ContactsMerge,
                Permission::ConsentsRecord,
                Permission::CampaignsManage,
                Permission::GuestsViewSensitive,
                Permission::GuestExperienceManage,
            ]),
            self::SalesExec => collect([
                Permission::PanelRms,
                Permission::PanelCrm,
                Permission::BookingsViewAll,
                Permission::BookingsCreate,
                Permission::BookingsChangeStatus,
                Permission::BookingsMove,
                Permission::RequestsConfirm,
                Permission::RequestsRelease,
                Permission::PipelineMoveStage,
                Permission::ContactsManage,
            ]),
        };
    }
}
