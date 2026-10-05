<?php

declare(strict_types=1);

namespace App\Support\Operations;

/**
 * @deprecated Sprint 22 removes this alias. Call NightAudit.
 */
final class VoyageStatus
{
    public const ACTOR = 'System · voyage status';

    public function __construct(private readonly NightAudit $audit) {}

    public function run(): void
    {
        $this->audit->run();
    }
}
