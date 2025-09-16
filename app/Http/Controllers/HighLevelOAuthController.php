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
        $locationId = $request->get('location_id') ?? $request->get('locationId');
        
        // Extract location_id from URL if not in direct parameters
        if (!$locationId && $request->fullUrl()) {
            $url = $request->fullUrl();
            if (preg_match('/[?&]location_id=([^&]+)/', $url, $matches)) {
                $locationId = $matches[1];
            } elseif (preg_match('/[?&]locationId=([^&]+)/', $url, $matches)) {
                $locationId = $matches[1];
            }
        }

        Log::info('OAuth callback received', [
            'code' => $request->get('code') ? substr($request->get('code'), 0, 15) . '...' : null,
            'location_id' => $locationId,
            'full_url' => $request->fullUrl(),
            'all_params' => $request->all()
        ]);

        // Validate locationId
        if (!$locationId) {
            Log::error('OAuth callback missing location_id', [
                'full_url' => $request->fullUrl(),
                'all_params' => $request->all()
            ]);
            return redirect()->route('install.error')->with([
                'error' => 'Missing location ID in OAuth callback',
                'errorId' => 'LOCATION_ID_MISSING_' . time()
            ]);
        }

        $response = ghl_token($request);
        if ($response && property_exists($response, 'access_token')) {
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
}
