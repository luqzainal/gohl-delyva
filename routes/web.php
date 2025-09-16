<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
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
    // Debug callback route that logs everything
    Route::any('debug-callback', function (Request $request) {
        $logData = [
            'timestamp' => now()->toISOString(),
            'method' => $request->method(),
            'full_url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'query_params' => $request->query(),
            'post_data' => $request->all(),
            'raw_input' => $request->getContent(),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ];

        Log::info('OAuth DEBUG CALLBACK', $logData);

        // Also save to a file for easy viewing
        file_put_contents(storage_path('logs/oauth_debug.json'), json_encode($logData, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

        // Return a user-friendly page showing what was received
        return response()->view('oauth-debug', [
            'timestamp' => $logData['timestamp'],
            'method' => $logData['method'],
            'url' => $logData['full_url'],
            'params' => $logData['query_params'],
            'all_data' => $logData
        ]);
    })->name('oauth.debug-callback');

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

// API Testing Routes untuk Postman
Route::prefix('api/test')->group(function () {
    // Token management endpoints
    Route::get('tokens', function() {
        $tokens = \App\Models\LocationTokens::all();
        return response()->json([
            'total_tokens' => $tokens->count(),
            'tokens' => $tokens->map(function($token) {
                return [
                    'id' => $token->id,
                    'location_id' => $token->location_id,
                    'access_token_format' => [
                        'length' => strlen($token->access_token ?? ''),
                        'is_jwt' => str_contains($token->access_token ?? '', '.'),
                        'preview' => substr($token->access_token ?? '', 0, 50) . '...',
                        'parts_count' => count(explode('.', $token->access_token ?? ''))
                    ],
                    'refresh_token_format' => [
                        'length' => strlen($token->refresh_token ?? ''),
                        'is_jwt' => str_contains($token->refresh_token ?? '', '.'),
                        'preview' => substr($token->refresh_token ?? '', 0, 50) . '...',
                        'parts_count' => count(explode('.', $token->refresh_token ?? ''))
                    ],
                    'created_at' => $token->created_at,
                    'updated_at' => $token->updated_at
                ];
            })
        ]);
    })->name('api.test.tokens');

    Route::get('token/{locationId}', function($locationId) {
        $token = \App\Models\LocationTokens::where('location_id', $locationId)->first();

        if (!$token) {
            return response()->json(['error' => 'Token not found'], 404);
        }

        return response()->json([
            'location_id' => $token->location_id,
            'access_token' => [
                'value' => $token->access_token,
                'length' => strlen($token->access_token),
                'is_jwt' => str_contains($token->access_token, '.'),
                'jwt_parts' => explode('.', $token->access_token),
                'decoded_header' => str_contains($token->access_token, '.') ?
                    json_decode(base64_decode(explode('.', $token->access_token)[0]), true) : null,
                'expires_info' => 'Check JWT payload for exp claim'
            ],
            'refresh_token' => [
                'value' => $token->refresh_token,
                'length' => strlen($token->refresh_token),
                'is_jwt' => str_contains($token->refresh_token, '.'),
                'jwt_parts' => str_contains($token->refresh_token, '.') ? explode('.', $token->refresh_token) : null
            ]
        ]);
    })->name('api.test.token.detail');

    Route::post('token/convert/{locationId}', function(Request $request, $locationId) {
        $token = \App\Models\LocationTokens::where('location_id', $locationId)->first();

        if (!$token) {
            return response()->json(['error' => 'Token not found'], 404);
        }

        $format = $request->get('format', 'simple'); // simple, hash, encrypted

        switch($format) {
            case 'simple':
                $newAccessToken = 'simple_' . bin2hex(random_bytes(32));
                $newRefreshToken = 'refresh_' . bin2hex(random_bytes(32));
                break;

            case 'hash':
                $newAccessToken = 'hash_' . hash('sha256', $token->access_token . time());
                $newRefreshToken = 'hash_' . hash('sha256', $token->refresh_token . time());
                break;

            case 'encrypted':
                $key = config('app.key');
                $newAccessToken = 'enc_' . base64_encode(encrypt($token->access_token));
                $newRefreshToken = 'enc_' . base64_encode(encrypt($token->refresh_token));
                break;

            default:
                return response()->json(['error' => 'Invalid format'], 400);
        }

        // Save original tokens for backup
        $backup = [
            'original_access_token' => $token->access_token,
            'original_refresh_token' => $token->refresh_token,
            'converted_at' => now()
        ];

        $token->access_token = $newAccessToken;
        $token->refresh_token = $newRefreshToken;
        $token->save();

        return response()->json([
            'message' => 'Token format converted',
            'format' => $format,
            'location_id' => $locationId,
            'new_tokens' => [
                'access_token' => $newAccessToken,
                'refresh_token' => $newRefreshToken
            ],
            'backup' => $backup
        ]);
    })->name('api.test.token.convert');

    Route::post('oauth/simulate', function(Request $request) {
        $code = $request->get('code', 'test_' . bin2hex(random_bytes(16)));
        $locationId = $request->get('location_id', 'test_loc_' . bin2hex(random_bytes(8)));

        // Simulate OAuth response
        $simulatedTokens = [
            'access_token' => 'sim_access_' . bin2hex(random_bytes(32)),
            'refresh_token' => 'sim_refresh_' . bin2hex(random_bytes(32)),
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'locations.readonly contacts.write'
        ];

        // Save to database
        $tokenRecord = \App\Models\LocationTokens::updateOrCreate(
            ['location_id' => $locationId],
            [
                'access_token' => $simulatedTokens['access_token'],
                'refresh_token' => $simulatedTokens['refresh_token'],
                'company_id' => 'test_company',
                'user_id' => 'test_user',
                'user_type' => 'location'
            ]
        );

        return response()->json([
            'message' => 'Simulated OAuth tokens created',
            'oauth_response' => $simulatedTokens,
            'saved_to_db' => $tokenRecord->id,
            'location_id' => $locationId
        ]);
    })->name('api.test.oauth.simulate');

    // REAL OAuth testing endpoint
    Route::post('oauth/real', function(Request $request) {
        $code = $request->get('code');
        $locationId = $request->get('location_id');

        if (!$code) {
            return response()->json([
                'error' => 'Authorization code required',
                'usage' => 'POST /api/test/oauth/real with {"code": "real_code", "location_id": "real_location_id"}'
            ], 400);
        }

        try {
            // Get app credentials
            $creds = getAppCredentials();

            Log::info('Real OAuth API test initiated', [
                'code_length' => strlen($code),
                'code_preview' => substr($code, 0, 20) . '...',
                'location_id' => $locationId,
                'client_id' => substr($creds['client_id'], 0, 10) . '...',
                'redirect_uri' => config('services.highlevel.redirect_uri')
            ]);

            // Make real call to HighLevel
            $response = ghl_oauth_call($code, '');

            if ($response) {
                // Check if successful
                if (property_exists($response, 'access_token')) {
                    // Add location ID to response
                    if ($locationId) {
                        $response->locationId = $locationId;
                    }

                    // Try to save to database
                    try {
                        $tokenRecord = \App\Models\LocationTokens::updateOrCreate(
                            ['location_id' => $locationId ?: 'unknown_location_' . time()],
                            [
                                'access_token' => $response->access_token,
                                'refresh_token' => $response->refresh_token ?? null,
                                'company_id' => $response->companyId ?? null,
                                'user_id' => $response->userId ?? null,
                                'user_type' => $response->userType ?? 'location'
                            ]
                        );

                        Log::info('Real OAuth tokens saved successfully', [
                            'location_id' => $locationId,
                            'token_id' => $tokenRecord->id,
                            'access_token_length' => strlen($response->access_token),
                            'has_refresh_token' => !empty($response->refresh_token)
                        ]);

                        return response()->json([
                            'success' => true,
                            'message' => 'Real OAuth tokens retrieved and saved',
                            'data' => [
                                'location_id' => $locationId,
                                'token_id' => $tokenRecord->id,
                                'access_token' => [
                                    'length' => strlen($response->access_token),
                                    'preview' => substr($response->access_token, 0, 50) . '...',
                                    'is_jwt' => str_contains($response->access_token, '.'),
                                    'jwt_parts' => str_contains($response->access_token, '.') ? count(explode('.', $response->access_token)) : 0
                                ],
                                'refresh_token' => [
                                    'exists' => !empty($response->refresh_token),
                                    'length' => strlen($response->refresh_token ?? ''),
                                    'preview' => $response->refresh_token ? substr($response->refresh_token, 0, 50) . '...' : null
                                ],
                                'additional_fields' => [
                                    'companyId' => $response->companyId ?? null,
                                    'userId' => $response->userId ?? null,
                                    'userType' => $response->userType ?? null,
                                    'scope' => $response->scope ?? null
                                ]
                            ],
                            'raw_response_keys' => array_keys((array)$response)
                        ]);

                    } catch (\Exception $saveError) {
                        Log::error('Failed to save real OAuth tokens', [
                            'error' => $saveError->getMessage(),
                            'location_id' => $locationId
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => 'OAuth successful but failed to save to database',
                            'error' => $saveError->getMessage(),
                            'oauth_response' => $response
                        ], 500);
                    }
                } else {
                    // OAuth failed
                    Log::error('Real OAuth API call failed', [
                        'response' => $response,
                        'error' => $response->error ?? 'Unknown error',
                        'error_description' => $response->error_description ?? 'No description'
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'OAuth failed',
                        'error' => $response->error ?? 'Unknown error',
                        'error_description' => $response->error_description ?? 'No description',
                        'full_response' => $response,
                        'troubleshooting' => [
                            'code_expired' => 'Authorization codes expire in 60 seconds',
                            'code_used' => 'Codes can only be used once',
                            'redirect_uri_mismatch' => 'Check if redirect URI in HighLevel matches: ' . config('services.highlevel.redirect_uri'),
                            'client_credentials' => 'Verify client_id and client_secret are correct'
                        ]
                    ], 400);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No response from HighLevel OAuth API',
                    'troubleshooting' => [
                        'check_logs' => 'Check Laravel logs for CURL errors',
                        'network_issue' => 'Possible network connectivity issue'
                    ]
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Exception in real OAuth test', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Exception occurred',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    })->name('api.test.oauth.real');
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
            $response = Http::asForm()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ])
                ->post('https://api.msgsndr.com/oauth/token', [
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

    // Test shipping rates directly
    Route::get('shipping-rates/{locationId}', function($locationId) {
        try {
            $integration = \App\Models\ShippingIntegration::where('location_id', $locationId)->first();

            if (!$integration) {
                return response()->json(['error' => 'Integration not found'], 404);
            }

            if (!$integration->delyva_api_key) {
                return response()->json(['error' => 'No Delyva API key'], 400);
            }

            // Test Delyva API call
            $payload = [
                'customerId' => $integration->delyva_customer_id ? (int)$integration->delyva_customer_id : 1,
                'origin' => [
                    'address1' => 'Kuala Lumpur',
                    'city' => 'Kuala Lumpur',
                    'state' => 'Selangor',
                    'postcode' => '50000',
                    'country' => 'MY',
                ],
                'destination' => [
                    'address1' => 'Subang Jaya',
                    'city' => 'Subang Jaya',
                    'state' => 'Selangor',
                    'postcode' => '47400',
                    'country' => 'MY',
                ],
                'weight' => ['unit' => 'kg', 'value' => 1.0],
                'itemType' => 'PARCEL'
            ];

            $headers = [
                'X-Delyvax-Access-Token' => $integration->delyva_api_key,
                'Content-Type' => 'application/json',
            ];

            $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
                ->post('https://api.delyva.app/v1.0/service/instantQuote', $payload);

            return response()->json([
                'integration' => [
                    'location_id' => $integration->location_id,
                    'has_api_key' => !empty($integration->delyva_api_key),
                    'api_key_preview' => substr($integration->delyva_api_key, 0, 10) . '...',
                    'customer_id' => $integration->delyva_customer_id,
                    'shipping_enabled' => $integration->shipping_enabled
                ],
                'delyva_api_call' => [
                    'url' => 'https://api.delyva.app/v1.0/service/instantQuote',
                    'payload' => $payload,
                    'status' => $response->status(),
                    'success' => $response->successful(),
                    'response' => $response->json(),
                    'raw_body' => $response->body()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    })->name('test.shipping-rates');
});
