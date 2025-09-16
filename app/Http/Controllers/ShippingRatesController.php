<?php

namespace App\Http\Controllers;

use App\Models\ShippingIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShippingRatesController extends Controller
{
    /**
     * Callback endpoint untuk dapatkan kadar penghantaran dari Delyva
     * Dipanggil oleh HighLevel semasa checkout
     */
    public function getRatesCallback(Request $request)
    {
        Log::info('Shipping rates callback received', [
            'request_data' => $request->all()
        ]);

        $validator = Validator::make($request->all(), [
            'rate' => 'required|array',
            'rate.altId' => 'required|string',
            'rate.origin' => 'required|array',
            'rate.destination' => 'required|array',
            'rate.items' => 'required|array',
            'rate.items.*.name' => 'required|string',
            'rate.items.*.quantity' => 'required|integer|min:1',
            'rate.items.*.grams' => 'required|numeric|min:0',
            'rate.currency' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            Log::error('Shipping rates validation failed', [
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $rate = $request->input('rate');
        $locationId = $rate['altId'];
        $origin = $rate['origin'];
        $destination = $rate['destination'];
        $items = $rate['items'];

        // Dapatkan integration record
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration || !$integration->delyva_api_key) {
            Log::error('Integration or Delyva credentials not found', [
                'location_id' => $locationId
            ]);

            return response()->json([
                'error' => 'Integration not configured'
            ], 404);
        }

        // Semak jika shipping diaktifkan
        if (!$integration->shipping_enabled) {
            Log::info('Shipping disabled for location', [
                'location_id' => $locationId
            ]);

            return response()->json([
                'rates' => []  // Return empty rates jika disabled
            ]);
        }

        // Dapatkan kadar dari Delyva
        $rates = $this->fetchRatesFromDelyva($integration, $origin, $destination, $items);

        if (!$rates) {
            return response()->json([
                'error' => 'Failed to fetch rates from Delyva'
            ], 500);
        }

        // Format response mengikut format HighLevel
        $formattedRates = $this->formatRatesForHighLevel($rates);

        Log::info('Shipping rates returned', [
            'location_id' => $locationId,
            'rates_count' => count($formattedRates)
        ]);

        return response()->json([
            'rates' => $formattedRates
        ]);
    }

    /**
     * Dapatkan kadar dari Delyva API mengikut spesifikasi
     */
    private function fetchRatesFromDelyva($integration, $origin, $destination, $items)
    {
        // Kira total berat dalam gram
        $totalGrams = 0;
        foreach ($items as $item) {
            $totalGrams += $item['grams'] * $item['quantity'];
        }

        // Tukar gram ke kilogram
        $weightKg = $totalGrams / 1000;
        
        // Pastikan ada berat minimum
        if ($weightKg <= 0) {
            $weightKg = 0.1; // minimum 0.1kg
        }

        // Format data untuk Delyva API mengikut spesifikasi
        // Hanya include customerId jika ada dan valid
        $customerId = $integration->delyva_customer_id ? (int)$integration->delyva_customer_id : null;

        $quotePayload = [
            'origin' => [
                'address1' => $origin['address1'] ?? $origin['address'] ?? '',
                'city' => $origin['city'] ?? '',
                'state' => $origin['state'] ?? '',
                'postcode' => $origin['zip'] ?? $origin['postcode'] ?? '', // map zip to postcode
                'country' => $origin['country'] ?? 'MY',
            ],
            'destination' => [
                'address1' => $destination['address1'] ?? $destination['address'] ?? '',
                'city' => $destination['city'] ?? '',
                'state' => $destination['state'] ?? '',
                'postcode' => $destination['zip'] ?? $destination['postcode'] ?? '', // map zip to postcode
                'country' => $destination['country'] ?? 'MY',
            ],
            'weight' => [
                'unit' => 'kg',
                'value' => $weightKg
            ],
            'itemType' => 'PARCEL'
        ];

        // Hanya tambah customerId jika ada dan valid
        if ($customerId) {
            $quotePayload['customerId'] = $customerId;
        }

        $headers = [
            'X-Delyvax-Access-Token' => $integration->delyva_api_key,
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)
            ->post('https://api.delyva.app/v1.0/service/instantQuote', $quotePayload);

        if (!$response->successful()) {
            Log::error('Failed to fetch rates from Delyva', [
                'status' => $response->status(),
                'response' => $response->body(),
                'request' => $quotePayload
            ]);
            return null;
        }

        $responseData = $response->json();
        return $responseData['services'] ?? [];
    }

    /**
     * Format kadar untuk HighLevel mengikut spesifikasi
     */
    private function formatRatesForHighLevel($delyvaServices)
    {
        $rates = [];

        foreach ($delyvaServices as $service) {
            // Anggaran hari berdasarkan nama servis
            $estimatedDays = null;
            $serviceName = $service['serviceCompany']['name'] ?? 'Unknown Service';
            
            if (stripos($serviceName, 'instant') !== false) {
                $estimatedDays = 0;
            } elseif (stripos($serviceName, 'next-day') !== false || stripos($serviceName, 'nextday') !== false) {
                $estimatedDays = 1;
            } elseif (stripos($serviceName, 'express') !== false) {
                $estimatedDays = 1;
            }

            $rates[] = [
                'serviceName' => $serviceName,
                'amount' => $service['price']['amount'] ?? 0,
                'currency' => $service['price']['currency'] ?? 'MYR',
                'estimatedDays' => $estimatedDays
            ];
        }

        return $rates;
    }

    /**
     * Test endpoint untuk debug rates
     */
    public function testRates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'location_id' => 'required|string',
            'origin' => 'required|array',
            'destination' => 'required|array',
            'items' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // Simulate HighLevel request dengan format yang betul
        $testRequest = new Request([
            'rate' => [
                'altId' => $request->location_id,
                'origin' => $request->origin,
                'destination' => $request->destination,
                'items' => $request->items,
                'currency' => $request->currency ?? 'MYR'
            ]
        ]);

        return $this->getRatesCallback($testRequest);
    }

    /**
     * Dapatkan senarai kadar yang tersedia untuk location
     */
    public function getAvailableRates($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration || !$integration->delyva_api_key) {
            return response()->json([
                'error' => 'Integration not configured'
            ], 404);
        }

        // Sample origin dan destination untuk test
        $sampleOrigin = [
            'address' => 'Kuala Lumpur',
            'city' => 'Kuala Lumpur',
            'state' => 'Kuala Lumpur',
            'postcode' => '50000',
            'country' => 'MY'
        ];

        $sampleDestination = [
            'address' => 'Petaling Jaya',
            'city' => 'Petaling Jaya', 
            'state' => 'Selangor',
            'postcode' => '47400',
            'country' => 'MY'
        ];

        $sampleItems = [
            [
                'weight' => 1.0,
                'dimensions' => [
                    'length' => 10,
                    'width' => 10,
                    'height' => 10
                ]
            ]
        ];

        $rates = $this->fetchRatesFromDelyva($integration, $sampleOrigin, $sampleDestination, $sampleItems);

        if (!$rates) {
            return response()->json([
                'error' => 'Failed to fetch sample rates'
            ], 500);
        }

        return response()->json([
            'sample_rates' => $this->formatRatesForHighLevel($rates),
            'note' => 'These are sample rates from KL to PJ with 1kg package'
        ]);
    }

    /**
     * Debug endpoint untuk test Delyva API secara langsung
     */
    public function debugDelyvaApi($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();

        if (!$integration || !$integration->delyva_api_key) {
            return response()->json([
                'error' => 'Integration or API key not found'
            ], 404);
        }

        $quotePayload = [
            'customerId' => (int)($integration->delyva_customer_id ?? 1),
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
            'X-Delyvax-Access-Token' => $integration->delyva_api_key,
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)
            ->post('https://api.delyva.app/v1.0/service/instantQuote', $quotePayload);

        return response()->json([
            'request' => $quotePayload,
            'headers' => $headers,
            'response_status' => $response->status(),
            'response_body' => $response->json(),
            'successful' => $response->successful()
        ]);
    }
}
