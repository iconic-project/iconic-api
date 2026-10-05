<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cabin_claims_prevent_insert
BEFORE INSERT ON cabin_claims
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cabin_claims cannot be inserted'
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cabin_claims_prevent_insert');
    }
};
