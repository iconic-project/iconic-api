<?php

declare(strict_types=1);

use App\Support\Inventory\BackfillRoomNightClaims;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        app(BackfillRoomNightClaims::class)->run();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS room_night_claims_prevent_delete');

        DB::table('room_night_claims')->whereNotNull('legacy_cabin_claim_id')->delete();

        DB::unprepared(<<<'SQL'
CREATE TRIGGER room_night_claims_prevent_delete
BEFORE DELETE ON room_night_claims
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'room_night_claims cannot be deleted'
SQL);
    }
};
