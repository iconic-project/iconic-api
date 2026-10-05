<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Support\Bookings\BackfillBookingStays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('property_id')->nullable()->after('departure_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('room_type_id')->nullable()->after('property_id')->constrained('room_types')->restrictOnDelete();
            $table->date('check_in')->nullable()->after('room_type_id');
            $table->date('check_out')->nullable()->after('check_in');
            $table->unsignedSmallInteger('nights')->nullable()->after('check_out');
            $table->string('rate_plan_code', 16)->nullable()->after('nights');
            $table->json('night_lines')->nullable()->after('rate_plan_code');
            $table->json('tax_lines')->nullable()->after('night_lines');
            $table->json('child_ages')->nullable()->after('tax_lines');
            $table->char('expected_arrival_time', 5)->nullable()->after('child_ages');
            $table->timestamp('checked_in_at')->nullable()->after('expected_arrival_time');
            $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            $table->timestamp('no_show_at')->nullable()->after('checked_out_at');

            $table->index(['property_id', 'check_in']);
            $table->index(['property_id', 'check_out']);
            $table->index(['status', 'check_in']);
        });

        app(BackfillBookingStays::class)->run();

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('property_id')->nullable(false)->change();
            $table->date('check_in')->nullable(false)->change();
            $table->date('check_out')->nullable(false)->change();
            $table->unsignedSmallInteger('nights')->nullable(false)->change();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable()->change();
            $table->foreign('departure_id')->references('id')->on('departures')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('bookings')->where('status', BookingStatus::InHouse->value)->update(['status' => 'ON_BOARD']);
        DB::table('bookings')->where('status', BookingStatus::CheckedOut->value)->update(['status' => 'COMPLETED']);
        DB::table('bookings')->where('status', BookingStatus::NoShow->value)->update(['status' => 'CANCELLED']);

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable(false)->change();
            $table->foreign('departure_id')->references('id')->on('departures')->restrictOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['property_id', 'check_in']);
            $table->dropIndex(['property_id', 'check_out']);
            $table->dropIndex(['status', 'check_in']);
            $table->dropConstrainedForeignId('property_id');
            $table->dropConstrainedForeignId('room_type_id');
            $table->dropColumn([
                'check_in',
                'check_out',
                'nights',
                'rate_plan_code',
                'night_lines',
                'tax_lines',
                'child_ages',
                'expected_arrival_time',
                'checked_in_at',
                'checked_out_at',
                'no_show_at',
            ]);
        });
    }
};
