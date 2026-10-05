<?php

declare(strict_types=1);

use App\Models\ChangeHistory;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Models\ReferenceSequence;
use App\Models\StripeEvent;
use App\Services\Stripe\StripeSdkGateway;
use App\Support\History\History;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

arch('engine controllers do not use RMS or CRM resources')
    ->expect('App\Http\Controllers\Engine')
    ->not->toUse([
        'App\Http\Resources\Rms',
        'App\Http\Resources\Crm',
        'App\Http\Controllers\Rms',
        'App\Http\Controllers\Crm',
    ]);

arch('crm controllers do not use money or booking write paths')
    ->expect('App\Http\Controllers\Crm')
    ->not->toUse([
        'App\Actions\Bookings',
        'App\Actions\Payments',
        'App\Actions\Refunds',
        'App\Actions\Commissions',
        'App\Actions\Agencies',
        'App\Actions\Documents',
        'App\Actions\Guests',
        'App\Actions\Extras',
        'App\Services\Pricing',
        'App\Actions\Privacy',
        'App\Actions\Offers',
        'App\Actions\Manifests',
        'App\Support\Manifests',
        'App\Actions\GuestExperience',
        'App\Support\GuestExperience',
    ]);

arch('privacy controllers are not used by the crm')
    ->expect('App\Http\Controllers\Crm')
    ->not->toUse('App\Http\Controllers\Privacy');

arch('portal controllers do not use staff actions or staff auth')
    ->expect('App\Http\Controllers\Portal')
    ->not->toUse(['App\Actions\Auth', 'App\Http\Middleware\EnsureUserIsActive']);

arch('staff controllers do not use portal auth')
    ->expect('App\Http\Controllers')
    ->not->toUse(['App\Actions\Portal', 'App\Http\Middleware\EnsurePortalSessionIsValid'])
    ->ignoring('App\Http\Controllers\Portal');

arch('models use HasAuditColumns')
    ->expect('App\Models')
    ->toUseTrait(HasAuditColumns::class)
    ->ignoring([
        ChangeHistory::class,
        HasAuditColumns::class,
        SerializesDatesAsUtc::class,
        // Infrastructure counter: no audit columns and no history.
        ReferenceSequence::class,
        StripeEvent::class,
    ]);

arch('models serialize dates as UTC')
    ->expect('App\Models')
    ->toUseTrait(SerializesDatesAsUtc::class)
    ->ignoring([
        HasAuditColumns::class,
        SerializesDatesAsUtc::class,
        StripeEvent::class,
    ]);

arch('only the Stripe SDK wrapper imports Stripe classes')
    ->expect('App')
    ->not->toUse('Stripe')
    ->ignoring(StripeSdkGateway::class);

arch('controllers do not write history')
    ->expect('App\Http\Controllers')
    ->not->toUse(History::class);

arch('every class in app uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debug helpers in app')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);

arch('no debug helpers in database')
    ->expect('Database')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);

arch('env is not used in app')
    ->expect('App')
    ->not->toUse('env');

// Yacht PNG/TCT until Sprint 22 (09 H9). New code must not join this list.
arch('new code does not use retired png helpers')
    ->expect('App')
    ->not->toUse([
        'App\Enums\PngCategory',
        'App\Support\Guests\PngCategory',
        'App\Support\Guests\ApplyPng',
    ])
    ->ignoring([
        'App\Enums\PngCategory',
        'App\Support\Guests\PngCategory',
        'App\Support\Guests\ApplyPng',
        'App\Support\Guests\AndeanCommunity',
        'App\Support\Guests\GuestIssues',
        'App\Support\Documents\Snapshots\DocumentFacts',
        'App\Actions\Bookings\MoveBooking',
        'App\Actions\Extras\UpdateBookingFees',
        'App\Actions\Guests\AddGuest',
        'App\Actions\Guests\RemoveGuest',
        'App\Actions\Guests\UpdateGuest',
        'App\Actions\Checkout\SubmitEngineCheckout',
        'App\Http\Controllers\Rms\BookingExtraController',
        'App\Http\Requests\Rms\UpdateBookingFeesRequest',
        'App\Http\Requests\Engine\SubmitCheckoutRequest',
        'App\Http\Resources\Rms\BookingResource',
        'App\Models\Booking',
        'App\Models\Guest',
    ]);

test('png_collected stays on the yacht allowlist until sprint 22', function (): void {
    $allowed = [
        'Models/Booking.php',
        'Models/Guest.php',
        'Actions/Bookings/MoveBooking.php',
        'Actions/Extras/UpdateBookingFees.php',
        'Actions/Guests/AddGuest.php',
        'Actions/Guests/RemoveGuest.php',
        'Actions/Guests/UpdateGuest.php',
        'Actions/Checkout/SubmitEngineCheckout.php',
        'Http/Controllers/Rms/BookingExtraController.php',
        'Http/Requests/Rms/UpdateBookingFeesRequest.php',
        'Http/Requests/Engine/SubmitCheckoutRequest.php',
        'Http/Resources/Rms/BookingResource.php',
        'Support/Documents/Snapshots/DocumentFacts.php',
        'Support/Guests/ApplyPng.php',
        'Support/Guests/GuestIssues.php',
    ];

    $offenders = [];
    $root = dirname(__DIR__, 2).'/app';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1);

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (is_string($contents) && str_contains($contents, 'png_collected')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});
