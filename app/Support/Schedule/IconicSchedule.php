<?php

declare(strict_types=1);

namespace App\Support\Schedule;

use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Config\Documents\BusinessRulesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Telescope\Console\PruneCommand;
use Throwable;

final class IconicSchedule
{
    public static function register(Schedule $schedule): void
    {
        if (self::alreadyRegistered($schedule)) {
            return;
        }

        RecordScheduledRuns::attach(
            $schedule->command('inventory:release-expired-holds')
                ->everyMinute()
                ->withoutOverlapping(),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:inbox-poll')
                ->everyMinute()
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Poll the mailbox for inbound CRM mail'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('engine:expire-stripe-checkouts')
                ->everyMinute()
                ->withoutOverlapping(),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:crm-tasks')
                ->everyFiveMinutes()
                ->withoutOverlapping(),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:alerts')
                ->everyFiveMinutes()
                ->withoutOverlapping(),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:reports-send')
                ->everyFifteenMinutes()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Email scheduled reports whose Galápagos moment has passed'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:journeys')
                ->everyFifteenMinutes()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Advance CRM journeys that are due'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:waitlist-notify')
                ->everyFifteenMinutes()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Email waitlist entries when a cabin in their category is free'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:flag-overdue')
                ->daily()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping(),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:charter-deposits')
                ->daily()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Warn when a charter deposit is unpaid after its due date'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:retention')
                ->daily()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping(),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:events-retention')
                ->daily()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping(),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:documents-due')
                ->daily()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping(),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:night-audit')
                ->dailyAt(self::nightAuditAt())
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Raise front-desk alerts. Changes no booking status.'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:ledger-check')
                ->dailyAt('02:00')
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Report ledger drift and never correct it'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:commission-scan')
                ->dailyAt('02:30')
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Scan for commission leakage'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:occupancy-check')
                ->dailyAt('07:00')
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Raise low-occupancy alerts'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:document-check')
                ->hourly()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Re-queue one failed automatic document send'),
        );

        RecordScheduledRuns::attach(
            $schedule->command('iconic:nps-survey')
                ->hourly()
                ->timezone(BusinessTime::zone())
                ->withoutOverlapping()
                ->onOneServer()
                ->description('Send the post-trip survey after return'),
        );

        if (class_exists(PruneCommand::class)) {
            RecordScheduledRuns::attach(
                $schedule->command('telescope:prune --hours=48')->daily(),
            );
        }
    }

    /**
     * One minute after stay.no_show_cutoff_time, property local. The minute is
     * schedule slack so the cut-off has passed, not a business rule.
     */
    public static function nightAuditAt(): string
    {
        $cutoff = '';

        try {
            $cutoff = app(CurrentConfig::class)->businessRules()->stay->noShowCutoffTime;
        } catch (Throwable) {
            $cutoff = '';
        }

        // A published document from before the stay fields, or a blank value,
        // must not stop Artisan (including migrate) from booting.
        $moment = self::parseClock($cutoff) ?? self::parseClock(self::defaultNoShowCutoff());

        if (! $moment instanceof CarbonImmutable) {
            throw new \InvalidArgumentException('stay.no_show_cutoff_time must be HH:MM.');
        }

        return $moment->addMinute()->format('H:i');
    }

    private static function defaultNoShowCutoff(): string
    {
        $stay = BusinessRulesDocument::initial()['stay'] ?? [];

        return is_array($stay) ? (string) ($stay['no_show_cutoff_time'] ?? '') : '';
    }

    private static function parseClock(string $value): ?CarbonImmutable
    {
        try {
            $moment = CarbonImmutable::createFromFormat('H:i', $value, BusinessTime::zone());
        } catch (Throwable) {
            return null;
        }

        if (! $moment instanceof CarbonImmutable || $moment->format('H:i') !== $value) {
            return null;
        }

        return $moment;
    }

    private static function alreadyRegistered(Schedule $schedule): bool
    {
        foreach ($schedule->events() as $event) {
            if (str_contains(RecordScheduledRuns::commandName($event), 'iconic:flag-overdue')) {
                return true;
            }
        }

        return false;
    }
}
