<?php

declare(strict_types=1);

use App\Support\Content\CopyPublishedItineraryContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Yacht installs only. Hotel seed already stores highlights and FAQs,
 * and this copy skips a property that already has them (09 H20).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('itineraries') || ! Schema::hasTable('properties')) {
            return;
        }

        app(CopyPublishedItineraryContent::class)->handle();
    }

    public function down(): void
    {
        // The copy is a starting point. Down does not wipe property content.
    }
};
