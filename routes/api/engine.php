<?php

declare(strict_types=1);

use App\Http\Controllers\Engine\AvailabilityController;
use App\Http\Controllers\Engine\CalendarController;
use App\Http\Controllers\Engine\CheckoutController;
use App\Http\Controllers\Engine\CompleteReservationController;
use App\Http\Controllers\Engine\CountryController;
use App\Http\Controllers\Engine\EngineEventsController;
use App\Http\Controllers\Engine\MarketingLeadController;
use App\Http\Controllers\Engine\PromoCheckController;
use App\Http\Controllers\Engine\PropertyFeedController;
use App\Http\Controllers\Engine\QuestionnaireController;
use App\Http\Controllers\Engine\QuoteController;
use App\Http\Controllers\Engine\SurveyController;
use App\Http\Controllers\Engine\UnsubscribeController;
use App\Http\Controllers\Engine\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::post('events', EngineEventsController::class)->middleware('throttle:engine-events');

Route::get('property', PropertyFeedController::class);
Route::get('calendar', CalendarController::class);
Route::get('availability', AvailabilityController::class);
Route::get('countries', CountryController::class);
Route::post('promo/check', PromoCheckController::class)->middleware('throttle:engine-promo');
Route::post('quote', QuoteController::class);

Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:engine-checkout');
Route::post('checkout/{token}/extend', [CheckoutController::class, 'extend'])->middleware('throttle:engine-checkout');
Route::get('checkout/{token}/status', [CheckoutController::class, 'status']);
Route::delete('checkout/{token}', [CheckoutController::class, 'destroy']);
Route::post('checkout/{token}/submit', [CheckoutController::class, 'submit'])->middleware('throttle:engine-checkout');
Route::post('marketing-leads', [MarketingLeadController::class, 'store'])->middleware('throttle:engine-checkout');

Route::post('waitlist', WaitlistController::class)->middleware('throttle:engine-waitlist');

Route::middleware(['throttle:engine-complete', 'noindex'])->group(function (): void {
    Route::get('complete/{token}', [CompleteReservationController::class, 'show']);
    Route::put('complete/{token}/billing', [CompleteReservationController::class, 'updateBilling']);
    Route::put('complete/{token}/guests/{guest}', [CompleteReservationController::class, 'updateGuest'])->whereNumber('guest');
    Route::post('complete/{token}/declarations', [CompleteReservationController::class, 'recordDeclarations']);
    Route::get('questionnaire/{token}', [QuestionnaireController::class, 'show']);
    Route::put('questionnaire/{token}/guests/{guest}', [QuestionnaireController::class, 'update'])->whereNumber('guest');
    Route::get('survey/{token}', [SurveyController::class, 'show']);
    Route::post('survey/{token}/guests/{guest}', [SurveyController::class, 'store'])->whereNumber('guest');
    Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show']);
    Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'store']);
});
