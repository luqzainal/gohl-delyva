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
        Schema::create('location_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('location_id')->unique();
            $table->string('company_id')->nullable();
            $table->string('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_tokens');
    }
};