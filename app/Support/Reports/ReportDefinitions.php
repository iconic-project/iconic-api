<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\Permission;
use App\Enums\ReportFormat;
use InvalidArgumentException;

final class ReportDefinitions
{
    /**
     * @return list<ReportDefinition>
     */
    public static function all(): array
    {
        $csv = [ReportFormat::Csv, ReportFormat::Xlsx];
        $pdf = [ReportFormat::Pdf];

        return [
            new ReportDefinition('payments-received', 'Payments received', 'Settled deposit and balance receipts in the window, by payment date. Bookings and money only.', Permission::PaymentsRecord, $csv),
            new ReportDefinition('commissions-payable', 'Commissions payable', 'Approved-agency commissions that the accrual status marks payable.', Permission::PaymentsRecord, $csv),
            new ReportDefinition('gateway-reconciliation', 'Gateway reconciliation', 'Card payments that carry a gateway id. The gateway id is the only external identifier.', Permission::PaymentsRecord, $csv),
            new ReportDefinition('commercial-summary', 'Commercial summary', 'The commercial metrics for the window: occupancy, RevPAB, ADR, lead time, channel mix, nationality counts, NPS, commissions and cash.', Permission::PanelRms, $pdf),
            new ReportDefinition('occupancy-revenue', 'Occupancy and revenue by night', 'Room-nights sold and available, occupancy, room revenue, ADR and RevPAR for each night. Blocked room-nights are not available. A stay that crosses the window splits by night.', Permission::PanelRms, $csv),
            new ReportDefinition('pace', 'Pace', 'Room-nights on the books for each night, pickup in the configured window, and the same night last year.', Permission::PanelRms, $csv),
            new ReportDefinition('arrivals-forecast', 'Arrivals forecast', 'Sold stays checking in during the window. Room type, nights and channel. No guest name.', Permission::PanelRms, $csv),
            new ReportDefinition('pipeline-summary', 'Pipeline summary', 'Collected, pending, overdue and scheduled cash for the window, from Payments & Revenue.', Permission::PanelRms, $pdf),
            new ReportDefinition('agency-report', 'Agency report', 'Commission blocked, earned, payable and paid by approved agency. Company name and reference only.', Permission::AgenciesManage, $csv),
        ];
    }

    public static function find(string $key): ?ReportDefinition
    {
        foreach ([...self::all(), ...self::retired()] as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    public static function inCatalogue(string $key): bool
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Departure-grain reports. Already generated files stay downloadable.
     * New runs are not offered.
     *
     * @return list<ReportDefinition>
     */
    public static function retired(): array
    {
        $csv = [ReportFormat::Csv, ReportFormat::Xlsx];

        return [
            new ReportDefinition('overdue', 'Overdue balances', 'Bookings overdue on the Galápagos date, whose departure falls in the window. Cruise balance only.', Permission::PaymentsRecord, $csv),
            new ReportDefinition('forecast-30-day', '30-day forecast', 'Open cruise balances whose due date falls in the window.', Permission::PaymentsRecord, $csv),
            new ReportDefinition('revenue-monthly', 'Monthly revenue', 'Cruise revenue of sold bookings by departure month. Extras and fees are excluded.', Permission::PaymentsRecord, $csv),
            new ReportDefinition('occupancy', 'Occupancy', 'Sold and sellable berths by departure, from the metrics layer. A charter counts as the whole property.', Permission::PanelRms, $csv),
        ];
    }

    public static function get(string $key): ReportDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        throw new InvalidArgumentException('Unknown report ['.$key.'].');
    }
}
