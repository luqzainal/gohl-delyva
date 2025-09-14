<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\HighLevelOAuthController;
use App\Http\Controllers\DelyvaCredentialsController;
use App\Http\Controllers\CarrierRegistrationController;
use App\Http\Controllers\ShippingRatesController;
use App\Http\Controllers\OrderWebhookController;
use App\Http\Controllers\DelyvaWebhookController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\SimpleTestController;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Plugin page untuk HighLevel
Route::get('/plugin-page', function () {
    return view('plugin');
})->name('plugin.page');

// Installation result pages
Route::get('/install/success', function () {
    $locationId = request()->get('locationId') ?? session('locationId');
    return view('install-success', compact('locationId'));
})->name('install.success');

Route::get('/install/error', function () {
    $error = request()->get('error') ?? session('error');
    $errorId = request()->get('errorId') ?? session('errorId');
    return view('install-error', compact('error', 'errorId'));
})->name('install.error');

// HighLevel OAuth Routes
Route::prefix('oauth')->group(function () {
    // Generic callback route untuk HighLevel marketplace
    Route::get('callback', [HighLevelOAuthController::class, 'handleCallback'])->name('oauth.callback');
    
    // Specific HighLevel routes
    Route::prefix('highlevel')->group(function () {
        Route::get('redirect', [HighLevelOAuthController::class, 'redirectToHighLevel'])->name('oauth.highlevel.redirect');
        Route::get('callback', [HighLevelOAuthController::class, 'handleCallback'])->name('oauth.highlevel.callback');
        Route::post('refresh/{locationId}', [HighLevelOAuthController::class, 'refreshToken'])->name('oauth.highlevel.refresh');
        Route::get('status/{locationId}', [HighLevelOAuthController::class, 'getIntegrationStatus'])->name('oauth.highlevel.status');
    });
    
    // Auth shortcut route for error page
    Route::get('auth', [HighLevelOAuthController::class, 'redirectToHighLevel'])->name('highlevel.auth');
});

// Delyva Credentials Routes
Route::prefix('delyva')->group(function () {
    Route::post('credentials', [DelyvaCredentialsController::class, 'saveCredentials'])->name('delyva.credentials.save');
    Route::get('credentials/{locationId}', [DelyvaCredentialsController::class, 'getCredentials'])->name('delyva.credentials.get');
    Route::post('credentials/test', [DelyvaCredentialsController::class, 'testCredentials'])->name('delyva.credentials.test');
    Route::delete('credentials/{locationId}', [DelyvaCredentialsController::class, 'deleteCredentials'])->name('delyva.credentials.delete');
    Route::get('couriers/{locationId}', [DelyvaCredentialsController::class, 'getAvailableCouriers'])->name('delyva.couriers');
});

// Carrier Registration Routes
Route::prefix('carrier')->group(function () {
    Route::post('register/{locationId}', [CarrierRegistrationController::class, 'registerCarrier'])->name('carrier.register');
    Route::get('info/{locationId}', [CarrierRegistrationController::class, 'getCarrierInfo'])->name('carrier.info');
    Route::put('update/{locationId}', [CarrierRegistrationController::class, 'updateCarrier'])->name('carrier.update');
    Route::put('deactivate/{locationId}', [CarrierRegistrationController::class, 'deactivateCarrier'])->name('carrier.deactivate');
    Route::delete('unregister/{locationId}', [CarrierRegistrationController::class, 'unregisterCarrier'])->name('carrier.unregister');
});

// Shipping Rates Routes
Route::prefix('shipping')->group(function () {
    Route::post('rates/callback', [ShippingRatesController::class, 'getRatesCallback'])->name('shipping.rates.callback');
    Route::post('rates/test', [ShippingRatesController::class, 'testRates'])->name('shipping.rates.test');
    Route::get('rates/available/{locationId}', [ShippingRatesController::class, 'getAvailableRates'])->name('shipping.rates.available');
});

// Order Webhook Routes
Route::prefix('webhooks')->group(function () {
    Route::post('highlevel', [OrderWebhookController::class, 'handleOrderWebhook'])->name('webhooks.highlevel');
    Route::post('delyva/status', [DelyvaWebhookController::class, 'handleStatusWebhook'])->name('webhooks.delyva.status');
    Route::get('orders/{locationId}', [OrderWebhookController::class, 'getOrders'])->name('orders.list');
    Route::get('orders/{locationId}/{orderId}', [OrderWebhookController::class, 'getOrderDetails'])->name('orders.details');
});

// Tracking & Status Routes
Route::prefix('tracking')->group(function () {
    Route::post('sync/{locationId}/{orderId}', [DelyvaWebhookController::class, 'syncOrderStatus'])->name('tracking.sync');
    Route::get('info/{locationId}/{orderId}', [DelyvaWebhookController::class, 'getTrackingInfo'])->name('tracking.info');
});

// Testing Routes (untuk development sahaja)
Route::prefix('test')->group(function () {
    Route::get('credentials', [TestController::class, 'testCredentials'])->name('test.credentials');
    Route::get('rates', [TestController::class, 'testRates'])->name('test.rates');
    Route::get('webhook', [TestController::class, 'testWebhook'])->name('test.webhook');
    Route::get('process-order', [TestController::class, 'testProcessOrder'])->name('test.process-order');
    Route::get('all', [TestController::class, 'testAll'])->name('test.all');
    Route::get('simple', function() {
        return response()->json(['status' => 'ok', 'message' => 'Simple test working']);
    });
    Route::get('simple-all', [SimpleTestController::class, 'testAll'])->name('test.simple-all');
    Route::get('simple-credentials', [SimpleTestController::class, 'testCredentials'])->name('test.simple-credentials');
    Route::get('simple-rates', [SimpleTestController::class, 'testRates'])->name('test.simple-rates');
    
    // Database check endpoint
    Route::get('database', function() {
        $integrations = \App\Models\ShippingIntegration::all();
        return response()->json([
            'total_records' => $integrations->count(),
            'records' => $integrations->map(function($integration) {
                return [
                    'id' => $integration->id,
                    'location_id' => $integration->location_id,
                    'api_key_preview' => substr($integration->delyva_api_key, 0, 15) . '...',
                    'has_customer_id' => !empty($integration->delyva_customer_id),
                    'created_at' => $integration->created_at->format('Y-m-d H:i:s')
                ];
            })
        ]);
    })->name('test.database');
    
    // Test OAuth callback endpoint
    Route::get('oauth-callback', function() {
        return response()->json([
            'message' => 'OAuth callback endpoint working',
            'routes_available' => [
                'oauth_callback' => url('/oauth/callback'),
                'plugin_page' => url('/plugin-page'),
                'test_endpoints' => url('/test/simple-all')
            ],
            'instructions' => [
                'For HighLevel marketplace, use: ' . url('/oauth/callback'),
                'For plugin page, use: ' . url('/plugin-page'),
                'Make sure to configure these URLs in HighLevel app settings'
            ]
        ]);
    })->name('test.oauth-callback');
});
