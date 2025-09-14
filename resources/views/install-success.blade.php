<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Successful - Delyva Shipping Integration</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8 text-center">
        <!-- Success Icon -->
        <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-6">
            <i class="fas fa-check text-green-600 text-2xl"></i>
        </div>

        <!-- Title -->
        <h1 class="text-2xl font-bold text-gray-900 mb-4">
            Installation Successful!
        </h1>

        <!-- Description -->
        <p class="text-gray-600 mb-6">
            Delyva Shipping Integration has been successfully installed for your HighLevel location.
        </p>

        <!-- Location Info -->
        @if(isset($locationId))
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <div class="flex items-center">
                <i class="fas fa-map-marker-alt text-blue-600 mr-2"></i>
                <span class="text-sm text-gray-700">
                    <strong>Location ID:</strong> {{ $locationId }}
                </span>
            </div>
        </div>
        @endif

        <!-- Next Steps -->
        <div class="text-left mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-3">Next Steps:</h3>
            <ul class="text-sm text-gray-600 space-y-2">
                <li class="flex items-start">
                    <i class="fas fa-circle text-green-500 text-xs mt-1.5 mr-2"></i>
                    Configure your Delyva API credentials
                </li>
                <li class="flex items-start">
                    <i class="fas fa-circle text-green-500 text-xs mt-1.5 mr-2"></i>
                    Set up your shipping carriers
                </li>
                <li class="flex items-start">
                    <i class="fas fa-circle text-green-500 text-xs mt-1.5 mr-2"></i>
                    Test your shipping integration
                </li>
            </ul>
        </div>

        <!-- Actions -->
        <div class="space-y-3">
            <a href="{{ route('plugin.page') }}" 
               class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition duration-200 inline-block">
                <i class="fas fa-cog mr-2"></i>
                Configure Plugin
            </a>
            
            <a href="https://app.gohighlevel.com" 
               class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-3 px-4 rounded-lg transition duration-200 inline-block">
                <i class="fas fa-external-link-alt mr-2"></i>
                Return to HighLevel
            </a>
        </div>

        <!-- Support -->
        <div class="mt-8 pt-6 border-t border-gray-200">
            <p class="text-xs text-gray-500">
                Need help? Contact our support team at 
                <a href="mailto:support@delyva.com" class="text-blue-600 hover:text-blue-800">
                    support@delyva.com
                </a>
            </p>
        </div>
    </div>
</body>
</html>