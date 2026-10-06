<?php

declare(strict_types=1);

use App\Support\Offers\BackfillOfferStayWindows;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->json('cabin_types')->nullable()->change();
            $table->json('itinerary_codes')->nullable()->change();
            $table->date('stay_from')->nullable()->after('travel_to');
            $table->date('stay_to')->nullable()->after('stay_from');
            $table->unsignedInteger('min_nights')->nullable()->after('stay_to');
            $table->json('applies_to_room_types')->nullable()->after('min_nights');
            $table->json('applies_to_rate_plans')->nullable()->after('applies_to_room_types');
        });

        app(BackfillOfferStayWindows::class)->handle();
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn([
                'stay_from',
                'stay_to',
                'min_nights',
                'applies_to_room_types',
                'applies_to_rate_plans',
            ]);
        });
    }
};
