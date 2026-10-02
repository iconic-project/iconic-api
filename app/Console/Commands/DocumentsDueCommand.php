<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Documents\SendPaymentRequest;
use App\Actions\GuestExperience\SendQuestionnaires;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DocumentKind;
use App\Models\Booking;
use App\Models\Delivery;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Documents\DeliveryKey;
use App\Support\Documents\IssueOnce;
use App\Support\Documents\Snapshots\DocumentFacts;
use App\Support\GuestExperience\QuestionnairePlan;
use Illuminate\Console\Command;

final class DocumentsDueCommand extends Command
{
    protected $signature = 'iconic:documents-due {--dry-run : List what would be sent and write nothing}';

    protected $description = 'Send due balance reminders, pre-trip itineraries, preference questionnaires and transfer vouchers (J7)';

    public function handle(
        CurrentConfig $config,
        SendPaymentRequest $reminders,
        IssueOnce $issueOnce,
        QuestionnairePlan $questionnaires,
        SendQuestionnaires $sendQuestionnaires,
    ): int {
        $today = BusinessTime::now()->toDateString();
        $dry = (bool) $this->option('dry-run');
        $rules = $config->businessRules();
        $reminderDays = $rules->payments->balanceReminderDays;
        $pretripDays = $rules->documents->pretripDaysBefore;
        $voucherDays = $rules->documents->voucherDaysBefore;
        $sent = 0;

        Booking::query()
            ->with(['departure', 'extras', 'contact', 'group.coordinator', 'agency', 'guests', 'documents.deliveries'])
            ->whereIn('status', [
                BookingStatus::Confirmed,
                BookingStatus::OnHoldAgency,
                BookingStatus::FullyPaid,
                BookingStatus::OnBoard,
                BookingStatus::Completed,
            ])
            ->orderBy('id')
            ->each(function (Booking $booking) use (
                $today,
                $dry,
                $reminderDays,
                $pretripDays,
                $voucherDays,
                $reminders,
                $issueOnce,
                $questionnaires,
                $sendQuestionnaires,
                &$sent,
            ): void {
                $sent += $this->reminders($booking, $today, $reminderDays, $dry, $reminders);
                $sent += $this->scheduledDocument(
                    $booking,
                    $today,
                    $pretripDays,
                    DocumentKind::Pretrip,
                    $dry,
                    $issueOnce,
                );
                $sent += $this->questionnaires($booking, $today, $pretripDays, $dry, $questionnaires, $sendQuestionnaires);
                $sent += $this->scheduledDocument(
                    $booking,
                    $today,
                    $voucherDays,
                    DocumentKind::Voucher,
                    $dry,
                    $issueOnce,
                );
            });

        $this->info(($dry ? 'Would send ' : 'Sent ').$sent.' document(s).');

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $slots
     */
    private function reminders(
        Booking $booking,
        string $today,
        array $slots,
        bool $dry,
        SendPaymentRequest $reminders,
    ): int {
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::OnHoldAgency], true)) {
            return 0;
        }

        if ($booking->cruiseOutstanding() <= 0) {
            return 0;
        }

        $due = $booking->balanceDueDate()->toDateString();

        if ($due <= $today) {
            return 0;
        }

        $eligible = [];

        foreach ($slots as $days) {
            $sendDate = BusinessTime::calendarDay($due)->subDays($days)->toDateString();

            if ($sendDate <= $today) {
                $eligible[] = $days;
            }
        }

        if ($eligible === []) {
            return 0;
        }

        $n = min($eligible);
        $key = DeliveryKey::forReminder($booking->id, $due, $n);

        if (Delivery::query()->where('idempotency_key', $key)->exists()) {
            return 0;
        }

        $label = 'reminder '.$n.'d for '.$booking->displayReference();

        if ($dry) {
            $this->line($label);

            return 1;
        }

        $reminders->handle($booking, DeliveryKind::Reminder, reminderDays: $n, system: true);

        return 1;
    }

    private function scheduledDocument(
        Booking $booking,
        string $today,
        int $daysBefore,
        DocumentKind $kind,
        bool $dry,
        IssueOnce $issueOnce,
    ): int {
        if (! $booking->status->isConfirmedOrLater()) {
            return 0;
        }

        if ($kind === DocumentKind::Voucher && ! DocumentFacts::bookingHasTransferVoucher($booking)) {
            return 0;
        }

        $departure = $booking->departure->date->toDateString();
        $sendDate = BusinessTime::calendarDay($departure)->subDays($daysBefore)->toDateString();

        if ($sendDate > $today) {
            return 0;
        }

        $key = $kind === DocumentKind::Pretrip
            ? DeliveryKey::forPretrip($booking->id, $departure)
            : DeliveryKey::forVoucher($booking->id, $departure);

        $existing = $booking->documents
            ->where('kind', $kind)
            ->sortByDesc('version')
            ->first();

        if ($existing?->deliveries?->isNotEmpty() ?? false) {
            return 0;
        }

        if (Delivery::query()->where('idempotency_key', $key)->exists()) {
            return 0;
        }

        $label = strtolower($kind->value).' for '.$booking->displayReference();

        if ($dry) {
            $this->line($label);

            return 1;
        }

        $issueOnce->handle($booking, $kind, $key);

        return 1;
    }

    private function questionnaires(
        Booking $booking,
        string $today,
        int $daysBefore,
        bool $dry,
        QuestionnairePlan $plan,
        SendQuestionnaires $send,
    ): int {
        if (! $booking->status->isConfirmedOrLater()) {
            return 0;
        }

        $departure = $booking->departure->date->toDateString();
        $sendDate = BusinessTime::calendarDay($departure)->subDays($daysBefore)->toDateString();

        if ($sendDate > $today) {
            return 0;
        }

        $pending = [];

        foreach ($plan->dispatches($booking) as $dispatch) {
            if (Delivery::query()->where('idempotency_key', $dispatch->key)->exists()) {
                continue;
            }

            $pending[] = $dispatch;
        }

        if ($pending === []) {
            return 0;
        }

        if ($dry) {
            foreach ($pending as $dispatch) {
                $this->line($dispatch->label);
            }

            return count($pending);
        }

        return $send->handle($booking);
    }
}
