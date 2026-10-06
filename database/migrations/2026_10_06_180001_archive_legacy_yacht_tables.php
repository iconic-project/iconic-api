<?php

declare(strict_types=1);

use App\Support\Schema\HotelContractCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archives departures, itineraries and cabin_claims, then drops the
 * legacy columns on the live tables.
 *
 * down() restores the table names and adds the dropped columns back
 * as nullable. Values that were in those columns are not restorable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('departures')) {
            return;
        }

        $failures = app(HotelContractCheck::class)->dataFailures();

        if ($failures !== []) {
            throw new RuntimeException('Hotel contract check failed: '.implode('; ', $failures));
        }

        Schema::table('manifests', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
            $table->dropIndex(['departure_id', 'status']);
            $table->dropColumn(['departure_id', 'back_to_back', 'png_collected']);
        });

        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropForeign(['departure_id']);
            $table->dropColumn(['departure_id', 'cabin_category']);
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn(['cabin_types', 'itinerary_codes', 'travel_from', 'travel_to']);
        });

        Schema::table('guests', function (Blueprint $table): void {
            $table->dropColumn('png_category');
        });

        if (Schema::hasColumn('rooms', 'category')) {
            Schema::table('rooms', function (Blueprint $table): void {
                $table->dropColumn('category');
            });
        }

        Schema::table('room_night_claims', function (Blueprint $table): void {
            $table->dropUnique(['legacy_cabin_claim_id', 'night']);
            $table->dropColumn('legacy_cabin_claim_id');
        });

        Schema::rename('itineraries', 'archive_itineraries');
        Schema::rename('departures', 'archive_departures');
        Schema::rename('cabin_claims', 'archive_cabin_claims');
    }

    public function down(): void
    {
        if (Schema::hasTable('archive_itineraries')) {
            Schema::rename('archive_itineraries', 'itineraries');
        }

        if (Schema::hasTable('archive_departures')) {
            Schema::rename('archive_departures', 'departures');
        }

        if (Schema::hasTable('archive_cabin_claims')) {
            Schema::rename('archive_cabin_claims', 'cabin_claims');
        }

        Schema::table('room_night_claims', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_cabin_claim_id')->nullable();
            $table->unique(['legacy_cabin_claim_id', 'night']);
        });

        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('category', 16)->nullable();
        });

        Schema::table('guests', function (Blueprint $table): void {
            $table->string('png_category', 32)->nullable();
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->json('cabin_types')->nullable();
            $table->json('itinerary_codes')->nullable();
            $table->date('travel_from')->nullable();
            $table->date('travel_to')->nullable();
        });

        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable();
            $table->string('cabin_category')->nullable();
            $table->foreign('departure_id')->references('id')->on('departures')->nullOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('departure_id')->nullable();
            $table->boolean('back_to_back')->default(false);
            $table->boolean('png_collected')->default(false);
            $table->index(['departure_id', 'status']);
            $table->foreign('departure_id')->references('id')->on('departures')->nullOnDelete();
        });

        Schema::table('manifests', function (Blueprint $table): void {
            $table->foreign('departure_id')->references('id')->on('departures')->restrictOnDelete();
        });
    }
};
