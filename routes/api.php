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

// HighLevel Context endpoints
Route::post('/decrypt-context', [HighLevelContextController::class, 'decryptContext']);
Route::post('/test-context', [HighLevelContextController::class, 'testContext']);

// Webhook endpoints - no CSRF protection needed
Route::post('/webhooks/highlevel', [OrderWebhookController::class, 'handleOrderWebhook']);
Route::post('/webhooks/delyva/status', [DelyvaWebhookController::class, 'handleStatusWebhook']);
Route::post('/shipping/rates/callback', [ShippingRatesController::class, 'getRatesCallback']);
