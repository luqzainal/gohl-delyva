<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\LocationTokens;

class HighLevelContextController extends Controller
{
    /**
     * Decrypt HighLevel context payload
     */
    public function decryptContext(Request $request)
    {
        $encryptedData = $request->input('encryptedData');
        
        if (!$encryptedData) {
            return response()->json([
                'error' => 'No encrypted data provided'
            ], 400);
        }

        try {
            // HighLevel shared secret untuk decrypt
            $sharedSecret = config('services.highlevel.shared_secret');
            
            if (!$sharedSecret) {
                Log::warning('HighLevel shared secret not configured');

                // Try to get the actual location_id from location_tokens table
                $locationToken = LocationTokens::first();

                if ($locationToken) {
                    Log::info('Using real location from database (no shared secret)', [
                        'location_id' => $locationToken->location_id
                    ]);

                    return response()->json([
                        'locationId' => $locationToken->location_id,
                        'userId' => $locationToken->user_id,
                        'companyId' => $locationToken->company_id,
                        'debug' => 'Using real location data from database (no shared secret configured)'
                    ]);
                }

                // Fallback untuk development - return mock data
                Log::warning('No shared secret configured and no location tokens - OAuth authentication required');

                return response()->json([
                    'locationId' => 'no_oauth_' . time(),
                    'userId' => 'no_oauth_user',
                    'companyId' => 'no_oauth_company',
                    'debug' => 'No shared secret configured and no location tokens found',
                    'action_required' => [
                        'message' => 'Please complete OAuth authentication first',
                        'oauth_url' => url('/oauth/highlevel/redirect'),
                        'instructions' => 'Visit the OAuth URL to authenticate with HighLevel'
                    ]
                ]);
            }

            // Decrypt menggunakan AES-256-CBC (format HighLevel)
            $decryptedData = $this->decryptHighLevelPayload($encryptedData, $sharedSecret);
            
            if (!$decryptedData) {
                throw new \Exception('Failed to decrypt payload');
            }

            $contextData = json_decode($decryptedData, true);
            
            if (!$contextData) {
                throw new \Exception('Invalid decrypted data format');
            }

            Log::info('HighLevel context decrypted successfully', [
                'locationId' => $contextData['locationId'] ?? 'unknown'
            ]);

            return response()->json([
                'locationId' => $contextData['locationId'] ?? null,
                'userId' => $contextData['userId'] ?? null,
                'companyId' => $contextData['companyId'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Error decrypting HighLevel context', [
                'error' => $e->getMessage(),
                'encrypted_data_length' => strlen($encryptedData)
            ]);

            // Try to get the actual location_id from location_tokens table
            $locationToken = LocationTokens::first();

            if ($locationToken) {
                Log::info('Using real location from database as fallback', [
                    'location_id' => $locationToken->location_id
                ]);

                return response()->json([
                    'locationId' => $locationToken->location_id,
                    'userId' => $locationToken->user_id,
                    'companyId' => $locationToken->company_id,
                    'debug' => 'Using real location data from database due to decryption error'
                ]);
            }

            // Final fallback for development if no location tokens exist
            Log::warning('No location tokens found in database - OAuth might not have completed');

            return response()->json([
                'locationId' => 'no_oauth_' . time(),
                'userId' => 'no_oauth_user',
                'companyId' => 'no_oauth_company',
                'debug' => 'No location tokens found - OAuth authentication required',
                'action_required' => [
                    'message' => 'Please complete OAuth authentication first',
                    'oauth_url' => url('/oauth/highlevel/redirect'),
                    'instructions' => 'Visit the OAuth URL to authenticate with HighLevel'
                ]
            ]);
        }
    }

    /**
     * Decrypt HighLevel payload menggunakan shared secret
     */
    private function decryptHighLevelPayload($encryptedData, $sharedSecret)
    {
        try {
            // HighLevel menggunakan base64 encoded encrypted data
            $encrypted = base64_decode($encryptedData);
            
            if (!$encrypted) {
                throw new \Exception('Invalid base64 data');
            }

            // Extract IV (first 16 bytes untuk AES-256-CBC)
            $iv = substr($encrypted, 0, 16);
            $encryptedPayload = substr($encrypted, 16);

            // Decrypt menggunakan AES-256-CBC
            $decrypted = openssl_decrypt(
                $encryptedPayload,
                'AES-256-CBC',
                $sharedSecret,
                OPENSSL_RAW_DATA,
                $iv
            );

            return $decrypted;

        } catch (\Exception $e) {
            Log::error('Decryption failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Test endpoint untuk check context tanpa encryption
     */
    public function testContext(Request $request)
    {
        $locationId = $request->input('locationId', 'test_location_manual');
        
        return response()->json([
            'locationId' => $locationId,
            'userId' => 'test_user_123',
            'companyId' => 'test_company_456',
            'message' => 'Test context - manual input'
        ]);
    }
}
