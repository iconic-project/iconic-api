<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestPreference;
use App\Models\Manifest;
use App\Models\ReportRun;
use App\Models\SubjectRequest;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\History\History;
use App\Support\Retention\RetentionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class RetentionCommand extends Command
{
    protected $signature = 'iconic:retention {--dry-run : Print counts and write nothing}';

    protected $description = 'Anonymise passport data and purge medical notes on the published retention schedule (B4)';

    public function handle(CurrentConfig $config): int
    {
        $rules = $config->businessRules()->retention;
        $months = $rules->passportMonthsAfterCheckOut;
        $days = $rules->medicalDaysAfterCheckOut;
        $today = BusinessTime::now()->toDateString();
        $dry = (bool) $this->option('dry-run');

        $passportGuests = 0;
        $noteGuests = 0;
        $preferenceRows = 0;
        $changedBookings = 0;

        $this->candidates()->chunkById(100, function ($bookings) use (
            $months,
            $days,
            $today,
            $dry,
            &$passportGuests,
            &$noteGuests,
            &$preferenceRows,
            &$changedBookings,
        ): void {
            foreach ($bookings as $booking) {
                $booking->loadMissing('guests');
                $returnDate = $booking->stay()->checkOut();
                $purgePassports = RetentionWindow::elapsed(
                    RetentionWindow::passportEndsOn($returnDate, $months),
                    $today,
                );
                $purgeNotes = RetentionWindow::elapsed(
                    RetentionWindow::notesEndOn($returnDate, $days),
                    $today,
                );

                if (! $purgePassports && ! $purgeNotes) {
                    continue;
                }

                $passports = 0;
                $notes = 0;
                $preferences = $purgeNotes ? $this->unpurgedPreferences($booking) : 0;

                foreach ($booking->guests as $guest) {
                    if ($purgePassports && $this->hasPassportData($guest)) {
                        $passports++;
                    }

                    if ($purgeNotes && $this->hasNotes($guest)) {
                        $notes++;
                    }
                }

                if ($passports === 0 && $notes === 0 && $preferences === 0) {
                    continue;
                }

                $passportGuests += $passports;
                $noteGuests += $notes;
                $preferenceRows += $preferences;
                $changedBookings++;

                if ($dry) {
                    continue;
                }

                DB::transaction(function () use ($booking, $purgePassports, $purgeNotes, $passports, $notes, $preferences, $months, $days): void {
                    foreach ($booking->guests as $guest) {
                        $dirty = false;

                        if ($purgePassports && $this->hasPassportData($guest)) {
                            $guest->passport_no = null;
                            $guest->passport_expiry = null;
                            $dirty = true;
                        }

                        if ($purgeNotes && $this->hasNotes($guest)) {
                            $guest->medical_note = null;
                            $guest->dietary_note = null;
                            $guest->accessibility_note = null;
                            $dirty = true;
                        }

                        if ($dirty) {
                            $guest->save();
                        }

                        if ($purgeNotes) {
                            $this->purgePreferences($guest);
                        }
                    }

                    History::record($booking, 'retention.applied', after: [
                        'passports_anonymised' => $passports,
                        'notes_purged' => $notes,
                        'preferences_purged' => $preferences,
                    ], extraContext: [
                        'what' => $this->historyWhat($passports, $notes, $preferences, $months, $days),
                    ], system: true);
                });
            }
        });

        $exports = $this->expireExports($config, $dry);
        $manifests = $this->purgeManifests($rules->passportMonthsAfterCheckOut, $rules->medicalDaysAfterCheckOut, $today, $dry);
        $reports = $this->purgeReports($config->businessRules()->reports->retentionDays, $dry);

        $verb = $dry ? 'Would change' : 'Changed';
        $this->info($verb.' '.$changedBookings.' booking(s): '.$passportGuests.' passport(s), '.$noteGuests.' note set(s), '.$preferenceRows.' preference row(s).');
        $this->info(($dry ? 'Would delete ' : 'Deleted ').$exports.' access export(s).');
        $this->info(($dry ? 'Would purge ' : 'Purged ').$manifests['captain'].' CAPTAIN file(s) and '.$manifests['dpng'].' DPNG file(s).');
        $this->info(($dry ? 'Would purge ' : 'Purged ').$reports.' report file set(s).');

        return self::SUCCESS;
    }

    /**
     * @return Builder<Booking>
     */
    private function candidates(): Builder
    {
        return Booking::query()
            ->withTrashed()
            ->whereHas('guests', function (Builder $query): void {
                $query->where(function (Builder $inner): void {
                    $inner->whereNotNull('passport_no')
                        ->orWhereNotNull('passport_expiry')
                        ->orWhereNotNull('medical_note')
                        ->orWhereNotNull('dietary_note')
                        ->orWhereNotNull('accessibility_note');
                })->orWhereHas('preferences', function (Builder $preferences): void {
                    $preferences->whereNull('purged_at');
                });
            })
            ->orderBy('id');
    }

    private function hasPassportData(Guest $guest): bool
    {
        return $guest->passport_no !== null || $guest->passport_expiry !== null;
    }

    private function hasNotes(Guest $guest): bool
    {
        return $guest->medical_note !== null
            || $guest->dietary_note !== null
            || $guest->accessibility_note !== null;
    }

    private function historyWhat(int $passports, int $notes, int $preferences, int $months, int $days): string
    {
        $parts = [];

        if ($passports > 0) {
            $parts[] = 'passport data anonymised for '.$passports.' guests ('.$months.' months after check-out, B4)';
        }

        if ($notes > 0) {
            $parts[] = 'medical notes purged for '.$notes.' guests ('.$days.' days after check-out, B4)';
        }

        if ($preferences > 0) {
            $parts[] = 'guest preferences purged for '.$preferences.' rows ('.$days.' days after check-out, B4)';
        }

        return 'Retention — '.implode('; ', $parts);
    }

    private function unpurgedPreferences(Booking $booking): int
    {
        return GuestPreference::query()
            ->whereIn('guest_id', $booking->guests->pluck('id'))
            ->whereNull('purged_at')
            ->count();
    }

    private function purgePreferences(Guest $guest): void
    {
        GuestPreference::query()
            ->where('guest_id', $guest->id)
            ->whereNull('purged_at')
            ->orderBy('id')
            ->each(function (GuestPreference $preference): void {
                $preference->answers = [];
                $preference->accessibility = null;
                $preference->emergency_contact = null;
                $preference->purged_at = now();
                $preference->save();
            });
    }

    private function expireExports(CurrentConfig $config, bool $dry): int
    {
        $days = $config->businessRules()->privacy->requestSlaDays;
        $cutoff = now()->subDays($days);
        $count = 0;

        SubjectRequest::query()
            ->whereNotNull('export_path')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $cutoff)
            ->orderBy('id')
            ->each(function (SubjectRequest $request) use ($dry, &$count): void {
                $count++;

                if ($dry || $request->export_path === null) {
                    return;
                }

                Storage::disk('local')->delete($request->export_path);
                $request->forceFill(['export_path' => null])->save();
            });

        return $count;
    }

    /**
     * @return array{captain: int, dpng: int}
     */
    private function purgeManifests(int $months, int $days, string $today, bool $dry): array
    {
        $captain = 0;
        $dpng = 0;

        Manifest::query()
            ->whereNull('purged_at')
            ->orderBy('id')
            ->each(function (Manifest $manifest) use ($months, $days, $today, $dry, &$captain, &$dpng): void {
                $returnDate = CarbonImmutable::parse($manifest->generated_at);
                $end = $manifest->kind === 'CAPTAIN'
                    ? RetentionWindow::notesEndOn($returnDate, $days)
                    : RetentionWindow::passportEndsOn($returnDate, $months);

                if (! RetentionWindow::elapsed($end, $today)) {
                    return;
                }

                $paths = array_values(array_filter(
                    [$manifest->pdf_path, $manifest->csv_path, $manifest->xlsx_path],
                    fn (?string $path): bool => is_string($path) && $path !== '',
                ));

                if ($manifest->kind === 'CAPTAIN') {
                    $captain += count($paths);
                } else {
                    $dpng += count($paths);
                }

                if ($dry) {
                    return;
                }

                foreach ($paths as $path) {
                    Storage::disk('manifests')->delete($path);
                }

                $manifest->forceFill([
                    'pdf_path' => null,
                    'csv_path' => null,
                    'xlsx_path' => null,
                    'purged_at' => now(),
                ])->save();
            });

        return ['captain' => $captain, 'dpng' => $dpng];
    }

    private function purgeReports(int $days, bool $dry): int
    {
        $cutoff = BusinessTime::now()->subDays($days);
        $count = 0;

        ReportRun::query()
            ->whereNull('purged_at')
            ->whereNotNull('generated_at')
            ->where('generated_at', '<=', $cutoff)
            ->orderBy('id')
            ->each(function (ReportRun $run) use ($dry, &$count): void {
                $count++;

                if ($dry) {
                    return;
                }

                foreach (['csv_path', 'xlsx_path', 'pdf_path'] as $column) {
                    $path = $run->getAttribute($column);

                    if (is_string($path) && $path !== '') {
                        Storage::disk('reports')->delete($path);
                    }
                }

                $run->forceFill([
                    'csv_path' => null,
                    'xlsx_path' => null,
                    'pdf_path' => null,
                    'purged_at' => now(),
                ])->save();
            });

        return $count;
    }
}
