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
    ];
}