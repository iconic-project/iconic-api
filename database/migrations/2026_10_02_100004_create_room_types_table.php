<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 16);
            $table->string('name');
            $table->unsignedTinyInteger('base_occupancy');
            $table->unsignedTinyInteger('max_occupancy');
            $table->unsignedTinyInteger('max_adults');
            $table->unsignedTinyInteger('max_children');
            $table->boolean('waitlist_enabled')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('status', 16)->default('ACTIVE');
            $table->string('slug')->nullable()->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('size_sqm')->nullable();
            $table->string('bed_setup')->nullable();
            $table->json('amenities')->nullable();
            $table->json('photos')->nullable();
            $table->string('meta_title', 60)->nullable();
            $table->string('meta_description', 155)->nullable();
            $table->timestamps();
            $table->auditColumns();

            $table->unique(['property_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
