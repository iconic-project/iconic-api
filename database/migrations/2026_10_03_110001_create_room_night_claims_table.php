<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_night_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->date('night');
            $table->morphs('holder');
            $table->string('kind', 16);
            $table->string('hold_type', 16)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 16)->nullable();
            $table->uuid('claim_group');
            // One cabin claim becomes one row per night, so the source id repeats.
            // Uniqueness is the pair, which still refuses a second copy of the same night.
            $table->unsignedBigInteger('legacy_cabin_claim_id')->nullable();
            $table->unique(['legacy_cabin_claim_id', 'night']);
            $table->string('active_key', 64)
                ->nullable()
                ->storedAs("if(`released_at` is null, concat(`room_id`, '-', `night`), null)")
                ->unique();
            $table->timestamps();
            $table->auditColumns();

            $table->index(['night', 'released_at']);
            $table->index(['room_id', 'night']);
            $table->index(['holder_type', 'holder_id', 'released_at']);
            $table->index(['kind', 'expires_at']);
            $table->index('claim_group');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER room_night_claims_prevent_delete
BEFORE DELETE ON room_night_claims
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'room_night_claims cannot be deleted'
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS room_night_claims_prevent_delete');
        Schema::dropIfExists('room_night_claims');
    }
};
