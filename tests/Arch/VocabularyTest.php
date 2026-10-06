<?php

declare(strict_types=1);

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Retired inventory words. departure / png / ppdo / embark may remain
 * where the reason on the allowlist says so. yacht, cabin, itinerary,
 * voyage and cruise may remain only under the archive models.
 */
test('yacht cabin itinerary voyage and cruise appear only on archive models', function (): void {
    $hits = vocabularyFiles('/yacht|cabin|itinerar|voyage|cruise/i');

    $outside = array_values(array_filter(
        $hits,
        fn (string $path): bool => ! str_starts_with($path, 'app/Models/Archive/')
            && ! str_starts_with($path, 'app/Enums/Archive/')
            && $path !== 'app/Support/Content/CopyPublishedItineraryContent.php',
    ));

    expect($outside)->toBe([]);
});

test('departure png ppdo and embark outside the allowlist are gone', function (): void {
    $hits = vocabularyFiles('/departure(?!s? time)|yacht|cabin|itinerar|voyage|cruise|\bpng\b|ppdo|embark/i');
    $unexpected = [];

    foreach ($hits as $path) {
        if (vocabularyAllowed($path) !== null) {
            continue;
        }

        $unexpected[] = $path;
    }

    expect($unexpected)->toBe([]);
});

/**
 * @return list<string>
 */
function vocabularyFiles(string $pattern): array
{
    $root = dirname(__DIR__, 2);
    $hits = [];

    foreach (['app', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents) || preg_match($pattern, $contents) !== 1) {
                continue;
            }

            $hits[] = $directory.'/'.substr($file->getPathname(), strlen($root.'/'.$directory) + 1);
        }
    }

    sort($hits);

    return $hits;
}

