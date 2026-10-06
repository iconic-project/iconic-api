<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DML only. Alert and task keys no longer name a departure.
 * Existing open rows keep one identity so the next run does not raise a second row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $replacements = [
            'departure-not-checked-out:' => 'check-out-still-open:',
            'confirmed-at-departure:' => 'confirmed-on-check-in:',
        ];

        foreach ($replacements as $from => $to) {
            DB::table('alerts')
                ->where('base_key', 'like', $from.'%')
                ->update([
                    'base_key' => DB::raw("REPLACE(base_key, '".$from."', '".$to."')"),
                    'idempotency_key' => DB::raw("REPLACE(idempotency_key, '".$from."', '".$to."')"),
                ]);

            DB::table('crm_tasks')
                ->where('idempotency_key', 'like', $from.'%')
                ->update([
                    'idempotency_key' => DB::raw("REPLACE(idempotency_key, '".$from."', '".$to."')"),
                ]);
        }
    }

    public function down(): void
    {
        // Keys already raised under the new prefix stay.
    }
};
