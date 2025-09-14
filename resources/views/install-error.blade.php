<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Failed - Delyva Shipping Integration</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8 text-center">
        <!-- Error Icon -->
        <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-6">
            <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
        </div>

        <!-- Title -->
        <h1 class="text-2xl font-bold text-gray-900 mb-4">
            Installation Failed
        </h1>

        <!-- Description -->
        <p class="text-gray-600 mb-6">
            We encountered an error while installing the Delyva Shipping Integration for your HighLevel location.
        </p>

        <!-- Error Details -->
        @if(isset($error))
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 text-left">
            <div class="flex items-start">
                <i class="fas fa-bug text-red-600 mr-2 mt-1"></i>
                <div>
                    <p class="text-sm font-medium text-red-800 mb-1">Error Details:</p>
                    <p class="text-sm text-red-700">{{ $error }}</p>
                </div>
            </div>
        </div>
        @endif

        <!-- Common Issues -->
        <div class="text-left mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-3">Common Issues:</h3>
            <ul class="text-sm text-gray-600 space-y-2">
                <li class="flex items-start">
                    <i class="fas fa-circle text-red-400 text-xs mt-1.5 mr-2"></i>
                    Authorization code expired or invalid
                </li>
                <li class="flex items-start">
                    <i class="fas fa-circle text-red-400 text-xs mt-1.5 mr-2"></i>
                    Network connection issues
                </li>
                <li class="flex items-start">
                    <i class="fas fa-circle text-red-400 text-xs mt-1.5 mr-2"></i>
                    HighLevel API temporarily unavailable
                </li>
                <li class="flex items-start">
                    <i class="fas fa-circle text-red-400 text-xs mt-1.5 mr-2"></i>
                    Missing required permissions
                </li>
            </ul>
        </div>

        <!-- Actions -->
        <div class="space-y-3">
            <a href="{{ route('highlevel.auth') }}" 
               class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition duration-200 inline-block">
                <i class="fas fa-redo mr-2"></i>
                Try Again
            </a>
            
            <a href="https://app.gohighlevel.com" 
               class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-3 px-4 rounded-lg transition duration-200 inline-block">
                <i class="fas fa-external-link-alt mr-2"></i>
                Return to HighLevel
            </a>
        </div>

        <!-- Troubleshooting -->
        <div class="mt-8">
            <details class="text-left">
                <summary class="cursor-pointer text-sm font-medium text-gray-700 hover:text-gray-900">
                    <i class="fas fa-question-circle mr-1"></i>
                    Troubleshooting Steps
                </summary>
                <div class="mt-3 text-xs text-gray-600 space-y-2">
                    <p><strong>1.</strong> Ensure you have admin permissions in your HighLevel account</p>
                    <p><strong>2.</strong> Check your internet connection</p>
                    <p><strong>3.</strong> Clear your browser cache and cookies</p>
                    <p><strong>4.</strong> Try using a different browser</p>
                    <p><strong>5.</strong> Wait a few minutes and try the installation again</p>
                </div>
            </details>
        </div>

        <!-- Support -->
        <div class="mt-8 pt-6 border-t border-gray-200">
            <p class="text-xs text-gray-500">
                Still having issues? Contact our support team at 
                <a href="mailto:support@delyva.com" class="text-blue-600 hover:text-blue-800">
                    support@delyva.com
                </a>
            </p>
            @if(isset($errorId))
            <p class="text-xs text-gray-400 mt-2">
                Error ID: {{ $errorId }}
            </p>
            @endif
        </div>
    </div>
</body>
</html>