function vocabularyAllowed(string $path): ?string
{
    if (str_starts_with($path, 'app/Models/Archive/') || str_starts_with($path, 'app/Enums/Archive/')) {
        return 'archive models keep the retired names';
    }

    if (str_starts_with($path, 'app/Support/FrontDesk/') || $path === 'app/Http/Controllers/Rms/FrontDeskController.php' || $path === 'app/Http/Resources/Rms/FrontDeskListsResource.php') {
        return 'departures means guests leaving (09 §2)';
    }

    if (str_starts_with($path, 'app/Support/Bookings/Backfill')
        || $path === 'app/Support/Bookings/StayFromDeparture.php'
        || str_starts_with($path, 'app/Support/Inventory/Backfill')
        || $path === 'app/Support/Offers/BackfillOfferStayWindows.php'
        || $path === 'app/Support/Waitlist/BackfillWaitlistStays.php'
    ) {
        return 'reads a dropped column during expand-migrate backfill';
    }

    if ($path === 'app/Support/Schema/HotelContractCheck.php') {
        return 'pre-flight names the columns it checks are absent';
    }

    if ($path === 'app/Support/Content/CopyPublishedItineraryContent.php') {
        return 'merged migration 2026_10_05_210001 resolves this class by name';
    }

    $reasons = [
        // H7: a night can be closed to departure (the guest cannot leave).
        'app/Actions/Restrictions/SetStayRestrictions.php' => 'closed_to_departure is the guest-leaving restriction (09 H7)',
        'app/Http/Requests/Rms/SetStayRestrictionsRequest.php' => 'closed_to_departure is the guest-leaving restriction (09 H7)',
        'app/Http/Resources/Portal/PortalCalendarResource.php' => 'closed_to_departure is the guest-leaving restriction (09 H7)',
        'app/Http/Resources/Rms/StayRestrictionResource.php' => 'closed_to_departure is the guest-leaving restriction (09 H7)',
        'app/Models/StayRestriction.php' => 'closed_to_departure is the guest-leaving restriction (09 H7)',
        'app/Services/Engine/EngineCalendar.php' => 'closed_to_departure is the guest-leaving restriction (09 H7)',
        'app/Services/Inventory/Bookability.php' => 'closed_to_departure is the guest-leaving restriction (09 H7)',
        'app/Services/Inventory/Restrictions.php' => 'closed_to_departure is the guest-leaving restriction (09 H7)',
        // Early departure is a guest leaving before the planned check-out.
        'app/Actions/Bookings/ModifyStay.php' => 'early departure is a guest leaving before the planned check-out',
        'app/Actions/Bookings/CheckOutBooking.php' => 'early departure is a guest leaving before the planned check-out',
        'app/Console/Commands/NightAuditCommand.php' => 'late departures are guests who have not checked out',
        'app/Support/Operations/NightAudit.php' => 'late departures are guests who have not checked out',
        // png as an image type, not the retired fee.
        'app/Actions/SalesMaterials/UploadSalesMaterial.php' => 'png is the image media type',
        'app/Http/Requests/Rms/StoreContentImageRequest.php' => 'png is the image media type',
        'app/Rules/SalesMaterialUpload.php' => 'png is the image media type',
        'app/Support/SalesMaterials/MaterialFile.php' => 'png is the image media type',
        // Stored behavioural event detail still names the check-in date.
        'app/Support/Crm/BehaviouralEventDetail.php' => 'event detail labels the stay check-in',
        // Journey anchors stored on steps.
        'app/Models/JourneyStep.php' => 'stored journey anchor departure',
        'app/Support/Journeys/JourneyClock.php' => 'stored journey anchor departure',
        'app/Support/Journeys/JourneyEngine.php' => 'stored journey facts departed and embarked',
        // Response keys that are the stay check-in. Task 05 updates clients.
        'app/Http/Resources/Crm/CampaignBookingPageResource.php' => 'departure_date response key is the stay check-in',
        'app/Http/Resources/Crm/ContactBookingResource.php' => 'departure_date response key is the stay check-in',
        'app/Http/Resources/Crm/PipelineResource.php' => 'departure_date response key is the stay check-in',
        'app/Http/Resources/Portal/PortalBookingResource.php' => 'departure_date response key is the stay check-in',
        'app/Http/Resources/Rms/AgencyPortalPreviewResource.php' => 'departure_date response key is the stay check-in',
        'app/Http/Resources/Rms/GuestResource.php' => 'age_at_departure is the guest age on the check-in date',
        'app/Support/Agencies/PortalPreview.php' => 'departure_date response key is the stay check-in',
        'app/Support/Crm/CampaignMeasures.php' => 'departure_date response key is the stay check-in',
        'app/Support/Crm/ContactTimeline.php' => 'departure_date response key is the stay check-in',
        'app/Support/Crm/DealDrawer.php' => 'departure_date response key is the stay check-in',
        'app/Support/Crm/FieldOwnership.php' => 'departure_date response key is the stay check-in',
        'app/Support/Crm/PipelineBoard.php' => 'departure_date response key is the stay check-in',
        'app/Support/Documents/DeliveryKey.php' => 'delivery key uses the check-in date',
        'app/Support/Metrics/CommercialMetrics.php' => 'metrics label the stay check-in as the departure date',
        'app/Support/Metrics/MetricCatalogue.php' => 'metrics label the stay check-in as the departure date',
        'app/Support/Reports/ReportDefinitions.php' => 'report copy names the stay check-in',
        'app/Support/Reports/ReportQueries.php' => 'report columns name the stay check-in',
        'app/Models/Booking.php' => 'stay query comments name the old check-in column',
        'app/Support/Bookings/BookingMutationLock.php' => 'lock comment names the stay rows being held',
        'app/Support/Bookings/Transitions.php' => 'status copy names a guest leaving',
        'app/Support/BusinessHours.php' => 'copy names days before the guest leaves',
        'app/Support/Config/Documents/RegistrationRules.php' => 'copy names the guest leaving date',
        'app/Support/Automations/AutomationCatalogue.php' => 'automation copy names the guest leaving date',
        'app/Support/Payments/InsertLedgerRow.php' => 'lock comment names the stay rows being held',
        'app/Support/Schedule/JobCatalogue.php' => 'schedule copy names open stays inside the window',
    ];

    return $reasons[$path] ?? null;
}
