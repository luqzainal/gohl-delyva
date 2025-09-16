<?php

namespace App\Http\Controllers;

use App\Models\ShippingIntegration;
use App\Models\LocationTokens;
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
        // Try multiple ways to get location_id from HighLevel
        $locationId = $request->get('location_id')
                   ?? $request->get('locationId')
                   ?? $request->get('loc_id')
                   ?? $request->get('ghl_location_id')
                   ?? $request->get('sub_account_id')
                   ?? $request->header('X-Location-Id')
                   ?? $request->header('X-GHL-Location-Id');

        // Extract location_id from URL if not in direct parameters
        if (!$locationId && $request->fullUrl()) {
            $url = $request->fullUrl();
            $patterns = [
                '/[?&]location_id=([^&]+)/',
                '/[?&]locationId=([^&]+)/',
                '/[?&]loc_id=([^&]+)/',
                '/[?&]ghl_location_id=([^&]+)/',
                '/[?&]sub_account_id=([^&]+)/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $url, $matches)) {
                    $locationId = $matches[1];
                    break;
                }
            }
        }

        // Try to extract from state parameter if present
        if (!$locationId && $request->get('state')) {
            $state = $request->get('state');
            // HighLevel sometimes embeds location_id in state parameter
            if (preg_match('/location_id[=:]([^&,;]+)/', $state, $matches)) {
                $locationId = $matches[1];
            }
        }

        Log::info('OAuth callback received', [
            'code' => $request->get('code') ? substr($request->get('code'), 0, 15) . '...' : null,
            'location_id' => $locationId,
            'full_url' => $request->fullUrl(),
            'all_params' => $request->all()
        ]);

        // Log location_id detection attempts
        Log::info('Location ID detection attempts', [
            'found_location_id' => $locationId,
            'all_params' => $request->all(),
            'headers_checked' => [
                'X-Location-Id' => $request->header('X-Location-Id'),
                'X-GHL-Location-Id' => $request->header('X-GHL-Location-Id')
            ]
        ]);

        $response = ghl_token($request);
        if ($response && property_exists($response, 'access_token')) {
            // If location_id still missing, try to get it from token response
            if (!$locationId) {
                $locationId = $response->locationId ?? $response->location_id ?? $response->subAccountId ?? null;

                Log::info('Extracted location_id from token response', [
                    'location_id' => $locationId,
                    'token_response_keys' => array_keys((array)$response)
                ]);
            }

            // Still no location_id? Try to get it from userInfo endpoint
            if (!$locationId && isset($response->access_token)) {
                $locationId = $this->getLocationIdFromUserInfo($response->access_token);
            }

            // Final check
            if (!$locationId) {
                Log::error('Could not determine location_id from any source', [
                    'request_params' => $request->all(),
                    'token_response' => $response
                ]);
                return redirect()->route('install.error')->with([
                    'error' => 'Missing location ID - cannot complete installation',
                    'errorId' => 'LOCATION_ID_MISSING_' . time()
                ]);
            }

            $response->locationId = $locationId;

            try {
                $this->saveTokens($response);
                Log::info('OAuth process completed successfully', [
                    'location_id' => $locationId
                ]);
                return redirect()->route('install.success')->with('locationId', $locationId);
            } catch (\Exception $e) {
                Log::error('Failed to save OAuth tokens', [
                    'location_id' => $locationId,
                    'error' => $e->getMessage()
                ]);
                return redirect()->route('install.error')->with([
                    'error' => 'Failed to save OAuth tokens: ' . $e->getMessage(),
                    'errorId' => 'SAVE_FAILED_' . time()
                ]);
            }
        }

        Log::error('OAuth token exchange failed', [
            'location_id' => $locationId,
            'response' => $response
        ]);

        return redirect()->route('install.error')->with([
            'error' => 'Failed to get valid OAuth token from HighLevel',
            'errorId' => 'TOKEN_INVALID_' . time()
        ]);
    }


    public function saveTokens($tokensData)
    {
        $setting = LocationTokens::where('location_id', $tokensData->locationId)->first();

        if (!$setting) {
            $setting = new LocationTokens();
        }

        $setting->location_id = $tokensData->locationId;
        $setting->company_id = $tokensData->companyId ?? null;
        $setting->user_id = $tokensData->userId ?? null;
        $setting->user_type = $tokensData->userType ?? null;
        $setting->access_token = $tokensData->access_token;
        $setting->refresh_token = $tokensData->refresh_token;
        $setting->save();

        Log::info('HighLevel OAuth tokens saved', [
            'location_id' => $tokensData->locationId,
            'setting_id' => $setting->id
        ]);
    }

    /**
     * Refresh HighLevel access token
     */
    public function refreshToken($locationId)
    {
        $locationToken = getLocationToken($locationId);

        if (!$locationToken || !$locationToken->refresh_token) {
            return response()->json(['error' => 'Location token not found or no refresh token'], 404);
        }

        $newTokenRes = newAccessToken($locationToken->refresh_token);
        
        if (!$newTokenRes || !property_exists($newTokenRes, 'access_token')) {
            return response()->json(['error' => 'Failed to refresh token'], 500);
        }

        saveNewAccessTokens($newTokenRes);

        return response()->json(['message' => 'Token refreshed successfully']);
    }

    /**
     * Get integration status
     */
    public function getIntegrationStatus($locationId)
    {
        $locationToken = getLocationToken($locationId);
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$locationToken) {
            return response()->json(['integrated' => false]);
        }

        return response()->json([
            'integrated' => !empty($locationToken->access_token),
            'has_delyva_credentials' => $integration ? !empty($integration->delyva_api_key) : false,
            'carrier_registered' => $integration ? !empty($integration->shipping_carrier_id) : false,
        ]);
    }

    /**
     * Get location_id from HighLevel user info endpoint
     */
    private function getLocationIdFromUserInfo($accessToken)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ])->get('https://services.leadconnectorhq.com/users/me');

            if ($response->successful()) {
                $userInfo = $response->json();

                Log::info('Retrieved user info from HighLevel', [
                    'user_info_keys' => array_keys($userInfo ?? [])
                ]);

                // Check various possible location_id fields
                $locationId = $userInfo['locationId']
                           ?? $userInfo['location_id']
                           ?? $userInfo['subAccountId']
                           ?? $userInfo['sub_account_id']
                           ?? $userInfo['accountId']
                           ?? null;

                // If user has locations array, get the first one
                if (!$locationId && isset($userInfo['locations']) && is_array($userInfo['locations'])) {
                    $locationId = $userInfo['locations'][0]['id'] ?? null;
                }

                return $locationId;
            }

            Log::error('Failed to get user info from HighLevel', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('Exception getting user info from HighLevel', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }
}
