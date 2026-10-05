<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Role;
use App\Support\History\History;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REASON = 'Sprint 19: front desk check-in, check-out, and no-show';

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ([SystemRole::Admin, SystemRole::Manager, SystemRole::SalesExec] as $systemRole) {
                $this->grant($systemRole);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ([SystemRole::Admin, SystemRole::Manager, SystemRole::SalesExec] as $systemRole) {
                $role = Role::query()->where('slug', $systemRole->value)->lockForUpdate()->first();

                if (! $role instanceof Role || ! $role->permissions->contains(Permission::BookingsFrontDesk)) {
                    continue;
                }

                $before = $this->sorted($role);
                $role->setAttribute('permissions', $role->permissions
                    ->reject(fn (Permission $permission): bool => $permission === Permission::BookingsFrontDesk)
                    ->values()
                    ->all());
                $role->save();

                History::record($role, 'role.updated', before: [
                    'permissions' => $before,
                ], after: [
                    'permissions' => $this->sorted($role),
                    'added' => [],
                    'removed' => [Permission::BookingsFrontDesk->value],
                ], reason: self::REASON, actor: null);
            }
        });
    }

    private function grant(SystemRole $systemRole): void
    {
        $role = Role::query()->where('slug', $systemRole->value)->lockForUpdate()->first();

        if (! $role instanceof Role || $role->permissions->contains(Permission::BookingsFrontDesk)) {
            return;
        }

        // TODO(OPEN: 19-03) Confirm the Sales Exec grant with the client.
        $before = $this->sorted($role);
        $role->permissions = $role->permissions->push(Permission::BookingsFrontDesk)->unique()->values();
        $role->save();

        History::record($role, 'role.updated', before: [
            'permissions' => $before,
        ], after: [
            'permissions' => $this->sorted($role),
            'added' => [Permission::BookingsFrontDesk->value],
            'removed' => [],
        ], reason: self::REASON, actor: null);
    }

    /**
     * @return list<string>
     */
    private function sorted(Role $role): array
    {
        return $role->permissions
            ->map(fn (Permission $permission): string => $permission->value)
            ->sort()
            ->values()
            ->all();
    }
};
