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

    /**
     * Cari location ID yang sebenar dengan OAuth token
     */
    public function findIntegratedLocation(Request $request)
    {
        $attemptedLocationId = $request->input('attempted_location_id');

        Log::info('Finding integrated location', [
            'attempted_location_id' => $attemptedLocationId,
            'timestamp' => $request->input('timestamp')
        ]);

        try {
            // Cari location tokens yang ada dan terbaru
            $locationToken = LocationTokens::whereNotNull('access_token')
                ->orderBy('updated_at', 'desc')
                ->first();

            if ($locationToken) {
                // Jika location token yang ditemui berbeza dengan yang dicuba
                if ($locationToken->location_id !== $attemptedLocationId) {
                    Log::info('Found different integrated location', [
                        'attempted' => $attemptedLocationId,
                        'found' => $locationToken->location_id
                    ]);

                    return response()->json([
                        'location_id' => $locationToken->location_id,
                        'message' => 'Found integrated location',
                        'changed' => true
                    ]);
                }

                return response()->json([
                    'location_id' => $locationToken->location_id,
                    'message' => 'Location ID confirmed',
                    'changed' => false
                ]);
            }

            // Tiada location token ditemui
            Log::warning('No integrated location found', [
                'attempted_location_id' => $attemptedLocationId
            ]);

            return response()->json([
                'location_id' => null,
                'message' => 'No integrated location found',
                'changed' => false
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error finding integrated location', [
                'error' => $e->getMessage(),
                'attempted_location_id' => $attemptedLocationId
            ]);

            return response()->json([
                'location_id' => null,
                'message' => 'Error finding integrated location',
                'error' => $e->getMessage(),
                'changed' => false
            ], 500);
        }
    }

    /**
     * Sync location context untuk session-based user mapping
     * Ini untuk handle multiple users/locations dengan session tracking
     */
    public function syncLocationContext(Request $request)
    {
        $originalLocationId = $request->input('original_location_id');
        $sessionId = $request->input('browser_session_id');
        $timestamp = $request->input('timestamp');

        Log::info('Syncing location context', [
            'original_location_id' => $originalLocationId,
            'session_id' => $sessionId,
            'timestamp' => $timestamp,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        try {
            // 1. Check jika original location ID sudah integrated
            $originalToken = LocationTokens::where('location_id', $originalLocationId)
                ->whereNotNull('access_token')
                ->first();

            if ($originalToken) {
                Log::info('Original location ID is already integrated', [
                    'location_id' => $originalLocationId
                ]);

                return response()->json([
                    'corrected_location_id' => $originalLocationId,
                    'message' => 'Original location ID is correct',
                    'changed' => false,
                    'source' => 'original_verified'
                ]);
            }

            // 2. Check session storage untuk mapping yang ada
            $sessionKey = 'session_location_' . $sessionId;
            $cachedLocationId = cache()->get($sessionKey);

            if ($cachedLocationId) {
                // Verify cache location masih valid
                $cachedToken = LocationTokens::where('location_id', $cachedLocationId)
                    ->whereNotNull('access_token')
                    ->first();

                if ($cachedToken) {
                    Log::info('Found valid cached location for session', [
                        'session_id' => $sessionId,
                        'cached_location_id' => $cachedLocationId
                    ]);

                    return response()->json([
                        'corrected_location_id' => $cachedLocationId,
                        'message' => 'Found cached location for session',
                        'changed' => $cachedLocationId !== $originalLocationId,
                        'source' => 'session_cache'
                    ]);
                }
            }

            // 3. Cari location yang baru sahaja created (dalam 10 minit terakhir)
            // untuk handle fresh OAuth completion
            $recentToken = LocationTokens::whereNotNull('access_token')
                ->where('updated_at', '>=', now()->subMinutes(10))
                ->orderBy('updated_at', 'desc')
                ->first();

            if ($recentToken) {
                // Cache mapping untuk session ini
                cache()->put($sessionKey, $recentToken->location_id, now()->addHours(24));

                Log::info('Found recent OAuth completion, mapping to session', [
                    'session_id' => $sessionId,
                    'recent_location_id' => $recentToken->location_id,
                    'original_attempted' => $originalLocationId
                ]);

                return response()->json([
                    'corrected_location_id' => $recentToken->location_id,
                    'message' => 'Found recent OAuth completion',
                    'changed' => $recentToken->location_id !== $originalLocationId,
                    'source' => 'recent_oauth'
                ]);
            }

            // 4. No correction needed, return original
            Log::info('No location correction needed', [
                'original_location_id' => $originalLocationId,
                'session_id' => $sessionId
            ]);

            return response()->json([
                'corrected_location_id' => $originalLocationId,
                'message' => 'No correction needed',
                'changed' => false,
                'source' => 'no_change'
            ]);

        } catch (\Exception $e) {
            Log::error('Error syncing location context', [
                'error' => $e->getMessage(),
                'original_location_id' => $originalLocationId,
                'session_id' => $sessionId
            ]);

            return response()->json([
                'corrected_location_id' => $originalLocationId,
                'message' => 'Error syncing context, using original',
                'changed' => false,
                'source' => 'error_fallback',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
