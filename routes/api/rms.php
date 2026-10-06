<?php

declare(strict_types=1);

use App\Http\Controllers\Rms\AgencyController;
use App\Http\Controllers\Rms\BookingBillingController;
use App\Http\Controllers\Rms\BookingController;
use App\Http\Controllers\Rms\BookingExtraController;
use App\Http\Controllers\Rms\BookingFeesController;
use App\Http\Controllers\Rms\BusinessRulesController;
use App\Http\Controllers\Rms\CalendarController;
use App\Http\Controllers\Rms\ClientDocumentController;
use App\Http\Controllers\Rms\CommissionController;
use App\Http\Controllers\Rms\CompleteLinkController;
use App\Http\Controllers\Rms\ConsentController;
use App\Http\Controllers\Rms\ContactController;
use App\Http\Controllers\Rms\ContactsInController;
use App\Http\Controllers\Rms\CountryController;
use App\Http\Controllers\Rms\DeliveryController;
use App\Http\Controllers\Rms\DocumentController;
use App\Http\Controllers\Rms\EngineSettingsController;
use App\Http\Controllers\Rms\ExtrasController;
use App\Http\Controllers\Rms\FrontDeskController;
use App\Http\Controllers\Rms\GroupController;
use App\Http\Controllers\Rms\GuestController;
use App\Http\Controllers\Rms\GuestExperienceController;
use App\Http\Controllers\Rms\GuestResponseController;
use App\Http\Controllers\Rms\HoldController;
use App\Http\Controllers\Rms\HotelKpisController;
use App\Http\Controllers\Rms\InternalBlockController;
use App\Http\Controllers\Rms\MetricsController;
use App\Http\Controllers\Rms\OfferController;
use App\Http\Controllers\Rms\PaymentController;
use App\Http\Controllers\Rms\PaymentLinkController;
use App\Http\Controllers\Rms\PermissionController;
use App\Http\Controllers\Rms\PropertyController;
use App\Http\Controllers\Rms\RatesController;
use App\Http\Controllers\Rms\ReconciliationController;
use App\Http\Controllers\Rms\RefundController;
use App\Http\Controllers\Rms\ReportController;
use App\Http\Controllers\Rms\ReportSubscriptionController;
use App\Http\Controllers\Rms\RequestController;
use App\Http\Controllers\Rms\RestrictionController;
use App\Http\Controllers\Rms\RoleController;
use App\Http\Controllers\Rms\RoomController;
use App\Http\Controllers\Rms\RoomTypeController;
use App\Http\Controllers\Rms\SalesMaterialController;
use App\Http\Controllers\Rms\UserController;
use App\Http\Controllers\Rms\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['ok' => true]));

Route::get('permissions', [PermissionController::class, 'index']);

Route::get('roles', [RoleController::class, 'index']);
Route::post('roles', [RoleController::class, 'store']);
Route::patch('roles/{role}', [RoleController::class, 'update']);
Route::delete('roles/{role}', [RoleController::class, 'destroy']);
Route::get('roles/{role}/history', [RoleController::class, 'history']);

Route::get('users', [UserController::class, 'index']);
Route::post('users', [UserController::class, 'store']);
Route::patch('users/{user}', [UserController::class, 'update']);
Route::post('users/{user}/disable', [UserController::class, 'disable']);
Route::post('users/{user}/enable', [UserController::class, 'enable']);
Route::post('users/{user}/resend-invitation', [UserController::class, 'resendInvitation']);
Route::get('users/{user}/history', [UserController::class, 'history']);

Route::get('rates', [RatesController::class, 'current']);
Route::post('rates/validate', [RatesController::class, 'validateDocument']);
Route::post('rates/price-check', [RatesController::class, 'priceCheck']);
Route::post('rates/versions', [RatesController::class, 'store']);
Route::get('rates/versions', [RatesController::class, 'index']);
Route::get('rates/versions/{version}', [RatesController::class, 'show'])
    ->whereNumber('version');

Route::get('engine-settings', [EngineSettingsController::class, 'current']);
Route::post('engine-settings/validate', [EngineSettingsController::class, 'validateDocument']);
Route::post('engine-settings/versions', [EngineSettingsController::class, 'store']);
Route::get('engine-settings/versions', [EngineSettingsController::class, 'index']);
Route::get('engine-settings/versions/{version}', [EngineSettingsController::class, 'show'])
    ->whereNumber('version');

Route::get('business-rules', [BusinessRulesController::class, 'current']);
Route::post('business-rules/validate', [BusinessRulesController::class, 'validateDocument']);
Route::post('business-rules/versions', [BusinessRulesController::class, 'store']);
Route::get('business-rules/versions', [BusinessRulesController::class, 'index']);
Route::get('business-rules/versions/{version}', [BusinessRulesController::class, 'show'])
    ->whereNumber('version');

