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
        Schema::create('shipping_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('location_id'); // HighLevel Location altId
            $table->string('delyva_api_key')->nullable();
            $table->string('delyva_customer_id')->nullable(); // optional
            $table->string('delyva_api_secret')->nullable(); // optional
            $table->string('shipping_carrier_id')->nullable(); // HL shipping carrier id
            $table->text('hl_access_token')->nullable();
            $table->text('hl_refresh_token')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_integrations');
    }
};
