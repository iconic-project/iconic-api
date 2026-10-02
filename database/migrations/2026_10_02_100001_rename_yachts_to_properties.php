<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabins', function (Blueprint $table): void {
            $table->dropForeign(['yacht_id']);
            $table->dropUnique(['yacht_id', 'code']);
        });

        Schema::table('departures', function (Blueprint $table): void {
            $table->dropForeign(['yacht_id']);
            $table->dropUnique(['yacht_id', 'date']);
        });

        Schema::rename('yachts', 'properties');

        Schema::table('cabins', function (Blueprint $table): void {
            $table->renameColumn('yacht_id', 'property_id');
        });

        Schema::table('departures', function (Blueprint $table): void {
            $table->renameColumn('yacht_id', 'property_id');
        });

        Schema::table('cabins', function (Blueprint $table): void {
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->unique(['property_id', 'code']);
        });

        Schema::table('departures', function (Blueprint $table): void {
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->unique(['property_id', 'date']);
        });

        Schema::table('properties', function (Blueprint $table): void {
            $table->string('slug')->nullable()->unique();
            $table->string('timezone', 64)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode', 32)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('description')->nullable();
            $table->string('hero_image_path')->nullable();
            $table->string('hero_alt')->nullable();
            $table->json('highlights')->nullable();
            $table->json('facts')->nullable();
            $table->json('faqs')->nullable();
            $table->text('policies_text')->nullable();
            $table->string('meta_title', 60)->nullable();
            $table->string('meta_description', 155)->nullable();
            $table->string('status', 16)->default('ACTIVE');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn([
                'slug',
                'timezone',
                'address_line_1',
                'address_line_2',
                'city',
                'postcode',
                'country',
                'phone',
                'email',
                'description',
                'hero_image_path',
                'hero_alt',
                'highlights',
                'facts',
                'faqs',
                'policies_text',
                'meta_title',
                'meta_description',
                'status',
            ]);
        });

        Schema::table('cabins', function (Blueprint $table): void {
            $table->dropForeign(['property_id']);
            $table->dropUnique(['property_id', 'code']);
        });

        Schema::table('departures', function (Blueprint $table): void {
            $table->dropForeign(['property_id']);
            $table->dropUnique(['property_id', 'date']);
        });

        Schema::table('cabins', function (Blueprint $table): void {
            $table->renameColumn('property_id', 'yacht_id');
        });

        Schema::table('departures', function (Blueprint $table): void {
            $table->renameColumn('property_id', 'yacht_id');
        });

        Schema::rename('properties', 'yachts');

        Schema::table('cabins', function (Blueprint $table): void {
            $table->foreign('yacht_id')->references('id')->on('yachts')->restrictOnDelete();
            $table->unique(['yacht_id', 'code']);
        });

        Schema::table('departures', function (Blueprint $table): void {
            $table->foreign('yacht_id')->references('id')->on('yachts')->restrictOnDelete();
            $table->unique(['yacht_id', 'date']);
        });
    }
};
