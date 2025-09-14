<?php

namespace App\Http\Controllers;

use App\Models\ShippingIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestController extends Controller
{
    /**
     * Test endpoint untuk credentials - dengan data dummy
     */
    public function testCredentials()
    {
        $testData = [
            'locationId' => 'test_location_123',
            'apiKey' => 'test_delyva_api_key_456',
            'customerId' => 'test_customer_789',
            'apiSecret' => 'test_secret_abc'
        ];

        Log::info('Testing credentials endpoint', $testData);

        // Simulate request
        $request = new Request($testData);
        
        // Call the actual controller
        $credentialsController = new \App\Http\Controllers\DelyvaCredentialsController();
        
        try {
            $response = $credentialsController->saveCredentials($request);
            
            return response()->json([
                'test' => 'credentials',
                'status' => 'success',
                'test_data' => $testData,
                'response' => $response->getData(),
                'database_check' => $this->checkDatabaseRecord($testData['locationId'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'test' => 'credentials',
                'status' => 'error',
                'error' => $e->getMessage(),
                'test_data' => $testData
            ], 500);
        }
    }

    /**
     * Test endpoint untuk rates callback
     */
    public function testRates()
    {
        $testData = [
            'rate' => [
                'altId' => 'test_location_123',
                'origin' => [
                    'address1' => 'Jalan Ampang',
                    'city' => 'Kuala Lumpur',
                    'state' => 'Kuala Lumpur',
                    'zip' => '50450',
                    'country' => 'MY'
                ],
                'destination' => [
                    'address1' => 'Jalan SS2/24',
                    'city' => 'Petaling Jaya',
                    'state' => 'Selangor',
                    'zip' => '47300',
                    'country' => 'MY'
                ],
                'items' => [
                    [
                        'name' => 'Test Product A',
                        'quantity' => 2,
                        'grams' => 500
                    ],
                    [
                        'name' => 'Test Product B',
                        'quantity' => 1,
                        'grams' => 1200
                    ]
                ],
                'currency' => 'USD'
            ]
        ];

        Log::info('Testing rates endpoint', $testData);

        // Simulate request
        $request = new Request($testData);
        
        // Call the actual controller
        $ratesController = new \App\Http\Controllers\ShippingRatesController();
        
        try {
            $response = $ratesController->getRatesCallback($request);
            
            return response()->json([
                'test' => 'rates',
                'status' => 'success',
                'test_data' => $testData,
                'response' => $response->getData()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'test' => 'rates',
                'status' => 'error',
                'error' => $e->getMessage(),
                'test_data' => $testData
            ], 500);
        }
    }

    /**
     * Test endpoint untuk webhook order
     */
    public function testWebhook()
    {
        $testData = [
            'type' => 'OrderCreate',
            'orderId' => 'test_order_456',
            'locationId' => 'test_location_123',
            'status' => 'completed'
        ];

        Log::info('Testing webhook endpoint', $testData);

        // Simulate request
        $request = new Request($testData);
        
        // Call the actual controller
        $webhookController = new \App\Http\Controllers\OrderWebhookController();
        
        try {
            $response = $webhookController->handleOrderWebhook($request);
            
            return response()->json([
                'test' => 'webhook',
                'status' => 'success',
                'test_data' => $testData,
                'response' => $response->getContent(),
                'status_code' => $response->getStatusCode()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'test' => 'webhook',
                'status' => 'error',
                'error' => $e->getMessage(),
                'test_data' => $testData
            ], 500);
        }
    }

    /**
     * Test method processOrderForShipping dengan mock data
     */
    public function testProcessOrder()
    {
        $locationId = 'test_location_123';
        $orderId = 'test_order_456';

        // Pastikan ada integration record untuk testing
        $integration = ShippingIntegration::updateOrCreate(
            ['location_id' => $locationId],
            [
                'delyva_api_key' => 'test_api_key',
                'delyva_customer_id' => '123',
                'hl_access_token' => 'test_hl_token'
            ]
        );

        Log::info('Testing processOrderForShipping', [
            'location_id' => $locationId,
            'order_id' => $orderId
        ]);

        // Create a mock webhook controller with public method for testing
        $controller = new class extends \App\Http\Controllers\OrderWebhookController {
            public function testProcessOrderForShipping($locationId, $orderId)
            {
                // Override method untuk testing tanpa panggil HL API
                $integration = ShippingIntegration::where('location_id', $locationId)->first();
                
                if (!$integration || !$integration->delyva_api_key) {
                    Log::error("No Delyva credentials for location $locationId");
                    return ['status' => 'error', 'message' => 'No credentials'];
                }

                // Mock order details (skip HL API call)
                $orderDetails = [
                    'orderNumber' => 'TEST-001',
                    'requiresShipping' => true,
                    'shipping' => [
                        'address' => [
                            'address1' => 'Test Address',
                            'city' => 'Petaling Jaya',
                            'state' => 'Selangor',
                            'zip' => '47300',
                            'country' => 'MY'
                        ]
                    ],
                    'contact' => [
                        'name' => 'Test Customer',
                        'phone' => '0123456789',
                        'email' => '[email protected]'
                    ]
                ];

                Log::info('Mock order details created', $orderDetails);

                // Simulate the rest of the process (tanpa actual API calls)
                return [
                    'status' => 'success',
                    'message' => 'Order processing simulated',
                    'order_details' => $orderDetails,
                    'integration_found' => true,
                    'delyva_api_key' => substr($integration->delyva_api_key, 0, 10) . '...',
                ];
            }
        };

        try {
            $result = $controller->testProcessOrderForShipping($locationId, $orderId);
            
            return response()->json([
                'test' => 'process_order',
                'status' => 'success',
                'result' => $result,
                'integration_id' => $integration->id
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'test' => 'process_order',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test semua endpoints sekaligus
     */
    public function testAll()
    {
        $results = [];

        // Test 1: Credentials
        try {
            $credentialsResponse = $this->testCredentials();
            $credentialsData = json_decode($credentialsResponse->getContent(), true);
            $results['credentials'] = $credentialsData ?: ['status' => 'error', 'error' => 'Invalid response'];
        } catch (\Exception $e) {
            $results['credentials'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // Test 2: Process Order
        try {
            $processResponse = $this->testProcessOrder();
            $processData = json_decode($processResponse->getContent(), true);
            $results['process_order'] = $processData ?: ['status' => 'error', 'error' => 'Invalid response'];
        } catch (\Exception $e) {
            $results['process_order'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // Test 3: Rates (skip jika credentials gagal)
        if (isset($results['credentials']['status']) && $results['credentials']['status'] === 'success') {
            try {
                $ratesResponse = $this->testRates();
                $ratesData = json_decode($ratesResponse->getContent(), true);
                $results['rates'] = $ratesData ?: ['status' => 'error', 'error' => 'Invalid response'];
            } catch (\Exception $e) {
                $results['rates'] = ['status' => 'error', 'error' => $e->getMessage()];
            }
        } else {
            $results['rates'] = ['status' => 'skipped', 'reason' => 'credentials test failed'];
        }

        // Test 4: Webhook
        try {
            $webhookResponse = $this->testWebhook();
            $webhookData = json_decode($webhookResponse->getContent(), true);
            $results['webhook'] = $webhookData ?: ['status' => 'error', 'error' => 'Invalid response'];
        } catch (\Exception $e) {
            $results['webhook'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        return response()->json([
            'test_suite' => 'all_backend_functions',
            'timestamp' => now()->toISOString(),
            'results' => $results,
            'summary' => $this->generateTestSummary($results)
        ]);
    }

    /**
     * Check database record
     */
    private function checkDatabaseRecord($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();
        
        return [
            'found' => $integration ? true : false,
            'location_id' => $integration->location_id ?? null,
            'has_delyva_key' => $integration && $integration->delyva_api_key ? true : false,
            'created_at' => $integration->created_at ?? null
        ];
    }

    /**
     * Generate test summary
     */
    private function generateTestSummary($results)
    {
        $total = count($results);
        $passed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($results as $test => $result) {
            if ($result['status'] === 'success') {
                $passed++;
            } elseif ($result['status'] === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
        }

        return [
            'total_tests' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'success_rate' => $total > 0 ? round(($passed / $total) * 100, 2) . '%' : '0%'
        ];
    }
}
