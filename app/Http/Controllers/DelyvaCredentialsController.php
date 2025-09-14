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

        // Validasi kredential dengan Delyva API
        $validationResult = $this->validateDelyvaCredentials($apiKey, $customerId, $apiSecret);

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
            // Jangan expose API key dan secret dalam response
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
            $request->apiSecret
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
    private function validateDelyvaCredentials($apiKey, $customerId = null, $apiSecret = null)
    {
        $headers = [
            'X-Delyvax-Access-Token' => $apiKey,
            'Content-Type' => 'application/json',
        ];

        // Test dengan endpoint user mengikut dokumentasi
        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->get('https://api.delyva.app/v1.0/user');

        Log::info('Delyva API validation attempt', [
            'api_key_preview' => substr($apiKey, 0, 10) . '...',
            'status' => $response->status(),
            'headers_sent' => $headers,
            'response_body' => $response->body(),
            'response_headers' => $response->headers()
        ]);

        if ($response->successful()) {
            $userData = $response->json();
            Log::info('Delyva credentials validation successful', [
                'status' => $response->status(),
                'user_data' => $userData
            ]);
            return [
                'valid' => true,
                'user_data' => $userData,
                'error' => null
            ];
        }

        // Detailed error messages based on status code
        $errorMessage = 'Unknown error occurred';
        switch ($response->status()) {
            case 401:
                $errorMessage = 'Invalid API key or unauthorized access';
                break;
            case 403:
                $errorMessage = 'API key is valid but access is forbidden. Check your API key permissions.';
                break;
            case 404:
                $errorMessage = 'API endpoint not found. Please verify the Delyva API version.';
                break;
            case 429:
                $errorMessage = 'Rate limit exceeded. Please try again later.';
                break;
            case 500:
                $errorMessage = 'Delyva API server error. Please try again later.';
                break;
            default:
                $errorMessage = 'API request failed with status ' . $response->status();
        }

        Log::warning('Delyva credentials validation failed', [
            'status' => $response->status(),
            'response' => $response->body(),
            'error_message' => $errorMessage
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
