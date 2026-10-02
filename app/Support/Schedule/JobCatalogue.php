<?php

declare(strict_types=1);

namespace App\Support\Schedule;

final class JobCatalogue
{
    /**
     * Doc 07 §7 as this system implements it (N3).
     *
     * @return list<array{job: string, command: string, sentence: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'job' => 'Ledger reconcile',
                'command' => 'iconic:ledger-check',
                'sentence' => 'Drift is reported and never corrected.',
            ],
            [
                'job' => 'Commission leakage scan',
                'command' => 'iconic:commission-scan',
                'sentence' => 'Raises a warning for a trade booking with no agency, an over-cap booking that escaped the hold, or an approved agency with no payment terms.',
            ],
            [
                'job' => 'Hold expiry sweep',
                'command' => 'inventory:release-expired-holds',
                'sentence' => 'Releases expired holds.',
            ],
            [
                'job' => 'Occupancy check',
                'command' => 'iconic:occupancy-check',
                'sentence' => 'Raises a low-occupancy alert for open departures inside the configured window.',
            ],
            [
                'job' => 'Document version check',
                'command' => 'iconic:document-check',
                'sentence' => 'Re-queues one failed automatic send and never issues a new version.',
            ],
            [
                'job' => 'Segment recompute',
                'command' => 'not needed',
                'sentence' => 'Not needed: segments are derived in SQL (L2).',
            ],
            [
                'job' => 'Consent sweep',
                'command' => 'not needed',
                'sentence' => 'Not needed: consent is read at send time from one register (M2).',
            ],
        ];
    }
}
