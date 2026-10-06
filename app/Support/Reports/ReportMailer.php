<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\AlertNotificationStatus;
use App\Enums\Permission;
use App\Enums\ReportFormat;
use App\Enums\UserStatus;
use App\Mail\Reports\ReportMail;
use App\Models\ReportRun;
use App\Models\ReportRunNotification;
use App\Models\User;
use App\Support\Automations\AutomationCatalogue;
use App\Support\Automations\AutomationGate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ReportMailer
{
    public function __construct(private readonly AutomationGate $gate) {}

    public function send(ReportRun $run): void
    {
        if (! $this->gate->allows(AutomationCatalogue::REPORT_EMAIL)) {
            $this->gate->recordSkip($run, AutomationCatalogue::REPORT_EMAIL);

            return;
        }

        $definition = ReportDefinitions::find($run->definition_key);

        if ($definition === null) {
            return;
        }

        $existing = $run->notifications()->get();

        if ($existing->isEmpty()) {
            foreach ($this->audience($definition->permission) as $user) {
                $this->attempt($run, $user, null);
            }

            return;
        }

        foreach ($existing as $notification) {
            if ($notification->status !== AlertNotificationStatus::Failed || $notification->attempts !== 1) {
                continue;
            }

            $notification->loadMissing('user');
            $this->attempt($run, $notification->user, $notification);
        }
    }

    /**
     * @return list<User>
     */
    private function audience(Permission $permission): array
    {
        $users = [];

        User::query()
            ->where('status', UserStatus::Active)
            ->whereNull('disabled_at')
            ->with('role')
            ->orderBy('id')
            ->each(function (User $user) use ($permission, &$users): void {
                if ($user->hasPermission($permission)) {
                    $users[] = $user;
                }
            });

        return $users;
    }

    private function attempt(ReportRun $run, User $user, ?ReportRunNotification $existing): void
    {
        $attempts = $existing instanceof ReportRunNotification ? 2 : 1;
        $definition = ReportDefinitions::find($run->definition_key) ?? ReportDefinitions::get($run->definition_key);
        $url = rtrim((string) config('iconic.panel_url'), '/').'/rms/reports/runs/'.$run->id;
        [$path, $name] = $this->attachment($run);

        try {
            Mail::to($user->email)->send(new ReportMail(
                $definition->title.' '.$run->window_from->toDateString().' to '.$run->window_to->toDateString(),
                $this->sentence($run),
                $url,
                $path,
                $name,
            ));
            $this->store($run, $user, $existing, AlertNotificationStatus::Sent, null, now(), $attempts);
        } catch (Throwable $exception) {
            $this->store($run, $user, $existing, AlertNotificationStatus::Failed, $exception->getMessage(), null, $attempts);
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function attachment(ReportRun $run): array
    {
        $limit = (int) config('iconic.report_attachment_bytes');

        foreach (ReportFormat::cases() as $format) {
            $path = $run->getAttribute($format->column());

            if (! is_string($path) || $path === '' || ! Storage::disk('reports')->exists($path)) {
                continue;
            }

            if (Storage::disk('reports')->size($path) > $limit) {
                return [null, null];
            }

            return [$path, $run->definition_key.'.'.$format->value];
        }

        return [null, null];
    }

    private function sentence(ReportRun $run): string
    {
        $definition = ReportDefinitions::find($run->definition_key) ?? ReportDefinitions::get($run->definition_key);

        return $definition->sentence.' '.$run->window_from->toDateString().' to '.$run->window_to->toDateString().'. '.$run->rows.' rows.';
    }

    private function store(
        ReportRun $run,
        User $user,
        ?ReportRunNotification $existing,
        AlertNotificationStatus $status,
        ?string $error,
        mixed $sentAt,
        int $attempts,
    ): void {
        $attributes = [
            'status' => $status,
            'error' => $error,
            'sent_at' => $sentAt,
            'attempts' => $attempts,
        ];

        if ($existing instanceof ReportRunNotification) {
            $existing->forceFill($attributes)->save();

            return;
        }

        ReportRunNotification::query()->create([
            'report_run_id' => $run->id,
            'user_id' => $user->id,
            ...$attributes,
        ]);
    }
}
