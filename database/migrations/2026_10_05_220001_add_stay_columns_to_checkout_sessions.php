<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
        });

        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable()->change();
            $table->foreign('departure_id')->references('id')->on('departures')->restrictOnDelete();
            $table->date('check_in')->nullable()->after('cabins');
            $table->date('check_out')->nullable()->after('check_in');
            $table->json('rooms')->nullable()->after('check_out');
            $table->json('guest')->nullable()->after('rooms');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
            $table->dropColumn(['check_in', 'check_out', 'rooms', 'guest']);
        });

        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable(false)->change();
            $table->foreign('departure_id')->references('id')->on('departures')->restrictOnDelete();
        });
    }
};
