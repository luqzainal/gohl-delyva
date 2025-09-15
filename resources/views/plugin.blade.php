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
    @if(file_exists(public_path('build/manifest.json')))
        @vite('resources/js/plugin.tsx')
    @else
        <!-- Fallback: Simple JS untuk development -->
        <script>
            console.log('Vite assets not found, using fallback');
            // Replace loading dengan simple form
            setTimeout(() => {
                document.getElementById('plugin-root').innerHTML = `
                    <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                        <div class="w-full max-w-md bg-white rounded-lg shadow-md p-6">
                            <div class="text-center mb-6">
                                <h2 class="text-2xl font-bold text-gray-900 mb-2">Delyva Integration</h2>
                                <p class="text-gray-600 text-sm">Enter your Delyva credentials to enable shipping</p>
                                <p class="text-xs text-red-500 mt-2">Development mode - assets not built</p>
                            </div>
                            <form class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Delyva API Key *</label>
                                    <input type="text" placeholder="Enter your Delyva API Key" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">Save</button>
                            </form>
                        </div>
                    </div>
                `;
            }, 100);
        </script>
    @endif

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
