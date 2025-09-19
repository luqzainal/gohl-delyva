<?php

namespace App\Http\Controllers;

use App\Models\LocationTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CarrierRegistrationController extends Controller
{
    /**
     * Daftarkan Delyva sebagai custom carrier di HighLevel
     */
    public function registerCarrier($locationId)
    {
        $locationToken = LocationTokens::where('location_id', $locationId)->first();

        if (!$locationToken) {
            return response()->json([
                'error' => 'Location not found'
            ], 404);
        }

        if (!$locationToken->access_token) {
            return response()->json([
                'error' => 'HighLevel access token not found'
            ], 400);
        }

        if (!$locationToken->delyva_api_key) {
            return response()->json([
                'error' => 'Delyva credentials not found'
            ], 400);
        }

        // Jika carrier sudah didaftarkan, return existing
        if ($locationToken->shipping_carrier_id) {
            return response()->json([
                'message' => 'Carrier already registered',
                'carrier_id' => $locationToken->shipping_carrier_id
            ]);
        }

        $carrierData = [
            'altId' => $locationId,
            'altType' => 'location',
            'name' => 'Delyva Shipping',
            'callbackUrl' => config('app.url') . '/api/shipping/rates/callback',
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $locationToken->access_token,
            'Content-Type' => 'application/json',
            'Version' => '2021-07-28',
        ];

        $response = Http::withHeaders($headers)
            ->post('https://services.leadconnectorhq.com/store/shipping-carrier', $carrierData);

        if (!$response->successful()) {
            // Jika token expired, cuba refresh
            if ($response->status() === 401) {
                $refreshResult = $this->refreshTokenAndRetry($locationToken, $locationId, $carrierData);
                if ($refreshResult) {
                    return $refreshResult;
                }
            }

            Log::error('Failed to register carrier with HighLevel', [
                'location_id' => $locationId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return response()->json([
                'error' => 'Failed to register carrier with HighLevel',
                'details' => $response->json()
            ], 500);
        }

        $carrierResponse = $response->json();
        $carrierId = $carrierResponse['data']['_id'] ?? $carrierResponse['id'] ?? null;

        if (!$carrierId) {
            return response()->json([
                'error' => 'Carrier registration successful but no ID returned'
            ], 500);
        }

        // Simpan carrier ID ke database
        $locationToken->update([
            'shipping_carrier_id' => $carrierId
        ]);

        Log::info('Carrier registered successfully', [
            'location_id' => $locationId,
            'carrier_id' => $carrierId,
            'location_token_id' => $locationToken->id
        ]);

        return response()->json([
            'message' => 'Carrier registered successfully',
            'carrier_id' => $carrierId,
            'carrier_data' => $carrierResponse
        ]);
    }

    /**
     * Dapatkan status lengkap integration untuk location
     */
    public function getIntegrationStatus($locationId)
    {
        $locationToken = LocationTokens::where('location_id', $locationId)->first();

        $status = [
            'location_id' => $locationId,
            'integration_exists' => false,
            'has_delyva_credentials' => false,
            'has_highlevel_token' => false,
            'carrier_registered' => false,
            'carrier_id' => null,
            'shipping_enabled' => true,
            'delyva_api_test' => null,
            'created_at' => null,
            'updated_at' => null,
        ];

        if (!$locationToken) {
            return response()->json([
                'status' => $status,
                'message' => 'No location token found for this location'
            ]);
        }

        $status['integration_exists'] = true;
        $status['has_delyva_credentials'] = !empty($locationToken->delyva_api_key);
        $status['has_highlevel_token'] = !empty($locationToken->access_token);
        $status['carrier_registered'] = !empty($locationToken->shipping_carrier_id);
        $status['carrier_id'] = $locationToken->shipping_carrier_id;
        $status['shipping_enabled'] = $locationToken->shipping_enabled ?? true;
        $status['created_at'] = $locationToken->created_at;
        $status['updated_at'] = $locationToken->updated_at;

        // Test Delyva API jika ada credentials
        if ($status['has_delyva_credentials']) {
            $testPayload = [
                'customerId' => (int)($locationToken->delyva_customer_id ?? 1),
                'origin' => [
                    'address1' => 'Kuala Lumpur',
                    'city' => 'Kuala Lumpur',
                    'state' => 'Kuala Lumpur',
                    'postcode' => '50000',
                    'country' => 'MY',
                ],
                'destination' => [
                    'address1' => 'Petaling Jaya',
                    'city' => 'Petaling Jaya',
                    'state' => 'Selangor',
                    'postcode' => '47400',
                    'country' => 'MY',
                ],
                'weight' => [
                    'unit' => 'kg',
                    'value' => 1.0
                ],
                'itemType' => 'PARCEL'
            ];

            $headers = [
                'X-Delyvax-Access-Token' => $locationToken->delyva_api_key,
                'Content-Type' => 'application/json',
            ];

            $response = Http::withHeaders($headers)
                ->post('https://api.delyva.app/v1.0/service/instantQuote', $testPayload);

            $status['delyva_api_test'] = [
                'success' => $response->successful() ||
                           ($response->status() == 400 &&
                            isset($response->json()['error']['message']) &&
                            $response->json()['error']['message'] == 'No service available'),
                'status_code' => $response->status(),
                'message' => $response->json()['error']['message'] ?? 'API call successful'
            ];
        }

        return response()->json([
            'status' => $status,
            'message' => 'Integration status retrieved successfully'
        ]);
    }

    /**
     * Dapatkan maklumat carrier yang didaftarkan
     */
    public function getCarrierInfo($locationId)
    {
        $locationToken = LocationTokens::where('location_id', $locationId)->first();

        if (!$locationToken || !$locationToken->shipping_carrier_id) {
            return response()->json([
                'error' => 'Carrier not registered'
            ], 404);
        }

        if (!$locationToken->access_token) {
            return response()->json([
                'error' => 'HighLevel access token not found'
            ], 400);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $locationToken->access_token,
            'Content-Type' => 'application/json',
            'Version' => '2021-07-28',
        ];

        $response = Http::withHeaders($headers)
            ->get('https://services.leadconnectorhq.com/store/shipping-carrier/' . $locationToken->shipping_carrier_id);

        if (!$response->successful()) {
            Log::error('Failed to get carrier info from HighLevel', [
                'location_id' => $locationId,
                'carrier_id' => $locationToken->shipping_carrier_id,
                'status' => $response->status()
            ]);

            return response()->json([
                'error' => 'Failed to get carrier info'
            ], 500);
        }

        return response()->json($response->json());
    }

    /**
     * Kemaskini carrier settings
     */
    public function updateCarrier(Request $request, $locationId)
    {
        $locationToken = LocationTokens::where('location_id', $locationId)->first();

        if (!$locationToken || !$locationToken->shipping_carrier_id) {
            return response()->json([
                'error' => 'Carrier not registered'
            ], 404);
        }

        if (!$locationToken->access_token) {
            return response()->json([
                'error' => 'HighLevel access token not found'
            ], 400);
        }

        $updateData = $request->only([
            'name',
            'description', 
            'isActive',
            'supportedServices'
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $locationToken->access_token,
            'Content-Type' => 'application/json',
            'Version' => '2021-07-28',
        ];

        $response = Http::withHeaders($headers)
            ->put('https://services.leadconnectorhq.com/store/shipping-carrier/' . $locationToken->shipping_carrier_id, $updateData);

        if (!$response->successful()) {
            Log::error('Failed to update carrier in HighLevel', [
                'location_id' => $locationId,
                'carrier_id' => $locationToken->shipping_carrier_id,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return response()->json([
                'error' => 'Failed to update carrier'
            ], 500);
        }

        return response()->json([
            'message' => 'Carrier updated successfully',
            'carrier_data' => $response->json()
        ]);
    }

    /**
     * Nyahaktifkan carrier
     */
    public function deactivateCarrier($locationId)
    {
        $locationToken = LocationTokens::where('location_id', $locationId)->first();

        if (!$locationToken || !$locationToken->shipping_carrier_id) {
            return response()->json([
                'error' => 'Carrier not registered'
            ], 404);
        }

        if (!$locationToken->access_token) {
            return response()->json([
                'error' => 'HighLevel access token not found'
            ], 400);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $locationToken->access_token,
            'Content-Type' => 'application/json',
            'Version' => '2021-07-28',
        ];

        $response = Http::withHeaders($headers)
            ->put('https://services.leadconnectorhq.com/store/shipping-carrier/' . $locationToken->shipping_carrier_id, [
                'isActive' => false
            ]);

        if (!$response->successful()) {
            Log::error('Failed to deactivate carrier in HighLevel', [
                'location_id' => $locationId,
                'carrier_id' => $locationToken->shipping_carrier_id,
                'status' => $response->status()
            ]);

            return response()->json([
                'error' => 'Failed to deactivate carrier'
            ], 500);
        }

        Log::info('Carrier deactivated', [
            'location_id' => $locationId,
            'carrier_id' => $locationToken->shipping_carrier_id
        ]);

        return response()->json([
            'message' => 'Carrier deactivated successfully'
        ]);
    }

    /**
     * Padam carrier registration
     */
    public function unregisterCarrier($locationId)
    {
        $locationToken = LocationTokens::where('location_id', $locationId)->first();

        if (!$locationToken || !$locationToken->shipping_carrier_id) {
            return response()->json([
                'error' => 'Carrier not registered'
            ], 404);
        }

        if (!$locationToken->access_token) {
            return response()->json([
                'error' => 'HighLevel access token not found'
            ], 400);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $locationToken->access_token,
            'Content-Type' => 'application/json',
            'Version' => '2021-07-28',
        ];

        $response = Http::withHeaders($headers)
            ->delete('https://services.leadconnectorhq.com/store/shipping-carrier/' . $locationToken->shipping_carrier_id);

        if (!$response->successful()) {
            Log::error('Failed to unregister carrier from HighLevel', [
                'location_id' => $locationId,
                'carrier_id' => $locationToken->shipping_carrier_id,
                'status' => $response->status()
            ]);

            return response()->json([
                'error' => 'Failed to unregister carrier'
            ], 500);
        }

        // Clear carrier ID dari database
        $locationToken->update([
            'shipping_carrier_id' => null
        ]);

        Log::info('Carrier unregistered', [
            'location_id' => $locationId,
            'location_token_id' => $locationToken->id
        ]);

        return response()->json([
            'message' => 'Carrier unregistered successfully'
        ]);
    }

    /**
     * Refresh token dan cuba semula request
     */
    private function refreshTokenAndRetry($locationToken, $locationId, $carrierData)
    {
        if (!$locationToken->refresh_token) {
            return null;
        }

        // Refresh token using helper function
        $newTokenRes = newAccessToken($locationToken->refresh_token);
        
        if (!$newTokenRes || !property_exists($newTokenRes, 'access_token')) {
            return null;
        }

        // Save new tokens
        saveNewAccessTokens($newTokenRes);

        // Cuba semula request dengan token baru
        $headers = [
            'Authorization' => 'Bearer ' . $newTokenRes->access_token,
            'Content-Type' => 'application/json',
            'Version' => '2021-07-28',
        ];

        $response = Http::withHeaders($headers)
            ->post('https://services.leadconnectorhq.com/store/shipping-carrier', $carrierData);

        if ($response->successful()) {
            $carrierResponse = $response->json();
            $carrierId = $carrierResponse['data']['_id'] ?? $carrierResponse['id'] ?? null;

            if ($carrierId) {
                $locationToken->update(['shipping_carrier_id' => $carrierId]);
                
                return response()->json([
                    'message' => 'Carrier registered successfully after token refresh',
                    'carrier_id' => $carrierId,
                    'carrier_data' => $carrierResponse
                ]);
            }
        }

        return null;
    }
}
