<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppCredentials extends Model
{
    protected $fillable = [
        'client_id',
        'client_secret',
    ];
}