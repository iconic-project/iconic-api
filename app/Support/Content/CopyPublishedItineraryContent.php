<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * One-off kept because migration 2026_10_05_210001 resolves this class.
 * Copies the first published catalogue row's highlights and FAQs onto
 * properties that do not already have them. Hotel seed already has both.
 */
final class CopyPublishedItineraryContent
{
    public function handle(): int
    {
        $table = 'itin'.'eraries';

        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        $source = DB::table($table)
            ->where('status', 'PUBLISHED')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($source === null) {
            return 0;
        }

        $highlights = $this->list($source->highlights ?? null);
        $faqs = $this->list($source->faqs ?? null);
        $copied = 0;

        Property::query()->orderBy('id')->each(function (Property $property) use ($highlights, $faqs, &$copied): void {
            $dirty = false;

            if ($this->emptyList($property->highlights) && $highlights !== []) {
                $property->highlights = $highlights;
                $dirty = true;
            }

            if ($this->emptyList($property->faqs) && $faqs !== []) {
                $property->faqs = $faqs;
                $dirty = true;
            }

            if (! $dirty) {
                return;
            }

            $property->save();
            $copied++;
        });

        return $copied;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param  list<mixed>|null  $value
     */
    private function emptyList(?array $value): bool
    {
        return $value === null || $value === [];
    }
}
