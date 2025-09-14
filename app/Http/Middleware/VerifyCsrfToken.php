<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Webhook endpoints - exclude dari CSRF protection
        'webhooks/*',
        'shipping/rates/callback',
        
        // API endpoints - exclude dari CSRF protection  
        'api/*',
        
        // Test endpoints - exclude dari CSRF protection
        'test/*',
    ];
}