<?php

namespace App\Http\Controllers;

use App\Models\ShippingIntegration;
use App\Models\ShippingOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderWebhookController extends Controller
{
    /**
     * Handle webhook order dari HighLevel
     */
    public function handleOrderWebhook(Request $request)
    {
        Log::info('Order webhook received from HighLevel', [
            'headers' => $request->headers->all(),
            'payload' => $request->all()
        ]);

        // Validate webhook signature jika perlu
        // $this->validateWebhookSignature($request);

        $payload = $request->all();
        
        // Extract data mengikut format HL webhook
        $type = $payload['type'] ?? '';
        $orderId = $payload['orderId'] ?? '';
        $locationId = $payload['locationId'] ?? ($payload['altId'] ?? '');
        $status = $payload['status'] ?? '';

        $validator = Validator::make($payload, [
            'type' => 'required|string',
            'orderId' => 'required|string',
            'status' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            Log::error('Order webhook validation failed', [
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'error' => 'Validation failed'
            ], 422);
        }

        // Hanya process order yang completed (selesai pembayaran)
        if ($type === 'OrderCreate' || $type === 'OrderStatusUpdate') {
            if ($status === 'completed' && $orderId) {
                // Masuk proses cipta penghantaran
                $this->processOrderForShipping($locationId, $orderId);
            }
        }

        return response('OK', 200);
    }

    /**
     * Process order untuk shipping mengikut spesifikasi 4.5
     */
    private function processOrderForShipping($locationId, $orderId)
    {
        // Dapatkan rekod integrasi
        $integration = ShippingIntegration::where('location_id', $locationId)->first();
        
        if (!$integration || !$integration->delyva_api_key) {
            Log::error("No Delyva credentials for location $locationId");
            return;
        }

        // Panggil API HL untuk maklumat penuh order
        $hlToken = $integration->hl_access_token;
        $orderResponse = Http::withToken($hlToken)
            ->get("https://services.leadconnectorhq.com/payments/orders/$orderId");

        if (!$orderResponse->successful()) {
            Log::error("Failed to get order details from HL", [
                'location_id' => $locationId,
                'order_id' => $orderId,
                'status' => $orderResponse->status()
            ]);
            return;
        }

        $orderDetails = $orderResponse->json();

        if (empty($orderDetails) || ($orderDetails['requiresShipping'] ?? true) === false) {
            Log::info("Order $orderId not requiring shipping, skip.");
            return;
        }

        // Check jika order sudah diproses
        $existingOrder = ShippingOrder::where('location_id', $locationId)
            ->where('hl_order_id', $orderId)
            ->first();

        if ($existingOrder && $existingOrder->delyva_order_id) {
            Log::info("Order $orderId already processed with Delyva");
            return;
        }

        // Dapatkan butiran penghantaran
        $originAddr = $orderDetails['shipping']['origin'] ?? $this->getStoreAddress($integration, $locationId);
        $destAddr = $orderDetails['shipping']['address'] ?? $orderDetails['address'];
        
        // Dapatkan pilihan shipping yang dipilih customer
        $chosenService = $orderDetails['shipping']['methodName'] ?? null;
        
        // Default service code (untuk tutorial, kita guna default)
        $serviceCode = 'JNT-NDD'; // Default ke J&T Next Day

        // Sediakan payload untuk cipta order Delyva
        $orderPayload = [
            'customerId' => (int)$integration->delyva_customer_id,
            'process' => false, // buat draft dulu
            'serviceCode' => $serviceCode,
            'source' => 'HighLevel',
            'referenceNo' => $orderDetails['orderNumber'] ?? "HL-$orderId",
            'note' => '',
            'waypoint' => [
                [
                    'type' => 'PICKUP',
                    'address1' => $originAddr['address1'] ?? 'Address Line 1',
                    'city' => $originAddr['city'] ?? 'City',
                    'state' => $originAddr['state'] ?? 'State',
                    'postcode' => $originAddr['postcode'] ?? $originAddr['zip'] ?? '',
                    'country' => $originAddr['country'] ?? 'MY',
                    'contact' => [
                        'name' => $originAddr['name'] ?? 'Sender Name',
                        'phone' => $originAddr['phone'] ?? '000',
                        'email' => $originAddr['email'] ?? '[email protected]'
                    ],
                    'inventory' => []
                ],
                [
                    'type' => 'DROPOFF',
                    'address1' => $destAddr['address1'] ?? $destAddr['address'],
                    'city' => $destAddr['city'],
                    'state' => $destAddr['state'],
                    'postcode' => $destAddr['postcode'] ?? $destAddr['zip'],
                    'country' => $destAddr['country'] ?? 'MY',
                    'contact' => [
                        'name' => $destAddr['name'] ?? $orderDetails['contact']['name'],
                        'phone' => $destAddr['phone'] ?? $orderDetails['contact']['phone'],
                        'email' => $destAddr['email'] ?? $orderDetails['contact']['email']
                    ],
                    'inventory' => []
                ]
            ]
        ];

        // Panggil API Delyva untuk create order (draft)
        $delyvaRes = Http::withHeaders([
            'X-Delyvax-Access-Token' => $integration->delyva_api_key,
            'Content-Type' => 'application/json'
        ])->post('https://api.delyva.app/v1.0/order', $orderPayload);

        if (!$delyvaRes->successful()) {
            Log::error("Delyva create order failed: " . $delyvaRes->body());
            return;
        }

        $orderData = $delyvaRes->json();
        $delyvaOrderId = $orderData['id'] ?? null;

        if (!$delyvaOrderId) {
            Log::error("Delyva order created but no ID returned");
            return;
        }

        // Simpan mapping order
        ShippingOrder::updateOrCreate(
            [
                'location_id' => $locationId,
                'hl_order_id' => $orderId,
            ],
            [
                'delyva_order_id' => $delyvaOrderId,
                'hl_order_data' => $orderDetails,
                'delyva_order_data' => $orderData,
                'status' => 'draft'
            ]
        );

        // Confirm/process order
        $processRes = Http::withHeaders([
            'X-Delyvax-Access-Token' => $integration->delyva_api_key,
            'Content-Type' => 'application/json'
        ])->post("https://api.delyva.app/v1.0/order/$delyvaOrderId/process", [
            'serviceCode' => $serviceCode
        ]);

        if (!$processRes->successful()) {
            Log::error("Delyva process order failed: " . $processRes->body());
            return;
        }

        // Dapatkan info akhir (tracking no)
        $detailRes = Http::withHeaders([
            'X-Delyvax-Access-Token' => $integration->delyva_api_key,
            'Content-Type' => 'application/json'
        ])->get("https://api.delyva.app/v1.0/order/$delyvaOrderId");

        if ($detailRes->successful()) {
            $detail = $detailRes->json();
            $trackingNo = $detail['consignmentNo'] ?? null;
            $trackingUrl = $trackingNo ? "https://my.delyva.app/track?trackingNo=$trackingNo" : null;

            // Update shipping order dengan tracking
            ShippingOrder::where('location_id', $locationId)
                ->where('hl_order_id', $orderId)
                ->update([
                    'tracking_number' => $trackingNo,
                    'status' => 'processing'
                ]);

            // Hantar fulfillment ke HL
            $fulfillPayload = [
                'altId' => $locationId,
                'altType' => 'location',
                'items' => [],
                'trackings' => [
                    [
                        'trackingNumber' => $trackingNo,
                        'shippingCarrier' => 'Delyva Shipping',
                        'trackingUrl' => $trackingUrl
                    ]
                ],
                'notifyCustomer' => true
            ];

            $fulfillRes = Http::withToken($hlToken)
                ->post("https://services.leadconnectorhq.com/payments/orders/$orderId/fulfillments", $fulfillPayload);

            if ($fulfillRes->successful()) {
                Log::info("Order $orderId fulfilled with Delyva tracking $trackingNo");
            } else {
                Log::error("Failed to create HL fulfillment", [
                    'order_id' => $orderId,
                    'response' => $fulfillRes->body()
                ]);
            }
        }
    }

    /**
     * Dapatkan alamat kedai dari HL atau default
     */
    private function getStoreAddress($integration, $locationId)
    {
        // Boleh panggil HL API untuk dapatkan store address
        // Untuk sekarang return default
        return [
            'address1' => 'Store Address',
            'city' => 'Kuala Lumpur',
            'state' => 'Kuala Lumpur',
            'postcode' => '50000',
            'country' => 'MY',
            'name' => 'Store Name',
            'phone' => '0123456789',
            'email' => '[email protected]'
        ];
    }



    /**
     * Dapatkan senarai orders untuk location dari HL
     */
    public function getOrders($locationId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();
        
        if (!$integration || !$integration->hl_access_token) {
            return response()->json(['error' => 'Integration not found'], 404);
        }

        // Dapatkan orders dari HL API
        $response = Http::withToken($integration->hl_access_token)
            ->get('https://services.leadconnectorhq.com/payments/orders', [
                'altId' => $locationId,
                'altType' => 'location'
            ]);

        if (!$response->successful()) {
            Log::error('Failed to fetch orders from HL', [
                'location_id' => $locationId,
                'status' => $response->status()
            ]);
            return response()->json(['error' => 'Failed to fetch orders'], 500);
        }

        $hlOrders = $response->json();

        // Dapatkan local shipping orders untuk mapping
        $localOrders = ShippingOrder::byLocation($locationId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->keyBy('hl_order_id');

        // Combine data
        $combinedOrders = [];
        foreach ($hlOrders['orders'] ?? [] as $hlOrder) {
            $orderId = $hlOrder['id'];
            $localOrder = $localOrders->get($orderId);
            
            $combinedOrders[] = [
                'hl_order' => $hlOrder,
                'shipping_info' => $localOrder ? [
                    'delyva_order_id' => $localOrder->delyva_order_id,
                    'tracking_number' => $localOrder->tracking_number,
                    'status' => $localOrder->status,
                    'shipped_at' => $localOrder->shipped_at,
                    'delivered_at' => $localOrder->delivered_at,
                ] : null
            ];
        }

        return response()->json([
            'orders' => $combinedOrders,
            'pagination' => $hlOrders['meta'] ?? null
        ]);
    }

    /**
     * Dapatkan order details dari HL dan local
     */
    public function getOrderDetails($locationId, $orderId)
    {
        $integration = ShippingIntegration::where('location_id', $locationId)->first();
        
        if (!$integration || !$integration->hl_access_token) {
            return response()->json(['error' => 'Integration not found'], 404);
        }

        // Dapatkan order dari HL API
        $hlResponse = Http::withToken($integration->hl_access_token)
            ->get("https://services.leadconnectorhq.com/payments/orders/$orderId");

        if (!$hlResponse->successful()) {
            return response()->json(['error' => 'Order not found in HL'], 404);
        }

        $hlOrder = $hlResponse->json();

        // Dapatkan local shipping order
        $localOrder = ShippingOrder::byLocation($locationId)
            ->where('hl_order_id', $orderId)
            ->first();

        return response()->json([
            'hl_order' => $hlOrder,
            'shipping_info' => $localOrder ? [
                'delyva_order_id' => $localOrder->delyva_order_id,
                'tracking_number' => $localOrder->tracking_number,
                'status' => $localOrder->status,
                'shipped_at' => $localOrder->shipped_at,
                'delivered_at' => $localOrder->delivered_at,
                'delyva_order_data' => $localOrder->delyva_order_data,
            ] : null
        ]);
    }
}