Route::get('extras', [ExtrasController::class, 'current']);
Route::post('extras/validate', [ExtrasController::class, 'validateDocument']);
Route::post('extras/versions', [ExtrasController::class, 'store']);
Route::get('extras/versions', [ExtrasController::class, 'index']);
Route::get('extras/versions/{version}', [ExtrasController::class, 'show'])
    ->whereNumber('version');

Route::get('properties', [PropertyController::class, 'index']);
Route::get('properties/{property}', [PropertyController::class, 'show'])->whereNumber('property');
Route::patch('properties/{property}', [PropertyController::class, 'update'])->whereNumber('property');
Route::post('properties/{property}/hero', [PropertyController::class, 'hero'])->whereNumber('property');
Route::get('properties/{property}/history', [PropertyController::class, 'history'])->whereNumber('property');

Route::get('properties/{property}/room-types', [RoomTypeController::class, 'index'])->whereNumber('property');
Route::post('properties/{property}/room-types', [RoomTypeController::class, 'store'])->whereNumber('property');
Route::patch('room-types/{roomType}', [RoomTypeController::class, 'update'])->whereNumber('roomType');
Route::post('room-types/{roomType}/photos', [RoomTypeController::class, 'photo'])->whereNumber('roomType');
Route::post('room-types/{roomType}/deactivate', [RoomTypeController::class, 'deactivate'])->whereNumber('roomType');
Route::get('room-types/{roomType}/history', [RoomTypeController::class, 'history'])->whereNumber('roomType');

Route::get('properties/{property}/rooms', [RoomController::class, 'index'])->whereNumber('property');
Route::post('properties/{property}/rooms', [RoomController::class, 'store'])->whereNumber('property');
Route::patch('rooms/{room}', [RoomController::class, 'update'])->whereNumber('room');
Route::post('rooms/{room}/deactivate', [RoomController::class, 'deactivate'])->whereNumber('room');
Route::get('rooms/{room}/history', [RoomController::class, 'history'])->whereNumber('room');

Route::get('calendar', CalendarController::class);

Route::get('payments', [PaymentController::class, 'index']);
Route::get('payments/options', [PaymentController::class, 'options']);
Route::get('payments/reconciliation', [ReconciliationController::class, 'index']);
Route::post('payments/reconciliation/apply', [ReconciliationController::class, 'apply']);
Route::post('payments/{payment}/mark-received', [PaymentController::class, 'markReceived'])->whereNumber('payment');
Route::post('payment-links/{paymentLink}/send', [PaymentLinkController::class, 'send'])->whereNumber('paymentLink');
Route::post('payment-links/{paymentLink}/cancel', [PaymentLinkController::class, 'cancel'])->whereNumber('paymentLink');

Route::get('front-desk', [FrontDeskController::class, 'index']);
Route::get('front-desk/registration', [FrontDeskController::class, 'registration']);

