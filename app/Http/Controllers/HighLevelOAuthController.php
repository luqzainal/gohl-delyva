<?php

namespace App\Http\Controllers;

use App\Models\ShippingIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HighLevelOAuthController extends Controller
{
    /**
     * Redirect ke HighLevel OAuth
     */
    public function redirectToHighLevel()
    {
        $clientId = config('services.highlevel.client_id');
        $redirectUri = config('services.highlevel.redirect_uri');
        $scopes = 'locations.readonly locations/customFields.write locations/customFields.readonly locations/customValues.write locations/customValues.readonly contacts.readonly contacts.write conversations.readonly conversations.write conversations/message.readonly conversations/message.write opportunities.readonly opportunities.write calendars.readonly calendars.write calendars/events.readonly calendars/events.write forms.readonly forms.write surveys.readonly surveys.write products.readonly products.write invoices.readonly invoices.write payments.readonly workflows.readonly workflows.write triggers.readonly triggers.write funnels.readonly funnels.write websites.readonly websites.write medias.readonly medias.write links.readonly links.write courses.readonly courses.write oauth.readonly oauth.write saas/locations.readonly saas/locations.write businesses.readonly businesses.write snapshots.readonly snapshots.write social_media_posting.readonly social_media_posting.write reputation.readonly reputation.write reporting.readonly reporting.write content.readonly content.write recording.readonly recording.write marketing.readonly marketing.write eliza.readonly eliza.write dashboard.readonly dashboard.write reviews.readonly reviews.write membership.readonly membership.write affiliate-manager.readonly affiliate-manager.write blogging.readonly blogging.write';
        
        $authUrl = 'https://marketplace.leadconnectorhq.com/oauth/chooselocation?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
        ]);

        return redirect($authUrl);
    }

    /**
     * Handle callback dari HighLevel OAuth
     */
    public function handleCallback(Request $request)
    {
        $code = $request->get('code');
        $locationId = $request->get('location_id') ?? $request->get('locationId');
        $state = $request->get('state');
        
        Log::info('OAuth callback received', [
            'code' => $code ? substr($code, 0, 10) . '...' : null,
            'location_id' => $locationId,
            'state' => $state,
            'all_params' => $request->all()
        ]);

        if (!$code) {
            Log::error('OAuth callback missing authorization code', $request->all());
            return redirect()->route('install.error')->with([
                'error' => 'Missing authorization code',
                'errorId' => 'MISSING_CODE_' . time()
            ]);
        }

        // Jika tiada locationId, cuba extract dari state atau guna default
        if (!$locationId) {
            if ($state) {
                // Cuba decode state jika ada locationId di dalamnya
                $stateData = json_decode(base64_decode($state), true);
                $locationId = $stateData['locationId'] ?? null;
            }
            
            // Jika masih tiada, guna placeholder untuk marketplace installation
            if (!$locationId) {
                $locationId = 'marketplace_install_' . time();
                Log::warning('No locationId provided, using placeholder', ['locationId' => $locationId]);
            }
        }

        // Check jika dalam development mode (tiada credentials)
        $clientId = config('services.highlevel.client_id');
        $clientSecret = config('services.highlevel.client_secret');
        
        if (!$clientId || !$clientSecret) {
            Log::info('Development mode: Simulating OAuth token exchange', [
                'code' => substr($code, 0, 10) . '...',
                'location_id' => $locationId
            ]);
            
            // Simulate successful token response untuk development
            $tokenData = [
                'access_token' => 'dev_access_token_' . time(),
                'refresh_token' => 'dev_refresh_token_' . time(),
                'token_type' => 'Bearer',
                'expires_in' => 3600
            ];
        } else {
            // Production mode: Actual HighLevel API call
            $tokenResponse = Http::post('https://services.leadconnectorhq.com/oauth/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.highlevel.redirect_uri'),
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('HighLevel OAuth token exchange failed', [
                    'response' => $tokenResponse->body(),
                    'status' => $tokenResponse->status()
                ]);
                return redirect()->route('install.error')->with([
                    'error' => 'Failed to exchange authorization code with HighLevel',
                    'errorId' => 'TOKEN_EXCHANGE_FAILED_' . time()
                ]);
            }

            $tokenData = $tokenResponse->json();
        }

        try {
            // Simpan atau kemaskini integration record
            $integration = ShippingIntegration::updateOrCreate(
                ['location_id' => $locationId],
                [
                    'hl_access_token' => $tokenData['access_token'],
                    'hl_refresh_token' => $tokenData['refresh_token'] ?? null,
                ]
            );

            Log::info('HighLevel OAuth successful', [
                'location_id' => $locationId,
                'integration_id' => $integration->id
            ]);

            // Redirect ke success page
            return redirect()->route('install.success')->with([
                'locationId' => $locationId
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save integration record', [
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('install.error')->with([
                'error' => 'Failed to save integration data: ' . $e->getMessage(),
                'errorId' => 'SAVE_FAILED_' . time()
            ]);
        }
    }

    /**
     * Refresh HighLevel access token
     */
    public function refreshToken($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration || !$integration->hl_refresh_token) {
            return response()->json(['error' => 'Integration not found or no refresh token'], 404);
        }

        $tokenResponse = Http::post('https://services.leadconnectorhq.com/oauth/token', [
            'client_id' => config('services.highlevel.client_id'),
            'client_secret' => config('services.highlevel.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->hl_refresh_token,
        ]);

        if (!$tokenResponse->successful()) {
            Log::error('HighLevel token refresh failed', [
                'location_id' => $locationId,
                'response' => $tokenResponse->body()
            ]);
            return response()->json(['error' => 'Failed to refresh token'], 500);
        }

        $tokenData = $tokenResponse->json();

        $integration->update([
            'hl_access_token' => $tokenData['access_token'],
            'hl_refresh_token' => $tokenData['refresh_token'] ?? $integration->hl_refresh_token,
        ]);

        return response()->json(['message' => 'Token refreshed successfully']);
    }

    /**
     * Get integration status
     */
    public function getIntegrationStatus($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration) {
            return response()->json(['integrated' => false]);
        }

        return response()->json([
            'integrated' => !empty($integration->hl_access_token),
            'has_delyva_credentials' => !empty($integration->delyva_api_key),
            'carrier_registered' => !empty($integration->shipping_carrier_id),
        ]);
    }
}
