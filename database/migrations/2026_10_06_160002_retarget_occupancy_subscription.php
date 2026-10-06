<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The weekly occupancy subscription followed a departure report.
 * That report is no longer generated. The same cadence now runs occupancy by night.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_subscriptions')
            ->where('definition_key', 'occupancy')
            ->update([
                'definition_key' => 'occupancy-revenue',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('report_subscriptions')
            ->where('definition_key', 'occupancy-revenue')
            ->where('cadence', 'WEEKLY')
            ->update([
                'definition_key' => 'occupancy',
                'updated_at' => now(),
            ]);
    }
};
