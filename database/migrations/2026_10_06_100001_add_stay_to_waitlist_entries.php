<?php

declare(strict_types=1);

use App\Support\Waitlist\BackfillWaitlistStays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
            $table->dropIndex(['departure_id', 'cabin_category', 'removed_at']);
        });

        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable()->change();
            $table->string('cabin_category')->nullable()->change();
            $table->foreignId('room_type_id')->nullable()->after('cabin_category')->constrained('room_types')->restrictOnDelete();
            $table->date('check_in')->nullable()->after('room_type_id');
            $table->date('check_out')->nullable()->after('check_in');
            $table->foreign('departure_id')->references('id')->on('departures')->nullOnDelete();
            $table->index(['room_type_id', 'check_in', 'check_out', 'removed_at'], 'waitlist_entries_stay_index');
        });

        app(BackfillWaitlistStays::class)->handle();
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
            $table->dropIndex('waitlist_entries_stay_index');
            $table->dropConstrainedForeignId('room_type_id');
            $table->dropColumn(['check_in', 'check_out']);
        });

        DB::table('waitlist_entries')->whereNull('departure_id')->delete();

        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable(false)->change();
            $table->string('cabin_category')->nullable(false)->change();
            $table->foreign('departure_id')->references('id')->on('departures')->restrictOnDelete();
            $table->index(['departure_id', 'cabin_category', 'removed_at']);
        });
    }
};
