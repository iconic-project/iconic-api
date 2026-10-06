<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Actions\Reports\GenerateReport;
use App\Enums\AlertKind;
use App\Enums\ReportRunSource;
use App\Enums\ReportRunStatus;
use App\Models\Alert;
use App\Models\ReportRun;
use App\Models\ReportSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ReportDispatch
{
    public function __construct(
        private readonly ReportWindow $windows,
        private readonly GenerateReport $generate,
        private readonly ReportMailer $mailer,
        private readonly RaiseAlert $raiseAlert,
        private readonly ResolveAlert $resolveAlert,
    ) {}

    public function sendDue(): int
    {
        $sent = 0;

        ReportSubscription::query()
            ->where('active', true)
            ->orderBy('id')
            ->each(function (ReportSubscription $subscription) use (&$sent): void {
                if (! $this->windows->due($subscription)) {
                    return;
                }

                $run = $this->runForPeriod($subscription, $this->windows->period($subscription));

                if (! $run instanceof ReportRun) {
                    $run = $this->create($subscription, null, ReportRunSource::Schedule);
                    $sent++;
                }

                $this->finish($run);
            });

        return $sent;
    }

    public function runNow(ReportSubscription $subscription, User $actor): ReportRun
    {
        $period = $this->windows->period($subscription);
        $existing = $this->runForPeriod($subscription, $period);

        if ($existing instanceof ReportRun) {
            $this->finish($existing);

            return $existing->fresh() ?? $existing;
        }

        $run = $this->create($subscription, $actor, ReportRunSource::Manual);
        $this->finish($run);

        return $run->fresh() ?? $run;
    }

    private function create(ReportSubscription $subscription, ?User $actor, ReportRunSource $source): ReportRun
    {
        $window = $this->windows->resolve($subscription);
        $period = $this->windows->period($subscription);

        $run = DB::transaction(function () use ($subscription, $actor, $source, $window, $period): ReportRun {
            return ReportRun::query()->create([
                'definition_key' => $subscription->definition_key,
                'parameters' => [
                    'from' => $window->from,
                    'to' => $window->to,
                    'property' => null,
                    'channel' => null,
                    'agency' => null,
                    'window' => $subscription->parameters['window'] ?? null,
                    'period' => $period,
                    'source' => $source->value,
                ],
                'window_from' => $window->from,
                'window_to' => $window->to,
                'requested_by' => $actor?->id,
                'subscription_id' => $subscription->id,
                'status' => ReportRunStatus::Queued,
                'rows' => 0,
            ]);
        });

        $this->generate->handle($run);

        return $run->fresh() ?? $run;
    }

    private function finish(ReportRun $run): void
    {
        $run->refresh();

        if ($run->status === ReportRunStatus::Failed) {
            $this->raiseAlert->handle(
                AlertKind::ReportFailed,
                'report:'.$run->definition_key,
                'Report failed',
                $run->definition_key.' failed: '.($run->error ?? 'unknown error'),
            );

            return;
        }

        if ($run->status !== ReportRunStatus::Ready) {
            return;
        }

        Alert::query()
            ->where('kind', AlertKind::ReportFailed)
            ->where('base_key', 'report:'.$run->definition_key)
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->each(function (Alert $alert) use ($run): void {
                $this->resolveAlert->handle($alert, 'A later '.$run->definition_key.' run succeeded.');
            });

        $this->mailer->send($run);
    }

    private function runForPeriod(ReportSubscription $subscription, string $period): ?ReportRun
    {
        $run = ReportRun::query()
            ->where('subscription_id', $subscription->id)
            ->where('parameters->period', $period)
            ->first();

        return $run instanceof ReportRun ? $run : null;
    }
}
