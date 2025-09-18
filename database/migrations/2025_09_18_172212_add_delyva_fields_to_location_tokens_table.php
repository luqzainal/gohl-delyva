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
        Schema::table('location_tokens', function (Blueprint $table) {
            $table->string('delyva_api_key')->nullable()->after('refresh_token');
            $table->string('delyva_customer_id')->nullable()->after('delyva_api_key');
            $table->string('delyva_api_secret')->nullable()->after('delyva_customer_id');
            $table->string('delyva_company_code')->nullable()->after('delyva_api_secret');
            $table->string('delyva_company_id')->nullable()->after('delyva_company_code');
            $table->string('delyva_user_id')->nullable()->after('delyva_company_id');
            $table->string('shipping_carrier_id')->nullable()->after('delyva_user_id');
            $table->boolean('shipping_enabled')->default(true)->after('shipping_carrier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('location_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'delyva_api_key',
                'delyva_customer_id',
                'delyva_api_secret',
                'delyva_company_code',
                'delyva_company_id',
                'delyva_user_id',
                'shipping_carrier_id',
                'shipping_enabled'
            ]);
        });
    }
};
