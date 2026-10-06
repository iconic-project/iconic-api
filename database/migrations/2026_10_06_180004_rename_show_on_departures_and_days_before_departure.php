<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns named a yacht inventory date. show_on_calendar is the
 * offer flag for the stay calendar. days_before_arrival is the refund
 * clock measured to check-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('offers', 'show_on_departures')) {
            Schema::table('offers', function (Blueprint $table): void {
                $table->renameColumn('show_on_departures', 'show_on_calendar');
            });
        }

        if (Schema::hasColumn('refund_requests', 'days_before_departure')) {
            Schema::table('refund_requests', function (Blueprint $table): void {
                $table->renameColumn('days_before_departure', 'days_before_arrival');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('offers', 'show_on_calendar')) {
            Schema::table('offers', function (Blueprint $table): void {
                $table->renameColumn('show_on_calendar', 'show_on_departures');
            });
        }

        if (Schema::hasColumn('refund_requests', 'days_before_arrival')) {
            Schema::table('refund_requests', function (Blueprint $table): void {
                $table->renameColumn('days_before_arrival', 'days_before_departure');
            });
        }
    }
};