Route::get('bookings', [BookingController::class, 'index']);
Route::get('bookings/audit', [BookingController::class, 'audit']);
Route::get('bookings/owners', [BookingController::class, 'owners']);
Route::get('bookings/form-options', [BookingController::class, 'formOptions']);
Route::post('bookings/quote', [BookingController::class, 'quote']);
Route::post('bookings', [BookingController::class, 'store']);
Route::post('bookings/{booking}/check-in', [BookingController::class, 'checkIn'])->whereNumber('booking');
Route::post('bookings/{booking}/check-out', [BookingController::class, 'checkOut'])->whereNumber('booking');
Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow'])->whereNumber('booking');
Route::post('bookings/{booking}/undo-check-in', [BookingController::class, 'undoCheckIn'])->whereNumber('booking');
Route::post('bookings/{booking}/transition', [BookingController::class, 'transition'])->whereNumber('booking');
Route::post('bookings/{booking}/overdue-decision', [BookingController::class, 'overdueDecision'])->whereNumber('booking');
Route::post('bookings/{booking}/commission-approval', [CommissionController::class, 'decide'])->whereNumber('booking');
Route::post('bookings/{booking}/modify/preview', [BookingController::class, 'modifyPreview'])->whereNumber('booking');
Route::post('bookings/{booking}/modify', [BookingController::class, 'modify'])->whereNumber('booking');
Route::post('bookings/{booking}/move/preview', [BookingController::class, 'movePreview'])->whereNumber('booking');
Route::post('bookings/{booking}/move', [BookingController::class, 'move'])->whereNumber('booking');
Route::patch('bookings/{booking}', [BookingController::class, 'update'])->whereNumber('booking');
Route::delete('bookings/{booking}', [BookingController::class, 'destroy'])->whereNumber('booking');
Route::get('bookings/{booking}', [BookingController::class, 'show'])->whereNumber('booking');
Route::get('bookings/{booking}/history', [BookingController::class, 'history'])->whereNumber('booking');
Route::get('bookings/{booking}/payments', [PaymentController::class, 'forBooking'])->whereNumber('booking');
Route::post('bookings/{booking}/payments', [PaymentController::class, 'store'])->whereNumber('booking');
Route::post('bookings/{booking}/payment-link', [PaymentLinkController::class, 'store'])->whereNumber('booking');
Route::get('bookings/{booking}/guests', [GuestController::class, 'index'])->whereNumber('booking');
Route::post('bookings/{booking}/guests', [GuestController::class, 'store'])->whereNumber('booking');
Route::get('bookings/{booking}/consents', [ConsentController::class, 'index'])->whereNumber('booking');
Route::post('bookings/{booking}/consents', [ConsentController::class, 'store'])->whereNumber('booking');
Route::get('bookings/{booking}/extras', [BookingExtraController::class, 'index'])->whereNumber('booking');
Route::post('bookings/{booking}/extras', [BookingExtraController::class, 'store'])->whereNumber('booking');
Route::patch('bookings/{booking}/fees', [BookingFeesController::class, 'update'])->whereNumber('booking');
Route::patch('bookings/{booking}/billing', [BookingBillingController::class, 'update'])->whereNumber('booking');
Route::post('bookings/{booking}/complete-link', CompleteLinkController::class)->whereNumber('booking');
Route::get('bookings/{booking}/documents', [DocumentController::class, 'index'])->whereNumber('booking');
Route::get('bookings/{booking}/documents/plan', [DocumentController::class, 'plan'])->whereNumber('booking');
Route::get('bookings/{booking}/documents/{kind}/html', [DocumentController::class, 'html'])->whereNumber('booking');
Route::post('bookings/{booking}/documents/{kind}/issue', [DocumentController::class, 'issue'])->whereNumber('booking');
Route::get('bookings/{booking}/receipts/{payment}/html', [DocumentController::class, 'receiptHtml'])->whereNumber('booking')->whereNumber('payment');
Route::get('bookings/{booking}/deliveries', [DeliveryController::class, 'index'])->whereNumber('booking');
Route::post('bookings/{booking}/wire-instructions/send', [DeliveryController::class, 'sendWire'])->whereNumber('booking');
Route::get('documents', [ClientDocumentController::class, 'index']);
Route::get('documents/{document}/html', [DocumentController::class, 'issuedHtml'])->whereNumber('document');
Route::get('documents/{document}/file', [DocumentController::class, 'file'])->whereNumber('document');
Route::post('documents/{document}/send', [DocumentController::class, 'send'])->whereNumber('document');
Route::delete('booking-extras/{extra}', [BookingExtraController::class, 'destroy'])->whereNumber('extra');
Route::get('metrics', MetricsController::class);
Route::get('hotel-kpis', HotelKpisController::class);
Route::get('reports', [ReportController::class, 'index']);
Route::get('reports/runs', [ReportController::class, 'runs']);
Route::post('reports/{key}/runs', [ReportController::class, 'store']);
Route::get('reports/runs/{run}/file/{format}', [ReportController::class, 'file'])->whereNumber('run');
Route::get('reports/subscriptions', [ReportSubscriptionController::class, 'index']);
Route::patch('reports/subscriptions/{subscription}', [ReportSubscriptionController::class, 'update'])
    ->whereNumber('subscription')
    ->middleware('permission:rules.manage');
Route::post('reports/subscriptions/{subscription}/run-now', [ReportSubscriptionController::class, 'runNow'])
    ->whereNumber('subscription')
    ->middleware('permission:rules.manage');

Route::get('guest-experience', [GuestExperienceController::class, 'index']);
Route::get('guest-experience/arrivals', [GuestExperienceController::class, 'arrivals']);
Route::get('guest-experience/nps', [GuestResponseController::class, 'index']);
Route::get('guest-experience/questions', [GuestExperienceController::class, 'questions']);
Route::get('guest-experience/survey-questions', [GuestResponseController::class, 'questions']);
Route::get('bookings/{booking}/survey-guests', [GuestResponseController::class, 'surveyGuests'])->whereNumber('booking');
Route::post('bookings/{booking}/guest-responses', [GuestResponseController::class, 'store'])->whereNumber('booking');
Route::get('guests/{guest}/preferences', [GuestExperienceController::class, 'preferences'])->whereNumber('guest');
Route::put('guests/{guest}/preferences', [GuestExperienceController::class, 'update'])->whereNumber('guest');

Route::patch('guests/{guest}', [GuestController::class, 'update'])->whereNumber('guest');
Route::delete('guests/{guest}', [GuestController::class, 'destroy'])->whereNumber('guest');

