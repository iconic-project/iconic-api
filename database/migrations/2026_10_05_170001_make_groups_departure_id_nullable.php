<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable()->change();
            $table->foreign('departure_id')->references('id')->on('departures')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable(false)->change();
            $table->foreign('departure_id')->references('id')->on('departures')->restrictOnDelete();
        });
    }
};
