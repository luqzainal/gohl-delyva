<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shipping_orders', function (Blueprint $table) {
            $table->id();
            $table->string('location_id'); // HighLevel Location ID
            $table->string('hl_order_id'); // HighLevel Order ID
            $table->string('delyva_order_id')->nullable(); // Delyva Order ID
            $table->string('tracking_number')->nullable(); // Tracking number dari Delyva
            $table->string('status')->default('pending'); // pending, processing, shipped, delivered, cancelled
            $table->json('hl_order_data'); // Full order data dari HighLevel
            $table->json('delyva_order_data')->nullable(); // Response dari Delyva
            $table->json('shipping_address'); // Alamat penghantaran
            $table->json('items'); // Senarai items
            $table->decimal('total_amount', 10, 2); // Jumlah order
            $table->decimal('shipping_cost', 10, 2)->nullable(); // Kos penghantaran
            $table->string('selected_rate_id')->nullable(); // Rate yang dipilih
            $table->json('selected_rate_data')->nullable(); // Data rate yang dipilih
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'hl_order_id']);
            $table->index('delyva_order_id');
            $table->index('tracking_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_orders');
    }
};