Route::get('offers', [OfferController::class, 'index']);
Route::post('offers', [OfferController::class, 'store']);
Route::get('offers/{offer}', [OfferController::class, 'show'])->whereNumber('offer');
Route::patch('offers/{offer}', [OfferController::class, 'update'])->whereNumber('offer');
Route::post('offers/{offer}/approve', [OfferController::class, 'approve'])->whereNumber('offer');
Route::post('offers/{offer}/reject', [OfferController::class, 'reject'])->whereNumber('offer');
Route::post('offers/{offer}/pause', [OfferController::class, 'pause'])->whereNumber('offer');
Route::post('offers/{offer}/resume', [OfferController::class, 'resume'])->whereNumber('offer');
Route::get('offers/{offer}/history', [OfferController::class, 'history'])->whereNumber('offer');

Route::get('agencies', [AgencyController::class, 'index']);
Route::post('agencies', [AgencyController::class, 'store']);
Route::get('agencies/{agency}/portal-preview', [AgencyController::class, 'portalPreview'])->whereNumber('agency');
Route::get('agencies/{agency}/portal-activity', [AgencyController::class, 'portalActivity'])->whereNumber('agency');
Route::post('agencies/{agency}/users', [AgencyController::class, 'storeUser'])->whereNumber('agency');
Route::patch('agencies/{agency}/users/{user}', [AgencyController::class, 'updateUser'])->whereNumber('agency')->whereNumber('user');
Route::get('agencies/{agency}', [AgencyController::class, 'show'])->whereNumber('agency');
Route::patch('agencies/{agency}', [AgencyController::class, 'update'])->whereNumber('agency');
Route::post('agencies/{agency}/decide', [AgencyController::class, 'decide'])->whereNumber('agency');
Route::post('agencies/{agency}/portal/suspend', [AgencyController::class, 'suspendPortal'])->whereNumber('agency');
Route::post('agencies/{agency}/portal/resume', [AgencyController::class, 'resumePortal'])->whereNumber('agency');
Route::post('agencies/{agency}/users/{user}/invite', [AgencyController::class, 'inviteUser'])->whereNumber('agency')->whereNumber('user');

Route::get('sales-materials', [SalesMaterialController::class, 'index']);
Route::post('sales-materials', [SalesMaterialController::class, 'store']);
Route::patch('sales-materials/{material}', [SalesMaterialController::class, 'update'])->whereNumber('material');
Route::get('sales-materials/{material}/file', [SalesMaterialController::class, 'file'])->whereNumber('material');

Route::get('commissions', [CommissionController::class, 'index']);
Route::post('commissions/{booking}/payout', [CommissionController::class, 'payout'])->whereNumber('booking');

Route::get('refunds', [RefundController::class, 'index']);
Route::post('refunds/{refund}/decide', [RefundController::class, 'decide'])->whereNumber('refund');
Route::post('refunds/{refund}/execute', [RefundController::class, 'execute'])->whereNumber('refund');

Route::get('groups', [GroupController::class, 'index']);
Route::get('countries', [CountryController::class, 'index']);
Route::get('contacts-in/nationalities', [ContactsInController::class, 'nationalities']);
Route::get('contacts-in', [ContactsInController::class, 'index']);
Route::get('contacts', [ContactController::class, 'index']);

Route::get('requests', [RequestController::class, 'index']);
Route::post('requests/{booking}/confirm/preview', [RequestController::class, 'preview'])->whereNumber('booking');
Route::post('requests/{booking}/confirm', [RequestController::class, 'confirm'])->whereNumber('booking');
Route::post('requests/{booking}/release', [RequestController::class, 'release'])->whereNumber('booking');

Route::get('holds', [HoldController::class, 'index']);

Route::get('waitlist', [WaitlistController::class, 'index']);
Route::post('waitlist', [WaitlistController::class, 'store']);
Route::post('waitlist/{entry}/notify', [WaitlistController::class, 'notify'])->whereNumber('entry');
Route::post('waitlist/{entry}/remove', [WaitlistController::class, 'remove'])->whereNumber('entry');

Route::get('restrictions', [RestrictionController::class, 'index']);
Route::put('restrictions', [RestrictionController::class, 'update']);

Route::get('blocks', [InternalBlockController::class, 'index']);
Route::post('blocks', [InternalBlockController::class, 'store']);
Route::patch('blocks/{block}', [InternalBlockController::class, 'update'])->whereNumber('block');
Route::post('blocks/{block}/release', [InternalBlockController::class, 'release'])->whereNumber('block');
Route::post('blocks/{block}/shorten', [InternalBlockController::class, 'shorten'])->whereNumber('block');
Route::get('blocks/{block}/history', [InternalBlockController::class, 'history'])->whereNumber('block');
