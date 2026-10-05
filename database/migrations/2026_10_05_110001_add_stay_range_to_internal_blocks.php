<?php

declare(strict_types=1);

use App\Support\Inventory\BackfillInternalBlockRanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_blocks', function (Blueprint $table): void {
            $table->foreignId('property_id')->nullable()->after('reference')->constrained('properties')->restrictOnDelete();
            $table->date('starts_on')->nullable()->after('property_id');
            $table->date('ends_on')->nullable()->after('starts_on');
        });

        BackfillInternalBlockRanges::run();

        Schema::table('internal_blocks', function (Blueprint $table): void {
            $table->unsignedBigInteger('property_id')->nullable(false)->change();
            $table->date('starts_on')->nullable(false)->change();
            $table->date('ends_on')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('internal_blocks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('property_id');
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
