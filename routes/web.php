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

// Asset serving fallback untuk production
Route::get('/build/assets/{file}', function ($file) {
    $path = public_path("build/assets/{$file}");

    if (!file_exists($path)) {
        abort(404);
    }

    $mimeType = 'text/plain';
    if (str_ends_with($file, '.js')) {
        $mimeType = 'application/javascript';
    } elseif (str_ends_with($file, '.css')) {
        $mimeType = 'text/css';
    }

    return response()->file($path, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->where('file', '.*');

// Debug route untuk check server paths
Route::get('/debug-paths', function () {
    return response()->json([
        'public_path' => public_path(),
        'build_path' => public_path('build'),
        'manifest_exists' => file_exists(public_path('build/manifest.json')),
        'manifest_path' => public_path('build/manifest.json'),
        'build_dir_exists' => is_dir(public_path('build')),
        'build_files' => is_dir(public_path('build')) ? scandir(public_path('build')) : [],
        'assets_dir_exists' => is_dir(public_path('build/assets')),
        'assets_files' => is_dir(public_path('build/assets')) ? scandir(public_path('build/assets')) : [],
    ]);
});

// Debug route untuk test API validation (without CSRF)
Route::post('/debug-api', function () {
    $apiKey = request('api_key');
    if (!$apiKey) {
        return response()->json(['error' => 'API key required'], 400);
    }

    $headers = [
        'X-Delyvax-Access-Token' => $apiKey,
        'Content-Type' => 'application/json',
    ];

    $testPayload = [
        'data' => [
            'pickupAddress' => 'Kuala Lumpur',
            'deliveryAddress' => 'Selangor',
            'totalWeight' => 1
        ]
    ];

    try {
        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->post('https://api.delyva.app/v1.0/service/instantQuote', $testPayload);

        return response()->json([
            'status_code' => $response->status(),
            'response_body' => $response->body(),
            'headers_sent' => $headers,
            'payload_sent' => $testPayload,
            'success' => $response->successful()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'headers_sent' => $headers,
            'payload_sent' => $testPayload
        ]);
    }
});

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

    // Test OAuth callback with manual parameters
    Route::get('oauth-test-callback', function() {
        $code = request('code');
        $locationId = request('location_id');

        if (!$code) {
            return response()->json([
                'error' => 'No code parameter provided',
                'usage' => 'Add ?code=YOUR_CODE&location_id=YOUR_LOCATION_ID to test',
                'example' => url('/test/oauth-test-callback?code=665c89bc2131d2a4b4f1315a03aa28c75e04a431&location_id=l1C08ntBrFjLS0elLIYU')
            ]);
        }

        // Test the actual OAuth controller method
        $request = request();
        $controller = new \App\Http\Controllers\HighLevelOAuthController();

        try {
            return $controller->handleCallback($request);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Controller error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    })->name('test.oauth-test-callback');

    // Debug OAuth configuration
    Route::get('oauth-config', function() {
        return response()->json([
            'client_id' => config('services.highlevel.client_id') ? 'SET (' . substr(config('services.highlevel.client_id'), 0, 10) . '...)' : 'NOT SET',
            'client_secret' => config('services.highlevel.client_secret') ? 'SET (' . substr(config('services.highlevel.client_secret'), 0, 8) . '...)' : 'NOT SET',
            'redirect_uri' => config('services.highlevel.redirect_uri') ?? 'NOT SET',
            'base_url' => config('services.highlevel.base_url') ?? 'NOT SET',
            'shared_secret' => config('services.highlevel.shared_secret') ? 'SET' : 'NOT SET',
            'env_values' => [
                'HIGHLEVEL_CLIENT_ID' => env('HIGHLEVEL_CLIENT_ID') ? 'SET' : 'NOT SET',
                'CLIENT_ID' => env('CLIENT_ID') ? 'SET' : 'NOT SET',
                'HIGHLEVEL_REDIRECT_URI' => env('HIGHLEVEL_REDIRECT_URI') ?? 'NOT SET'
            ]
        ]);
    })->name('test.oauth-config');

    // Debug OAuth callback parameters
    Route::get('oauth-debug', function() {
        return response()->json([
            'all_request_params' => request()->all(),
            'full_url' => request()->fullUrl(),
            'query_string' => request()->getQueryString(),
            'path' => request()->path(),
            'method' => request()->method(),
            'note' => 'This shows exactly what parameters are received in OAuth callback'
        ]);
    })->name('test.oauth-debug');

    // Test OAuth token exchange with HighLevel
    Route::get('oauth-simulate', function() {
        $testCode = request('code', 'test_auth_code_123456789');
        $locationId = request('location_id', 'test_location_456');

        try {
            $clientId = config('services.highlevel.client_id');
            $clientSecret = config('services.highlevel.client_secret');
            $redirectUri = config('services.highlevel.redirect_uri');

            if (!$clientId || !$clientSecret) {
                return response()->json([
                    'error' => 'Missing OAuth credentials',
                    'config' => [
                        'client_id' => $clientId ? 'SET' : 'MISSING',
                        'client_secret' => $clientSecret ? 'SET' : 'MISSING',
                        'redirect_uri' => $redirectUri
                    ]
                ], 400);
            }

            // Test the exact same request our callback makes
            $response = Http::asForm()->post('https://api.msgsndr.com/oauth/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $testCode,
                'redirect_uri' => $redirectUri,
            ]);

            return response()->json([
                'request' => [
                    'url' => 'https://api.msgsndr.com/oauth/token',
                    'client_id' => substr($clientId, 0, 10) . '...',
                    'grant_type' => 'authorization_code',
                    'code' => $testCode,
                    'redirect_uri' => $redirectUri,
                ],
                'response' => [
                    'status' => $response->status(),
                    'success' => $response->successful(),
                    'body' => $response->body(),
                    'json' => $response->json(),
                    'headers' => $response->headers()
                ],
                'note' => 'This tests the OAuth token exchange with a test code. Use ?code=your_real_code&location_id=your_location to test with real values.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Exception occurred',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    })->name('test.oauth-simulate');
});
