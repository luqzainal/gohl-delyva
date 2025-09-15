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
        Schema::table('shipping_integrations', function (Blueprint $table) {
            $table->string('delyva_company_code')->nullable()->after('delyva_api_secret');
            $table->string('delyva_company_id')->nullable()->after('delyva_company_code');
            $table->string('delyva_user_id')->nullable()->after('delyva_company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_integrations', function (Blueprint $table) {
            $table->dropColumn(['delyva_company_code', 'delyva_company_id', 'delyva_user_id']);
        });
    }
};
