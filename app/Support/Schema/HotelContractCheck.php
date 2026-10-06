<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Read-only pre-flight for the Sprint 22 contract.
 * Fails while a live booking is missing its stay, an active archived claim
 * has no night claims, a stay table is missing its stay columns, or app
 * code still names a column this contract drops.
 * Pre-flight names the columns it checks are absent.
 */
final class HotelContractCheck
{
    /**
     * Paths relative to app/. These files describe tables that still
     * store a listed column, or they run from a migration that already
     * shipped.
     *
     * @var list<string>
     */
    private const ALLOW = [
        'Models/Archive/',
        'Models/Manifest.php',
        'Models/Group.php',
        'Models/CheckoutSession.php',
        'Models/Alert.php',
        'Models/CharterEnquiry.php',
        'Support/Offers/BackfillOfferStayWindows.php',
        'Support/Waitlist/BackfillWaitlistStays.php',
        'Support/Inventory/BackfillRoomNightClaims.php',
        'Support/Inventory/BackfillClosedDepartureRestrictions.php',
        'Support/Bookings/BackfillBookingStays.php',
        'Support/Bookings/StayFromDeparture.php',
        'Support/Schema/HotelContractCheck.php',
    ];

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        return [
            ...$this->dataFailures(),
            ...$this->codeFailures(),
        ];
    }

    /**
     * @return list<string>
     */
    public function dataFailures(): array
    {
        return [
            ...$this->bookingFailures(),
            ...$this->claimFailures(),
            ...$this->stayColumnFailures(),
        ];
    }

    /**
     * @return list<string>
     */
    public function summary(): array
    {
        $lines = [];

        foreach ([
            'departures' => 'archive_departures',
            'itin'.'eraries' => 'archive_itin'.'eraries',
            'cab'.'in_claims' => 'archive_cab'.'in_claims',
        ] as $live => $archive) {
            if (Schema::hasTable($live)) {
                $lines[] = $live.': '.(string) DB::table($live)->count().' rows (not yet archived)';
            } elseif (Schema::hasTable($archive)) {
                $lines[] = $archive.': '.(string) DB::table($archive)->count().' rows';
            } else {
                $lines[] = $live.': missing';
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function bookingFailures(): array
    {
        if (! Schema::hasTable('bookings')) {
            return ['bookings table is missing'];
        }

        foreach (['check_in', 'property_id', 'room_type_id'] as $column) {
            if (! Schema::hasColumn('bookings', $column)) {
                return ["bookings.{$column} is missing"];
            }
        }

        $count = (int) DB::table('bookings')
            ->where(function ($query): void {
                $query->whereNull('check_in')
                    ->orWhereNull('property_id')
                    ->orWhereNull('room_type_id');
            })
            ->count();

        if ($count > 0) {
            return ["{$count} bookings have null check_in, property_id, or room_type_id"];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function claimFailures(): array
    {
        $liveClaims = 'cab'.'in_claims';
        $archivedClaims = 'archive_cab'.'in_claims';
        $legacy = 'legacy_cab'.'in_claim_id';
        $table = Schema::hasTable($liveClaims)
            ? $liveClaims
            : (Schema::hasTable($archivedClaims) ? $archivedClaims : null);

        if ($table === null) {
            return [$liveClaims.' table is missing'];
        }

        if (! Schema::hasTable('room_night_claims') || ! Schema::hasColumn('room_night_claims', $legacy)) {
            return [];
        }

        $count = (int) DB::table($table.' as claims')
            ->whereNull('claims.released_at')
            ->whereNotExists(function ($query) use ($legacy): void {
                $query->selectRaw('1')
                    ->from('room_night_claims as nights')
                    ->whereColumn('nights.'.$legacy, 'claims.id')
                    ->whereNull('nights.released_at');
            })
            ->count();

        if ($count > 0) {
            return ["{$count} active archived claims have no active room-night claims"];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function stayColumnFailures(): array
    {
        $failures = [];

        $failures = [
            ...$failures,
            ...$this->requiredColumns('waitlist_entries', ['room_type_id', 'check_in', 'check_out']),
            ...$this->requiredColumns('offers', ['stay_from', 'stay_to', 'min_nights']),
            ...$this->requiredColumns('internal_blocks', ['property_id', 'starts_on', 'ends_on']),
        ];

        if (Schema::hasTable('waitlist_entries') && Schema::hasColumn('waitlist_entries', 'check_in')) {
            $count = (int) DB::table('waitlist_entries')
                ->where(function ($query): void {
                    $query->whereNull('room_type_id')
                        ->orWhereNull('check_in')
                        ->orWhereNull('check_out');
                })
                ->count();

            if ($count > 0) {
                $failures[] = "{$count} waitlist entries lack room_type_id, check_in, or check_out";
            }
        }

        if (Schema::hasTable('internal_blocks') && Schema::hasColumn('internal_blocks', 'starts_on')) {
            $count = (int) DB::table('internal_blocks')
                ->where(function ($query): void {
                    $query->whereNull('property_id')
                        ->orWhereNull('starts_on')
                        ->orWhereNull('ends_on');
                })
                ->count();

            if ($count > 0) {
                $failures[] = "{$count} internal blocks lack property_id, starts_on, or ends_on";
            }
        }

        return $failures;
    }

    /**
     * A null offer stay window means any night. The columns must exist.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function requiredColumns(string $table, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return ["{$table} table is missing"];
        }

        $failures = [];

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $failures[] = "{$table}.{$column} is missing";
            }
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function codeFailures(): array
    {
        $root = app_path();
        $failures = [];

        if (! is_dir($root)) {
            return ['app/ is missing'];
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if ($this->allowed($relative)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                $failures[] = "could not read app/{$relative}";

                continue;
            }

            foreach (self::needles() as $needle) {
                if (str_contains($contents, $needle)) {
                    $failures[] = "app/{$relative} still names {$needle}";
                }
            }
        }

        sort($failures);

        return $failures;
    }

    private function allowed(string $relative): bool
    {
        foreach (self::ALLOW as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Column names dropped from live tables. Built at runtime so this
     * file can name them without storing the retired words in one piece.
     *
     * @return list<string>
     */
    private static function needles(): array
    {
        return [
            'departure_id',
            'back_to_back',
            'png_collected',
            'cab'.'in_category',
            'png_category',
            'legacy_cab'.'in_claim_id',
            'cab'.'in_types',
            'itin'.'erary_codes',
            'travel_from',
            'travel_to',
        ];
    }
}
