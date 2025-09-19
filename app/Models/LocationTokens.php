<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationTokens extends Model
{
    protected $fillable = [
        'location_id',
        'company_id',
        'user_id',
        'user_type',
        'access_token',
        'refresh_token',
        'delyva_api_key',
        'delyva_customer_id',
        'delyva_api_secret',
        'delyva_company_code',
        'delyva_company_id',
        'delyva_user_id',
        'shipping_carrier_id',
        'shipping_enabled',
    ];
}