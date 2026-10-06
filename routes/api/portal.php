<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\PortalAgencyController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalAvailabilityController;
use App\Http\Controllers\Portal\PortalBookingController;
use App\Http\Controllers\Portal\PortalCommissionController;
use App\Http\Controllers\Portal\PortalRequestController;
use App\Http\Controllers\Portal\PortalSalesMaterialController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/accept', [PortalAuthController::class, 'accept'])->middleware('throttle:auth-email');
Route::post('/auth/login', [PortalAuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/forgot', [PortalAuthController::class, 'forgot'])->middleware('throttle:auth-email');
Route::post('/auth/reset', [PortalAuthController::class, 'reset'])->middleware('throttle:auth-email');

Route::middleware('portal.auth')->group(function (): void {
    Route::post('/auth/logout', [PortalAuthController::class, 'logout']);
    Route::get('/auth/me', [PortalAuthController::class, 'me']);

    Route::get('/me', [PortalAgencyController::class, 'me']);
    Route::get('/rates', [PortalAgencyController::class, 'rates']);
    Route::get('/availability', [PortalAvailabilityController::class, 'index']);
    Route::get('/calendar', [PortalAvailabilityController::class, 'calendar']);
    Route::get('/bookings', [PortalBookingController::class, 'index']);
    Route::post('/bookings/{booking}/payment-link', [PortalBookingController::class, 'storePaymentLink'])->whereNumber('booking');
    Route::get('/requests', [PortalRequestController::class, 'index']);
    Route::post('/requests', [PortalRequestController::class, 'store']);
    Route::get('/commissions', [PortalCommissionController::class, 'index']);
    Route::get('/sales-materials', [PortalSalesMaterialController::class, 'index']);
    Route::get('/sales-materials/{material}/file', [PortalSalesMaterialController::class, 'file'])->whereNumber('material');
});
