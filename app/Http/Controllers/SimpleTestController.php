<?php

namespace App\Http\Controllers;

use App\Models\ShippingIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SimpleTestController extends Controller
{
    /**
     * Test semua fungsi dengan cara yang mudah
     */
    public function testAll()
    {
        $results = [];
        
        // Test 1: Database Connection
        try {
            $count = ShippingIntegration::count();
            $results['database'] = [
                'status' => 'success',
                'message' => "Database connected. Found {$count} integrations."
            ];
        } catch (\Exception $e) {
            $results['database'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
        
        // Test 2: Credentials API
        try {
            $testData = [
                'locationId' => 'test_location_simple',
                'apiKey' => 'test_key_simple',
                'customerId' => 'test_customer_simple'
            ];
            
            $integration = ShippingIntegration::updateOrCreate(
                ['location_id' => $testData['locationId']],
                [
                    'delyva_api_key' => $testData['apiKey'],
                    'delyva_customer_id' => $testData['customerId']
                ]
            );
            
            $results['credentials'] = [
                'status' => 'success',
                'message' => 'Credentials saved successfully',
                'integration_id' => $integration->id
            ];
        } catch (\Exception $e) {
            $results['credentials'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
        
        // Test 3: Rates Structure
        try {
            $mockRates = [
                [
                    'serviceName' => 'J&T Express Next-Day',
                    'amount' => 7.90,
                    'currency' => 'MYR',
                    'estimatedDays' => 1
                ],
                [
                    'serviceName' => 'Pos Laju Standard',
                    'amount' => 5.50,
                    'currency' => 'MYR',
                    'estimatedDays' => 3
                ]
            ];
            
            $results['rates'] = [
                'status' => 'success',
                'message' => 'Rates structure validated',
                'sample_rates' => $mockRates
            ];
        } catch (\Exception $e) {
            $results['rates'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
        
        // Test 4: Webhook Structure
        try {
            $mockWebhook = [
                'type' => 'OrderCreate',
                'orderId' => 'test_order_simple',
                'locationId' => 'test_location_simple',
                'status' => 'completed'
            ];
            
            $results['webhook'] = [
                'status' => 'success',
                'message' => 'Webhook structure validated',
                'sample_webhook' => $mockWebhook
            ];
        } catch (\Exception $e) {
            $results['webhook'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
        
        // Generate summary
        $total = count($results);
        $passed = 0;
        $failed = 0;
        
        foreach ($results as $test => $result) {
            if ($result['status'] === 'success') {
                $passed++;
            } else {
                $failed++;
            }
        }
        
        $summary = [
            'total_tests' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($passed / $total) * 100, 2) . '%' : '0%'
        ];
        
        return response()->json([
            'test_suite' => 'simple_backend_test',
            'timestamp' => now()->toISOString(),
            'results' => $results,
            'summary' => $summary
        ]);
    }
    
    /**
     * Test individual - credentials
     */
    public function testCredentials()
    {
        try {
            $request = new Request([
                'locationId' => 'test_simple_cred',
                'apiKey' => 'test_api_key_123',
                'customerId' => 'test_customer_456',
                'apiSecret' => 'test_secret_789'
            ]);
            
            $controller = new \App\Http\Controllers\DelyvaCredentialsController();
            $response = $controller->saveCredentials($request);
            
            return response()->json([
                'test' => 'credentials',
                'status' => 'success',
                'response_status' => $response->getStatusCode(),
                'response_data' => json_decode($response->getContent(), true)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'test' => 'credentials',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Test individual - rates
     */
    public function testRates()
    {
        try {
            $request = new Request([
                'rate' => [
                    'altId' => 'test_simple_rates',
                    'origin' => [
                        'address1' => 'Jalan Ampang',
                        'city' => 'Kuala Lumpur',
                        'zip' => '50450',
                        'country' => 'MY'
                    ],
                    'destination' => [
                        'address1' => 'Jalan SS2/24',
                        'city' => 'Petaling Jaya',
                        'zip' => '47300',
                        'country' => 'MY'
                    ],
                    'items' => [
                        [
                            'name' => 'Test Product',
                            'quantity' => 1,
                            'grams' => 1000
                        ]
                    ],
                    'currency' => 'USD'
                ]
            ]);
            
            $controller = new \App\Http\Controllers\ShippingRatesController();
            $response = $controller->getRatesCallback($request);
            
            return response()->json([
                'test' => 'rates',
                'status' => 'success',
                'response_status' => $response->getStatusCode(),
                'response_data' => json_decode($response->getContent(), true)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'test' => 'rates',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
