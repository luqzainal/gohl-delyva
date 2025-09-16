<?php

namespace App\Http\Controllers;

use App\Models\ShippingIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DelyvaCredentialsController extends Controller
{
    /**
     * Simpan kredential Delyva
     */
    public function saveCredentials(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'locationId' => 'required|string',
            'apiKey' => 'required|string',
            'customerId' => 'nullable|string',
            'apiSecret' => 'nullable|string',
            'companyCode' => 'nullable|string',
            'companyId' => 'nullable|string',
            'userId' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $locationId = $request->locationId;
        $apiKey = $request->apiKey;
        $customerId = $request->customerId;
        $apiSecret = $request->apiSecret;
        $companyCode = $request->companyCode;
        $companyId = $request->companyId;
        $userId = $request->userId;

        // Validasi kredential dengan Delyva API
        $validationResult = $this->validateDelyvaCredentials($apiKey, $customerId, $apiSecret, $companyCode, $companyId, $userId);

        if (!$validationResult['valid']) {
            return response()->json([
                'error' => 'Invalid Delyva credentials'
            ], 400);
        }

        // Auto-populate customerId dari response jika tidak disediakan
        if (!$customerId && isset($validationResult['user_data']['id'])) {
            $customerId = $validationResult['user_data']['id'];
        }

        // Simpan kredential ke database
        $integration = ShippingIntegration::updateOrCreate(
            ['location_id' => $locationId],
            [
                'delyva_api_key' => $apiKey,
                'delyva_customer_id' => $customerId,
                'delyva_api_secret' => $apiSecret,
                'delyva_company_code' => $companyCode,
                'delyva_company_id' => $companyId,
                'delyva_user_id' => $userId,
            ]
        );

        Log::info('Delyva credentials saved', [
            'location_id' => $locationId,
            'integration_id' => $integration->id
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Delyva credentials saved successfully',
            'integration_id' => $integration->id
        ]);
    }

    /**
     * Toggle shipping enabled/disabled
     */
    public function toggleShipping(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'locationId' => 'required|string',
            'enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $locationId = $request->input('locationId');
        $enabled = $request->input('enabled');

        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration) {
            return response()->json([
                'error' => 'Integration not found'
            ], 404);
        }

        $integration->update([
            'shipping_enabled' => $enabled
        ]);

        Log::info('Shipping status toggled', [
            'location_id' => $locationId,
            'enabled' => $enabled,
            'integration_id' => $integration->id
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Shipping status updated successfully',
            'enabled' => $enabled
        ]);
    }

    /**
     * Dapatkan kredential Delyva untuk location
     */
    public function getCredentials($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration) {
            return response()->json([
                'error' => 'Integration not found'
            ], 404);
        }

        return response()->json([
            'has_credentials' => !empty($integration->delyva_api_key),
            'delyva_customer_id' => $integration->delyva_customer_id,
            'delyva_company_code' => $integration->delyva_company_code,
            'delyva_company_id' => $integration->delyva_company_id,
            'delyva_user_id' => $integration->delyva_user_id,
            'api_key_preview' => $integration->delyva_api_key ?
                substr($integration->delyva_api_key, 0, 10) . '...' : null,
            'has_api_secret' => !empty($integration->delyva_api_secret),
            // Jangan expose API key dan secret lengkap dalam response untuk security
        ]);
    }

    /**
     * Test kredential Delyva
     */
    public function testCredentials(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'apiKey' => 'required|string',
            'customerId' => 'nullable|string',
            'apiSecret' => 'nullable|string',
            'companyCode' => 'nullable|string',
            'companyId' => 'nullable|string',
            'userId' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $validationResult = $this->validateDelyvaCredentials(
            $request->apiKey,
            $request->customerId,
            $request->apiSecret,
            $request->companyCode,
            $request->companyId,
            $request->userId
        );

        return response()->json([
            'valid' => $validationResult['valid'],
            'message' => $validationResult['valid'] ? 'Credentials are valid' : ($validationResult['error'] ?? 'Invalid credentials'),
            'user_data' => $validationResult['user_data'],
            'error_details' => $validationResult['error'] ?? null
        ]);
    }

    /**
     * Padam kredential Delyva
     */
    public function deleteCredentials($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration) {
            return response()->json([
                'error' => 'Integration not found'
            ], 404);
        }

        $integration->update([
            'delyva_api_key' => null,
            'delyva_customer_id' => null,
            'delyva_api_secret' => null,
        ]);

        Log::info('Delyva credentials deleted', [
            'location_id' => $locationId,
            'integration_id' => $integration->id
        ]);

        return response()->json([
            'message' => 'Delyva credentials deleted successfully'
        ]);
    }

    /**
     * Validasi kredential Delyva dengan API mereka
     */
    private function validateDelyvaCredentials($apiKey, $customerId = null, $apiSecret = null, $companyCode = null, $companyId = null, $userId = null)
    {
        $headers = [
            'X-Delyvax-Access-Token' => $apiKey,
            'Content-Type' => 'application/json',
        ];

        // Simple validation with a basic endpoint that should work for all API keys
        $testPayload = [
            'customerId' => $customerId ? (int)$customerId : 1,
            'origin' => [
                'postcode' => '50000',
                'country' => 'MY',
            ],
            'destination' => [
                'postcode' => '47400',
                'country' => 'MY',
            ],
            'weight' => [
                'unit' => 'kg',
                'value' => 1.0
            ],
            'itemType' => 'PARCEL'
        ];

        // Add additional fields if provided
        if ($companyCode) {
            $testPayload['companyCode'] = $companyCode;
        }
        if ($companyId) {
            $testPayload['companyId'] = $companyId;
        }
        if ($userId) {
            $testPayload['userId'] = $userId;
        }

        // Try the instantQuote endpoint
        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->post('https://api.delyva.app/v1.0/service/instantQuote', $testPayload);

        Log::info('Delyva API validation attempt', [
            'api_key_preview' => substr($apiKey, 0, 10) . '...',
            'status' => $response->status(),
            'response_preview' => substr($response->body(), 0, 300),
            'payload_sent' => $testPayload
        ]);

        // Check if successful
        if ($response->successful()) {
            Log::info('Delyva credentials validation successful', [
                'status' => $response->status(),
                'api_key_preview' => substr($apiKey, 0, 10) . '...'
            ]);

            return [
                'valid' => true,
                'user_data' => $response->json(),
                'error' => null
            ];
        }

        // Check for "No service available" which indicates valid auth but no services
        $responseData = $response->json();
        if ($response->status() == 400 &&
            isset($responseData['error']['message']) &&
            $responseData['error']['message'] == 'No service available') {

            Log::info('Delyva credentials valid but no service available', [
                'api_key_preview' => substr($apiKey, 0, 10) . '...'
            ]);

            return [
                'valid' => true,
                'user_data' => ['message' => 'API key valid, no services available for this route'],
                'error' => null
            ];
        }

        // Handle specific error cases
        $errorMessage = 'Unknown error occurred';
        switch ($response->status()) {
            case 401:
                $errorMessage = 'Invalid API key. Please check your Delyva API key.';
                break;
            case 403:
                $errorMessage = 'Access forbidden. Your API key may be restricted or invalid.';
                break;
            case 404:
                $errorMessage = 'API endpoint not found. Please verify the Delyva API version.';
                break;
            case 422:
                $errorMessage = 'Invalid request data. Please check the payload format.';
                break;
            case 429:
                $errorMessage = 'Rate limit exceeded. Please try again later.';
                break;
            case 500:
                $errorMessage = 'Delyva API server error. Please try again later.';
                break;
            default:
                if (isset($responseData['error']['message'])) {
                    $errorMessage = 'API Error: ' . $responseData['error']['message'];
                } else {
                    $errorMessage = 'API request failed with status ' . $response->status();
                }
        }

        Log::warning('Delyva credentials validation failed', [
            'status' => $response->status(),
            'response' => $response->body(),
            'error_message' => $errorMessage,
            'api_key_preview' => substr($apiKey, 0, 10) . '...'
        ]);

        return [
            'valid' => false,
            'user_data' => null,
            'error' => $errorMessage
        ];
    }

    /**
     * Dapatkan senarai courier yang tersedia dari Delyva
     */
    public function getAvailableCouriers($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration || !$integration->delyva_api_key) {
            return response()->json([
                'error' => 'Delyva credentials not found'
            ], 404);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $integration->delyva_api_key,
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)
            ->get(config('services.delyva.base_url') . '/v1/couriers');

        if (!$response->successful()) {
            Log::error('Failed to fetch Delyva couriers', [
                'location_id' => $locationId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return response()->json([
                'error' => 'Failed to fetch couriers from Delyva'
            ], 500);
        }

        return response()->json($response->json());
    }
}
