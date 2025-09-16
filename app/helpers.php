<?php

use App\Models\AppCredentials;
use App\Models\Credentials;
use App\Models\LocationTokens;
use Illuminate\Support\Facades\Log;

function ghl_oauth_call($code = '', $method = '')
{
    $cred = getAppCredentials();

    // Use the official HighLevel OAuth endpoint
    $url = 'https://services.leadconnectorhq.com/oauth/token';
    $curl = curl_init();

    $data = [];
    $data['client_id'] = $cred['client_id'];
    $data['client_secret'] = $cred['client_secret'];

    if (empty($method)) {
        // Authorization code flow
        $data['grant_type'] = 'authorization_code';
        $data['code'] = $code;
        $data['redirect_uri'] = config('services.highlevel.redirect_uri');
        $data['user_type'] = 'Location'; // As per documentation
    } else {
        // Refresh token flow
        $data['grant_type'] = 'refresh_token';
        $data['refresh_token'] = $code;
        $data['user_type'] = 'Location';
    }

    $postv = '';
    $x = 0;

    foreach ($data as $key => $value) {
        if ($x > 0) {
            $postv .= '&';
        }
        $postv .= urlencode($key) . '=' . urlencode($value);
        $x++;
    }

    $curlfields = array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postv,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: Delyva-HighLevel-Integration/1.0'
        ],
    );
    curl_setopt_array($curl, $curlfields);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    Log::info('HighLevel OAuth API call', [
        'url' => $url,
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'raw_response' => $response,
        'response_length' => strlen($response ?? ''),
        'request_data' => [
            'client_id' => substr($data['client_id'], 0, 10) . '...',
            'grant_type' => $data['grant_type'],
            'user_type' => $data['user_type'] ?? 'not_set',
            'redirect_uri' => $data['redirect_uri'] ?? 'not_set',
            'has_code' => !empty($data['code']),
            'has_refresh_token' => !empty($data['refresh_token'])
        ]
    ]);

    if ($curlError) {
        Log::error('CURL error in OAuth call', ['error' => $curlError]);
        return null;
    }

    if (!$response) {
        Log::error('Empty response from HighLevel OAuth API');
        return null;
    }

    $decoded = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE) {
        Log::error('JSON decode error in OAuth response', [
            'json_error' => json_last_error_msg(),
            'raw_response' => $response
        ]);
        return null;
    }

    return $decoded;
}

// GHL GET Token
function ghl_token($request, $type = '')
{
    $code = $request->get('code');
    if (!$code) {
        if (empty($type)) {
            return null; // Don't abort, let controller handle error
        }
        return null;
    }
    
    $tokenResponse = ghl_oauth_call($code, $type);

    if ($tokenResponse) {
        if (property_exists($tokenResponse, 'access_token')) {
            if (empty($type)) {
                return $tokenResponse;
            }
        } else {
            if (property_exists($tokenResponse, 'error_description')) {
                Log::error('HighLevel OAuth error', [
                    'error' => $tokenResponse->error ?? 'Unknown error',
                    'error_description' => $tokenResponse->error_description ?? 'No description'
                ]);
                if (empty($type)) {
                    return null; // Don't abort, let controller handle error
                }
            }
            return null;
        }
    }
    
    if (empty($type)) {
        return null; // Don't abort, let controller handle error
    }
    return null;
}

// CRM API CALL
function ghl_api_call($url, $method, $data, $locationId, $json = false)
{
    $baseurl = 'https://services.leadconnectorhq.com/';

    $location = $locationId;
    $token = getLocationToken($location);

    $bearer = 'Bearer ' . $token->access_token;

    if (empty($token)) {
        
    }

    $version = get_default_settings('oauth_ghl_version', '2021-04-15');

    $headers = [
        'Version' => $version,
        'Authorization' => $bearer,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    if (strtolower($method) === 'get') {
        $url .= (strpos($url, '?') !== false) ? '&' : '?';
        if (strpos($url, 'locationId=') === false) {
            $url .= 'locationId=' . $location;
        }
    }

    $client = new \GuzzleHttp\Client(['http_errors' => false, 'headers' => $headers]);
    $options = [];
    if (!empty($data)) {
        $options['body'] = $data;
    }

    $url1 = $baseurl . $url;

    try {
        $response = $client->request($method, $url1, $options);
        $bd = $response->getBody()->getContents();
        $bd = json_decode($bd);
        if (isset($bd->error) && $bd->error === 'Unauthorized' || (isset($bd->message) && $bd->message === 'Invalid JWT')) {
            $refreshToken = getLocationToken($locationId);
            $newTokenRes = newAccessToken($refreshToken->refresh_token);
            saveNewAccessTokens($newTokenRes);
            $newToken = $newTokenRes->access_token;
            return ghl_api_call($url, $method, $data, $locationId, $json);
        }
        return $bd;
    } catch (\GuzzleHttp\Exception\RequestException $e) {
    } catch (\Exception $e) {
    }
}

// Get Default Setting
function get_default_settings($j, $k)
{
    return $k;
}

// Get Location Token
function getLocationToken($locationId)
{
    $setting_res = LocationTokens::where('location_id', $locationId)->first();
    if (!$setting_res) {
        return response()->json([
            'message' => "No user found.",
            'code' => 404,
        ], 404);
    }
    return $setting_res;
}

// Get New Access Token
function newAccessToken($refreshToken)
{
    $url = 'https://api.msgsndr.com/oauth/token';
    $creds = getAppCredentials();
    $data = [
        'client_id' => $creds['client_id'],
        'client_secret' => $creds['client_secret'],
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token'
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
    ]);

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response);
}

// Save a new access token
function saveNewAccessTokens($tokensData)
{
    try {
        // If locationId is not provided, we can't save tokens
        if (!property_exists($tokensData, 'locationId') || !$tokensData->locationId) {
            Log::error('Cannot save tokens: locationId missing', [
                'tokens_data' => $tokensData
            ]);
            return null;
        }

        $setting = LocationTokens::where('location_id', $tokensData->locationId)->first();

        if (!$setting) {
            $setting = new LocationTokens();
        }
        $setting->location_id = $tokensData->locationId;
        $setting->access_token = $tokensData->access_token;
        $setting->refresh_token = $tokensData->refresh_token ?? $setting->refresh_token;
        $setting->save();
        
        Log::info('Tokens refreshed and saved', [
            'location_id' => $tokensData->locationId,
            'setting_id' => $setting->id
        ]);
        
        return $setting;
    } catch (\Throwable $th) {
        Log::error('Failed to save new access tokens', [
            'error' => $th->getMessage(),
            'tokens_data' => $tokensData
        ]);
        return null;
    }
}

// Get Gateway Credentials
function getCredentials($location_Id)
{
    $Credentials = Credentials::where('location_id', $location_Id)->first();
    if (!$Credentials) {
        $Credentials = null;
    }
    return $Credentials;
}

// Get App Credentials
function getAppCredentials()
{
    $appCredential = AppCredentials::first();
    if (!$appCredential) {
        // Fallback to config if no database record
        return [
            'client_id' => config('services.highlevel.client_id'),
            'client_secret' => config('services.highlevel.client_secret')
        ];
    }

    // Convert model to array to ensure consistent access pattern
    return [
        'client_id' => $appCredential->client_id,
        'client_secret' => $appCredential->client_secret
    ];
}