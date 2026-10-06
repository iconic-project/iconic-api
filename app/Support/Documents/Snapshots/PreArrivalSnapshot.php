<?php

declare(strict_types=1);

namespace App\Support\Documents\Snapshots;

use App\Enums\DocumentKind;
use App\Models\Booking;

final class PreArrivalSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function build(
        Booking $booking,
        bool $fresh,
        DocumentKind $kind = DocumentKind::PreArrival,
    ): array {
        $facts = DocumentFacts::load($booking, $fresh);
        $property = $booking->property;
        $description = is_string($property->description) ? $property->description : '';
        $policies = is_string($property->policies_text) ? $property->policies_text : '';

        return [
            'document' => $facts->document(
                $kind->value,
                'BEFORE YOU ARRIVE',
                $facts->nextVersion($kind->value),
            ),
            'reference' => $booking->displayReference(),
            'stay' => $facts->stay(),
            'description' => $description,
            'policies' => $policies,
            'before_you_arrive' => 'Your preferences questionnaire arrives with this note.',
        ];
    }
}
