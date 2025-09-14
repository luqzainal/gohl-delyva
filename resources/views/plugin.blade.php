<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Delyva Integration - HighLevel Plugin</title>
    
    <!-- Vite CSS -->
    @vite('resources/css/app.css')
    
    <style>
        /* Custom styles untuk HighLevel iframe */
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        /* Responsive untuk mobile */
        @media (max-width: 640px) {
            .max-w-md {
                max-width: 100%;
            }
            
            .rounded-lg {
                border-radius: 0.5rem;
            }
            
            /* Ensure proper spacing on mobile */
            .p-4 {
                padding: 1rem;
            }
        }
        
        /* Ensure full height centering */
        html, body {
            height: 100%;
        }
    </style>
</head>
<body>
    <div id="plugin-root">
        <!-- Loading state -->
        <div class="min-h-screen bg-gray-50 flex items-center justify-center">
            <div class="text-center">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto mb-4"></div>
                <p class="text-gray-600">Loading Delyva Integration...</p>
            </div>
        </div>
    </div>

    <!-- Vite JS -->
    @vite('resources/js/plugin.tsx')
    
    <script>
        // Debug: Log messages dari HighLevel
        window.addEventListener('message', function(event) {
            console.log('Received message from HighLevel:', event.data);
        });
        
        // Fallback jika tidak dalam HighLevel iframe
        if (window === window.parent) {
            console.warn('Plugin not running in HighLevel iframe - using development mode');
        }
    </script>
</body>
</html>
