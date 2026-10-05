<?php

declare(strict_types=1);

use App\Support\Inventory\BackfillClosedDepartureRestrictions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained('room_types')->restrictOnDelete();
            $table->date('night');
            $table->boolean('stop_sell')->default(false);
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            $table->unsignedSmallInteger('min_stay')->nullable();
            $table->unsignedSmallInteger('max_stay')->nullable();
            $table->string('note', 500)->nullable();
            // MySQL unique indexes treat NULL room_type_id as distinct, so a property-wide
            // row (null type) would not collide with a second null. The generated key
            // uses 0 for "all types".
            $table->string('scope_key', 96)
                ->storedAs("concat(`property_id`, '-', ifnull(`room_type_id`, 0), '-', `night`)")
                ->unique();
            $table->timestamps();
            $table->auditColumns();

            $table->index(['property_id', 'night']);
        });

        $this->grantManager();

        BackfillClosedDepartureRestrictions::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_restrictions');
    }

    private function grantManager(): void
    {
        $manager = DB::table('roles')->where('slug', 'manager')->first();

        if ($manager === null) {
            return;
        }

        $raw = $manager->permissions ?? '[]';
        $permissions = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($permissions)) {
            $permissions = [];
        }

        foreach (['inventory.manage_restrictions', 'bookings.override_restrictions'] as $permission) {
            if (! in_array($permission, $permissions, true)) {
                $permissions[] = $permission;
            }
        }

        DB::table('roles')->where('id', $manager->id)->update([
            'permissions' => json_encode(array_values($permissions), JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
