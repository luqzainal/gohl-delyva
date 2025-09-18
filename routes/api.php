<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DelyvaCredentialsController;
use App\Http\Controllers\OrderWebhookController;
use App\Http\Controllers\DelyvaWebhookController;
use App\Http\Controllers\ShippingRatesController;
use App\Http\Controllers\HighLevelContextController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// API untuk simpan kredential Delyva - dipanggil dari frontend
Route::post('/credentials', [DelyvaCredentialsController::class, 'saveCredentials']);
Route::post('/credentials/test', [DelyvaCredentialsController::class, 'testCredentials']);
Route::post('/shipping/toggle', [DelyvaCredentialsController::class, 'toggleShipping']);

// Carrier registration endpoints - dipanggil dari frontend
Route::post('/carrier/register/{locationId}', [\App\Http\Controllers\CarrierRegistrationController::class, 'registerCarrier']);
Route::get('/carrier/info/{locationId}', [\App\Http\Controllers\CarrierRegistrationController::class, 'getCarrierInfo']);
Route::get('/carrier/status/{locationId}', [\App\Http\Controllers\CarrierRegistrationController::class, 'getIntegrationStatus']);
Route::put('/carrier/update/{locationId}', [\App\Http\Controllers\CarrierRegistrationController::class, 'updateCarrier']);
Route::put('/carrier/deactivate/{locationId}', [\App\Http\Controllers\CarrierRegistrationController::class, 'deactivateCarrier']);
Route::delete('/carrier/unregister/{locationId}', [\App\Http\Controllers\CarrierRegistrationController::class, 'unregisterCarrier']);

// HighLevel Context endpoints
Route::post('/decrypt-context', [HighLevelContextController::class, 'decryptContext']);
Route::post('/test-context', [HighLevelContextController::class, 'testContext']);
Route::post('/find-integrated-location', [HighLevelContextController::class, 'findIntegratedLocation']);
Route::post('/sync-location-context', [HighLevelContextController::class, 'syncLocationContext']);

// Webhook endpoints - no CSRF protection needed
Route::post('/webhooks/highlevel', [OrderWebhookController::class, 'handleOrderWebhook']);
Route::post('/webhooks/delyva/status', [DelyvaWebhookController::class, 'handleStatusWebhook']);
Route::post('/shipping/rates/callback', [ShippingRatesController::class, 'getRatesCallback']);

// Debug endpoints
Route::get('/debug/delyva/{locationId}', [ShippingRatesController::class, 'debugDelyvaApi']);
