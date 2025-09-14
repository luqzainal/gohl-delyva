<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingIntegration extends Model
{
    protected $fillable = [
        'location_id',
        'delyva_api_key',
        'delyva_customer_id',
        'delyva_api_secret',
        'shipping_carrier_id',
        'hl_access_token',
        'hl_refresh_token',
    ];
}
