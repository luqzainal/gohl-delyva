<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OAuth Debug - Delyva Integration</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .info-box { background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 4px; margin: 10px 0; }
        .param { margin: 5px 0; }
        .param strong { color: #0066cc; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .urls { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 OAuth Debug Callback</h1>

        <div class="success">
            ✅ Debug callback received successfully at {{ $timestamp }}
        </div>

        <div class="urls">
            <h3>📋 Next Steps:</h3>
            <p><strong>1. Current Debug URL:</strong> <code>https://delyva.mysentree.io/oauth/debug-callback</code></p>
            <p><strong>2. Real Callback URL:</strong> <code>https://delyva.mysentree.io/oauth/callback</code></p>
            <p>Once you confirm the parameters look correct, update your HighLevel app to use the real callback URL.</p>
        </div>

        <div class="info-box">
            <h3>📥 Request Information</h3>
            <div class="param"><strong>Method:</strong> {{ $method }}</div>
            <div class="param"><strong>Full URL:</strong> {{ $url }}</div>
        </div>

        <div class="info-box">
            <h3>🔑 OAuth Parameters</h3>
            @if(empty($params))
                <p style="color: #dc3545;">❌ No parameters received!</p>
            @else
                @foreach($params as $key => $value)
                    <div class="param">
                        <strong>{{ $key }}:</strong>
                        @if($key === 'code')
                            <code>{{ substr($value, 0, 20) }}{{ strlen($value) > 20 ? '...' : '' }}</code>
                            <span style="color: #28a745;">({{ strlen($value) }} characters)</span>
                        @else
                            <code>{{ $value }}</code>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>

        <div class="info-box">
            <h3>🔍 Expected Parameters</h3>
            <div class="param"><strong>code:</strong> Authorization code from HighLevel</div>
            <div class="param"><strong>location_id:</strong> HighLevel location identifier</div>
            <div class="param"><strong>state:</strong> (Optional) State parameter</div>
        </div>

        <div class="info-box">
            <h3>🔧 Location ID Detection</h3>
            @php
                $possibleLocationIds = [];
                foreach(['location_id', 'locationId', 'loc_id', 'ghl_location_id', 'sub_account_id'] as $param) {
                    if(isset($params[$param])) {
                        $possibleLocationIds[$param] = $params[$param];
                    }
                }
            @endphp

            @if(empty($possibleLocationIds))
                <p style="color: #dc3545;">❌ No location_id parameter found in any expected format!</p>
                <p><strong>Checking:</strong> location_id, locationId, loc_id, ghl_location_id, sub_account_id</p>
            @else
                <p style="color: #28a745;">✅ Found potential location ID(s):</p>
                @foreach($possibleLocationIds as $param => $value)
                    <div class="param"><strong>{{ $param }}:</strong> <code>{{ $value }}</code></div>
                @endforeach
            @endif
        </div>

        <details>
            <summary><strong>📊 Full Request Data</strong></summary>
            <pre>{{ json_encode($all_data, JSON_PRETTY_PRINT) }}</pre>
        </details>

        <div style="margin-top: 30px; padding: 15px; background: #e9ecef; border-radius: 4px;">
            <small>
                <strong>Debug Info:</strong> This data has been logged to <code>storage/logs/oauth_debug.json</code>
                and <code>storage/logs/laravel.log</code> for your review.
            </small>
        </div>
    </div>
</body>
</html>