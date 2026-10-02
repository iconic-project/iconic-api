<?php

declare(strict_types=1);

use App\Support\Rooms\BackfillRoomTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cabin_claims DROP COLUMN active_key');

        Schema::table('cabin_claims', function (Blueprint $table): void {
            $table->dropForeign(['cabin_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['cabin_id']);
        });

        Schema::rename('cabins', 'rooms');

        Schema::table('cabin_claims', function (Blueprint $table): void {
            $table->renameColumn('cabin_id', 'room_id');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->renameColumn('cabin_id', 'room_id');
        });

        Schema::table('cabin_claims', function (Blueprint $table): void {
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE cabin_claims ADD COLUMN active_key VARCHAR(64) GENERATED ALWAYS AS (if(`released_at` is null, concat(`departure_id`, '-', `room_id`), null)) STORED",
        );
        Schema::table('cabin_claims', function (Blueprint $table): void {
            $table->unique('active_key');
        });

        Schema::table('rooms', function (Blueprint $table): void {
            $table->foreignId('room_type_id')->nullable()->after('property_id')->constrained('room_types')->restrictOnDelete();
            $table->string('floor', 32)->nullable()->after('label');
            $table->string('status', 16)->default('ACTIVE')->after('sort');
        });

        DB::statement('ALTER TABLE rooms MODIFY category VARCHAR(16) NULL');

        app(BackfillRoomTypes::class)->pending();

        DB::statement('ALTER TABLE rooms MODIFY room_type_id BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rooms MODIFY room_type_id BIGINT UNSIGNED NULL');

        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('room_type_id');
            $table->dropColumn(['floor', 'status']);
        });

        DB::statement('ALTER TABLE cabin_claims DROP INDEX cabin_claims_active_key_unique');
        DB::statement('ALTER TABLE cabin_claims DROP COLUMN active_key');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['room_id']);
        });

        Schema::table('cabin_claims', function (Blueprint $table): void {
            $table->dropForeign(['room_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->renameColumn('room_id', 'cabin_id');
        });

        Schema::table('cabin_claims', function (Blueprint $table): void {
            $table->renameColumn('room_id', 'cabin_id');
        });

        Schema::rename('rooms', 'cabins');

        Schema::table('cabin_claims', function (Blueprint $table): void {
            $table->foreign('cabin_id')->references('id')->on('cabins')->restrictOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreign('cabin_id')->references('id')->on('cabins')->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE cabin_claims ADD COLUMN active_key VARCHAR(64) GENERATED ALWAYS AS (if(`released_at` is null, concat(`departure_id`, '-', `cabin_id`), null)) STORED",
        );
        Schema::table('cabin_claims', function (Blueprint $table): void {
            $table->unique('active_key');
        });
    }
};
