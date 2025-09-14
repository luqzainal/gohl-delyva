<?php

namespace App\Http\Controllers;

use App\Models\ShippingOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

class DelyvaWebhookController extends Controller
{
    /**
     * Handle webhook status dari Delyva
     */
    public function handleStatusWebhook(Request $request)
    {
        Log::info('Delyva status webhook received', [
            'headers' => $request->headers->all(),
            'payload' => $request->all()
        ]);

        // Validate webhook signature
        if (!$this->validateWebhookSignature($request)) {
            Log::error('Invalid Delyva webhook signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $webhookData = $request->all();
        $eventType = $webhookData['event'] ?? $webhookData['type'] ?? null;
        $orderData = $webhookData['data'] ?? $webhookData;

        if (!$eventType) {
            Log::error('No event type in Delyva webhook');
            return response()->json(['error' => 'No event type'], 400);
        }

        // Cari shipping order berdasarkan Delyva order ID atau tracking number
        $delyvaOrderId = $orderData['id'] ?? $orderData['order_id'] ?? null;
        $trackingNumber = $orderData['tracking_number'] ?? $orderData['awb'] ?? null;

        $shippingOrder = null;

        if ($delyvaOrderId) {
            $shippingOrder = ShippingOrder::where('delyva_order_id', $delyvaOrderId)->first();
        }

        if (!$shippingOrder && $trackingNumber) {
            $shippingOrder = ShippingOrder::where('tracking_number', $trackingNumber)->first();
        }

        if (!$shippingOrder) {
            Log::error('Shipping order not found for Delyva webhook', [
                'delyva_order_id' => $delyvaOrderId,
                'tracking_number' => $trackingNumber
            ]);
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Process berdasarkan event type
        $result = $this->processStatusUpdate($shippingOrder, $eventType, $orderData);

        if ($result['success']) {
            return response()->json(['message' => 'Status updated successfully']);
        } else {
            return response()->json(['error' => $result['error']], 500);
        }
    }

    /**
     * Validate webhook signature dari Delyva
     */
    private function validateWebhookSignature(Request $request): bool
    {
        $webhookSecret = config('services.delyva.webhook_secret');
        
        if (!$webhookSecret) {
            // Jika tidak ada secret, skip validation (untuk development)
            return true;
        }

        $signature = $request->header('X-Delyva-Signature') ?? $request->header('X-Signature');
        
        if (!$signature) {
            return false;
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Process status update berdasarkan event type
     */
    private function processStatusUpdate($shippingOrder, $eventType, $orderData)
    {
        $statusMapping = [
            'order.created' => 'processing',
            'order.confirmed' => 'processing',
            'order.picked_up' => 'shipped',
            'order.in_transit' => 'shipped',
            'order.out_for_delivery' => 'shipped',
            'order.delivered' => 'delivered',
            'order.failed' => 'failed',
            'order.cancelled' => 'cancelled',
            'order.returned' => 'returned',
        ];

        $newStatus = $statusMapping[$eventType] ?? null;

        if (!$newStatus) {
            Log::warning('Unknown Delyva event type', ['event_type' => $eventType]);
            return ['success' => false, 'error' => 'Unknown event type'];
        }

        // Update shipping order status
        $updateData = [
            'status' => $newStatus,
            'delyva_order_data' => array_merge($shippingOrder->delyva_order_data ?? [], $orderData),
        ];

        // Set timestamps berdasarkan status
        if ($newStatus === 'shipped' && !$shippingOrder->shipped_at) {
            $updateData['shipped_at'] = now();
        }

        if ($newStatus === 'delivered' && !$shippingOrder->delivered_at) {
            $updateData['delivered_at'] = now();
        }

        // Update tracking number jika ada
        if (isset($orderData['tracking_number']) || isset($orderData['awb'])) {
            $updateData['tracking_number'] = $orderData['tracking_number'] ?? $orderData['awb'];
        }

        $shippingOrder->update($updateData);

        Log::info('Shipping order status updated', [
            'order_id' => $shippingOrder->hl_order_id,
            'old_status' => $shippingOrder->status,
            'new_status' => $newStatus,
            'event_type' => $eventType
        ]);

        // Update HighLevel order dengan status baru
        $hlUpdateResult = $this->updateHighLevelOrderStatus($shippingOrder, $newStatus, $orderData);

        return [
            'success' => true,
            'hl_update' => $hlUpdateResult
        ];
    }

    /**
     * Update HighLevel order dengan status baru
     */
    private function updateHighLevelOrderStatus($shippingOrder, $status, $orderData)
    {
        $integration = $shippingOrder->integration;

        if (!$integration || !$integration->hl_access_token) {
            Log::warning('Cannot update HighLevel order - no integration or token', [
                'order_id' => $shippingOrder->hl_order_id
            ]);
            return false;
        }

        // Map status ke HighLevel format
        $hlStatusMapping = [
            'processing' => 'processing',
            'shipped' => 'shipped',
            'delivered' => 'delivered',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'returned' => 'returned',
        ];

        $hlStatus = $hlStatusMapping[$status] ?? $status;

        $updateData = [
            'shippingStatus' => $hlStatus,
            'trackingNumber' => $shippingOrder->tracking_number,
        ];

        // Tambah notes berdasarkan status
        $statusNotes = [
            'processing' => 'Order is being processed by Delyva',
            'shipped' => 'Order has been shipped via Delyva',
            'delivered' => 'Order has been delivered successfully',
            'failed' => 'Delivery failed',
            'cancelled' => 'Order has been cancelled',
            'returned' => 'Order has been returned',
        ];

        if (isset($statusNotes[$status])) {
            $updateData['notes'] = $statusNotes[$status];
            
            if ($shippingOrder->tracking_number) {
                $updateData['notes'] .= ' (Tracking: ' . $shippingOrder->tracking_number . ')';
            }
        }

        $headers = [
            'Authorization' => 'Bearer ' . $integration->hl_access_token,
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)
            ->put(config('services.highlevel.base_url') . '/v1/orders/' . $shippingOrder->hl_order_id, $updateData);

        if ($response->successful()) {
            Log::info('HighLevel order status updated', [
                'order_id' => $shippingOrder->hl_order_id,
                'status' => $hlStatus,
                'tracking_number' => $shippingOrder->tracking_number
            ]);
            return true;
        } else {
            Log::error('Failed to update HighLevel order status', [
                'order_id' => $shippingOrder->hl_order_id,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            return false;
        }
    }

    /**
     * Manual sync status dari Delyva untuk order tertentu
     */
    public function syncOrderStatus($locationId, $orderId)
    {
        $shippingOrder = ShippingOrder::byLocation($locationId)
            ->where('hl_order_id', $orderId)
            ->first();

        if (!$shippingOrder) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if (!$shippingOrder->delyva_order_id) {
            return response()->json(['error' => 'No Delyva order ID'], 400);
        }

        $integration = $shippingOrder->integration;

        if (!$integration || !$integration->delyva_api_key) {
            return response()->json(['error' => 'Integration not configured'], 400);
        }

        // Dapatkan status terkini dari Delyva
        $headers = [
            'Authorization' => 'Bearer ' . $integration->delyva_api_key,
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)
            ->get(config('services.delyva.base_url') . '/v1/orders/' . $shippingOrder->delyva_order_id);

        if (!$response->successful()) {
            Log::error('Failed to fetch order status from Delyva', [
                'delyva_order_id' => $shippingOrder->delyva_order_id,
                'status' => $response->status()
            ]);

            return response()->json(['error' => 'Failed to fetch status from Delyva'], 500);
        }

        $delyvaOrderData = $response->json();
        $currentStatus = $delyvaOrderData['status'] ?? null;

        if (!$currentStatus) {
            return response()->json(['error' => 'No status in Delyva response'], 500);
        }

        // Map Delyva status ke internal status
        $statusMapping = [
            'pending' => 'pending',
            'confirmed' => 'processing',
            'picked_up' => 'shipped',
            'in_transit' => 'shipped',
            'out_for_delivery' => 'shipped',
            'delivered' => 'delivered',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'returned' => 'returned',
        ];

        $mappedStatus = $statusMapping[$currentStatus] ?? $currentStatus;

        // Update jika status berbeza
        if ($shippingOrder->status !== $mappedStatus) {
            $updateData = [
                'status' => $mappedStatus,
                'delyva_order_data' => $delyvaOrderData,
            ];

            if ($mappedStatus === 'shipped' && !$shippingOrder->shipped_at) {
                $updateData['shipped_at'] = now();
            }

            if ($mappedStatus === 'delivered' && !$shippingOrder->delivered_at) {
                $updateData['delivered_at'] = now();
            }

            $shippingOrder->update($updateData);

            // Update HighLevel
            $this->updateHighLevelOrderStatus($shippingOrder, $mappedStatus, $delyvaOrderData);

            Log::info('Order status synced manually', [
                'order_id' => $orderId,
                'old_status' => $shippingOrder->status,
                'new_status' => $mappedStatus
            ]);

            return response()->json([
                'message' => 'Status synced successfully',
                'old_status' => $shippingOrder->status,
                'new_status' => $mappedStatus
            ]);
        } else {
            return response()->json([
                'message' => 'Status already up to date',
                'current_status' => $mappedStatus
            ]);
        }
    }

    /**
     * Dapatkan tracking info untuk order
     */
    public function getTrackingInfo($locationId, $orderId)
    {
        $shippingOrder = ShippingOrder::byLocation($locationId)
            ->where('hl_order_id', $orderId)
            ->first();

        if (!$shippingOrder) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'tracking_number' => $shippingOrder->tracking_number,
            'status' => $shippingOrder->status,
            'shipped_at' => $shippingOrder->shipped_at,
            'delivered_at' => $shippingOrder->delivered_at,
            'tracking_url' => $shippingOrder->tracking_number ? 
                'https://tracking.delyva.app/track/' . $shippingOrder->tracking_number : null,
            'delyva_order_data' => $shippingOrder->delyva_order_data,
        ]);
    }
}
