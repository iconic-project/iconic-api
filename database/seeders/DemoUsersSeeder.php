<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

final class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $admin = Role::query()->where('slug', SystemRole::Admin->value)->firstOrFail();
        $manager = Role::query()->where('slug', SystemRole::Manager->value)->firstOrFail();
        $salesExec = Role::query()->where('slug', SystemRole::SalesExec->value)->firstOrFail();

        $financePermissions = [
            Permission::PanelRms,
            Permission::BookingsViewAll,
            Permission::PaymentsRecord,
            Permission::PaymentsMarkWireReceived,
            Permission::RefundsExecute,
            Permission::CommissionsRecordPayout,
        ];

        $externalFinance = Role::query()->firstOrCreate(
            ['slug' => 'external-finance'],
            [
                'name' => 'External finance',
                'description' => null,
                'permissions' => $financePermissions,
                'is_system' => false,
            ],
        );

        if ($externalFinance->permissions->pluck('value')->sort()->values()->all()
            !== collect($financePermissions)->map(fn (Permission $permission): string => $permission->value)->sort()->values()->all()
        ) {
            $externalFinance->permissions = $financePermissions;
            $externalFinance->save();
        }

        $this->seedUser('Carolina M.', 'carolina@iconic.test', $admin);
        $this->seedUser('Mateo R.', 'mateo@iconic.test', $manager);
        $this->seedUser('Lucía B.', 'lucia@iconic.test', $salesExec);
        $this->seedUser('CFO (external)', 'cfo@iconic.test', $externalFinance);
    }

    private function seedUser(string $name, string $email, Role $role): void
    {
        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'role_id' => $role->id,
                'status' => UserStatus::Active,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ],
        );
    }
}
