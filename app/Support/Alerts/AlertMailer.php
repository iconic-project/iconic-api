<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use App\Enums\AlertNotificationStatus;
use App\Enums\AlertSeverity;
use App\Enums\UserStatus;
use App\Mail\Alerts\AlertMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\User;
use App\Support\Automations\AutomationCatalogue;
use App\Support\Automations\AutomationGate;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class AlertMailer
{
    public function __construct(private readonly AutomationGate $gate) {}

    public function sendOutstanding(): void
    {
        Alert::query()
            ->where('severity', AlertSeverity::Critical)
            ->whereNull('resolved_at')
            ->whereNull('emailed_at')
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $this->send($alert);
            });
    }

    private function send(Alert $alert): void
    {
        $alert->refresh();

        if ($alert->resolved_at !== null || $alert->emailed_at !== null || $alert->severity !== AlertSeverity::Critical) {
            return;
        }

        $existing = $alert->notifications()->get();

        if ($existing->isEmpty()) {
            foreach ($this->audience($alert) as $user) {
                $this->attempt($alert, $user, null);
            }
        } else {
            foreach ($existing as $notification) {
                if ($notification->status !== AlertNotificationStatus::Failed || $notification->attempts !== 1) {
                    continue;
                }

                $alert->refresh();

                if ($alert->resolved_at !== null) {
                    return;
                }

                $notification->loadMissing('user');
                $this->attempt($alert, $notification->user, $notification);
            }
        }

        $this->markEmailed($alert);
    }

    /**
     * @return list<User>
     */
    private function audience(Alert $alert): array
    {
        $definition = AlertRegistry::get($alert->kind);
        $users = [];

        User::query()
            ->where('status', UserStatus::Active)
            ->whereNull('disabled_at')
            ->with('role')
            ->orderBy('id')
            ->each(function (User $user) use ($definition, &$users): void {
                foreach ($definition->audience as $permission) {
                    if ($user->hasPermission($permission)) {
                        $users[] = $user;

                        return;
                    }
                }
            });

        return $users;
    }

    private function attempt(Alert $alert, User $user, ?AlertNotification $existing): void
    {
        $key = AutomationCatalogue::alertKey($alert->kind);

        if (! $this->gate->allows($key)) {
            $this->gate->recordSkip($alert, $key);

            return;
        }

        $attempts = $existing instanceof AlertNotification ? 2 : 1;
        $url = rtrim((string) config('iconic.panel_url'), '/').AlertSubject::href($alert);

        try {
            Mail::to($user->email)->send(new AlertMail($alert->title, $alert->sentence, $url));
            $this->store($alert, $user, $existing, AlertNotificationStatus::Sent, null, now(), $attempts);
        } catch (Throwable $exception) {
            $this->store($alert, $user, $existing, AlertNotificationStatus::Failed, $exception->getMessage(), null, $attempts);
        }
    }

    private function store(
        Alert $alert,
        User $user,
        ?AlertNotification $existing,
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

        if ($existing instanceof AlertNotification) {
            $existing->forceFill($attributes)->save();

            return;
        }

        AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function markEmailed(Alert $alert): void
    {
        $rows = $alert->notifications()->get();
        $done = $rows->every(fn (AlertNotification $row): bool => $row->status === AlertNotificationStatus::Sent || $row->attempts >= 2);

        if ($done) {
            $alert->forceFill(['emailed_at' => now()])->save();
        }
    }
}
