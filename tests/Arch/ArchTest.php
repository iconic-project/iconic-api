<?php

declare(strict_types=1);

use App\Models\Archive\CabinClaim;
use App\Models\Archive\Departure;
use App\Models\Archive\Itinerary;
use App\Models\Archive\LegacyMorphs;
use App\Models\ChangeHistory;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Models\ReferenceSequence;
use App\Models\StripeEvent;
use App\Services\Stripe\StripeSdkGateway;
use App\Support\History\History;

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
        // Read-only renamed tables. They have no audit columns.
        Departure::class,
        Itinerary::class,
        CabinClaim::class,
        LegacyMorphs::class,
    ]);

arch('models serialize dates as UTC')
    ->expect('App\Models')
    ->toUseTrait(SerializesDatesAsUtc::class)
    ->ignoring([
        HasAuditColumns::class,
        SerializesDatesAsUtc::class,
        StripeEvent::class,
        Departure::class,
        Itinerary::class,
        CabinClaim::class,
        LegacyMorphs::class,
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
