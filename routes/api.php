<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\RetiredEndpointController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::any('rms/charter-enquiries/{enquiry}/proposal', RetiredEndpointController::class)->whereNumber('enquiry');
Route::any('rms/charter-enquiries/{enquiry}', RetiredEndpointController::class)->whereNumber('enquiry');
Route::any('rms/charter-enquiries', RetiredEndpointController::class);
Route::any('engine/charter-proposal/{token}/accept', RetiredEndpointController::class);
Route::any('engine/charter-proposal/{token}/decline', RetiredEndpointController::class);
Route::any('engine/charter-proposal/{token}', RetiredEndpointController::class);
Route::any('engine/charter-enquiries', RetiredEndpointController::class);
