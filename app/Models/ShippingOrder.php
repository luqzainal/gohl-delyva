<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingOrder extends Model
{
    protected $fillable = [
        'location_id',
        'hl_order_id',
        'delyva_order_id',
        'tracking_number',
        'status',
        'hl_order_data',
        'delyva_order_data',
        'shipping_address',
        'items',
        'total_amount',
        'shipping_cost',
        'selected_rate_id',
        'selected_rate_data',
        'shipped_at',
        'delivered_at',
        'notes',
    ];

    protected $casts = [
        'hl_order_data' => 'array',
        'delyva_order_data' => 'array',
        'shipping_address' => 'array',
        'items' => 'array',
        'selected_rate_data' => 'array',
        'total_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Relationship dengan ShippingIntegration
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(ShippingIntegration::class, 'location_id', 'location_id');
    }

    /**
     * Scope untuk status tertentu
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope untuk location tertentu
     */
    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * Check jika order sudah dihantar
     */
    public function isShipped(): bool
    {
        return in_array($this->status, ['shipped', 'delivered']);
    }

    /**
     * Check jika order sudah sampai
     */
    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    /**
     * Mark order sebagai shipped
     */
    public function markAsShipped($trackingNumber = null): void
    {
        $this->update([
            'status' => 'shipped',
            'tracking_number' => $trackingNumber ?? $this->tracking_number,
            'shipped_at' => now(),
        ]);
    }

    /**
     * Mark order sebagai delivered
     */
    public function markAsDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }
}