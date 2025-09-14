<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
                
                // Fallback untuk development - return mock data
                return response()->json([
                    'locationId' => 'dev_location_' . time(),
                    'userId' => 'dev_user_123',
                    'companyId' => 'dev_company_456'
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

            // Fallback untuk development
            return response()->json([
                'locationId' => 'fallback_location_' . time(),
                'userId' => 'fallback_user',
                'companyId' => 'fallback_company',
                'debug' => 'Using fallback data due to decryption error'
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
