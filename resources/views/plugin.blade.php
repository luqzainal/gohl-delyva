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
        <!-- Complete fallback with toggle functionality -->
        <script>
            console.log('Vite assets not found, using complete fallback');

            let locationId = 'test_location_dev';
            let shippingEnabled = true;

            // Request HighLevel context
            const requestUserData = () => {
                window.parent.postMessage({ message: 'REQUEST_USER_DATA' }, '*');
            };

            const handleHighLevelMessage = (event) => {
                if (event.data?.message === 'REQUEST_USER_DATA_RESPONSE') {
                    const encryptedPayload = event.data.payload;

                    fetch('/api/decrypt-context', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ encryptedData: encryptedPayload })
                    })
                    .then(res => res.json())
                    .then((data) => {
                        locationId = data.locationId;
                        console.log('HighLevel Context:', data);
                        loadShippingStatus();
                        updateLocationDisplay();
                    })
                    .catch(err => {
                        console.error('Error decrypting context:', err);
                        locationId = 'test_location_dev';
                        updateLocationDisplay();
                    });
                }
            };

            const loadShippingStatus = async () => {
                if (!locationId) return;
                try {
                    const response = await fetch(\`/api/carrier/status/\${locationId}\`);
                    if (response.ok) {
                        const data = await response.json();
                        shippingEnabled = data.status?.shipping_enabled ?? true;
                        updateToggleUI();
                    }
                } catch (err) {
                    console.error('Error loading shipping status:', err);
                }
            };

            const toggleShipping = async (enabled) => {
                if (!locationId) {
                    showMessage('Location ID not found. Please refresh the page.', 'error');
                    return;
                }

                try {
                    const response = await fetch('/api/shipping/toggle', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ locationId, enabled })
                    });

                    const data = await response.json();

                    if (response.ok && data.status === 'success') {
                        shippingEnabled = enabled;
                        updateToggleUI();
                        showMessage(
                            enabled ? '✅ Shipping rates enabled!' : '⏸️ Shipping rates disabled!',
                            'success'
                        );
                    } else {
                        showMessage(\`❌ \${data.error || 'Failed to update shipping status'}\`, 'error');
                    }
                } catch (err) {
                    showMessage('❌ Error updating shipping status. Please try again.', 'error');
                    console.error('Toggle shipping error:', err);
                }
            };

            const showMessage = (text, type) => {
                const messageDiv = document.getElementById('message');
                messageDiv.innerHTML = \`
                    <div class="mb-4 p-3 rounded-md text-sm \${type === 'success'
                        ? 'bg-green-100 text-green-700 border border-green-200'
                        : 'bg-red-100 text-red-700 border border-red-200'
                    }">
                        \${text}
                    </div>
                \`;
                setTimeout(() => {
                    messageDiv.innerHTML = '';
                }, 5000);
            };

            const updateToggleUI = () => {
                const toggleButton = document.getElementById('shipping-toggle');
                const statusText = document.getElementById('shipping-status');

                if (toggleButton && statusText) {
                    toggleButton.className = \`relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 \${
                        shippingEnabled ? 'bg-blue-600' : 'bg-gray-200'
                    }\`;

                    toggleButton.innerHTML = \`
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-lg transition-transform \${
                            shippingEnabled ? 'translate-x-6' : 'translate-x-1'
                        }"></span>
                    \`;

                    statusText.textContent = shippingEnabled
                        ? 'Customers will see live shipping rates at checkout'
                        : 'Shipping rates are disabled - no rates will be shown';
                }
            };

            const updateLocationDisplay = () => {
                const locationDisplay = document.getElementById('location-display');
                if (locationDisplay) {
                    locationDisplay.innerHTML = locationId ? \`<p class="text-xs text-gray-500 mt-2">Location: \${locationId}</p>\` : '';
                }
            };

            window.addEventListener('message', handleHighLevelMessage);

            // Replace loading dengan complete form
            setTimeout(() => {
                document.getElementById('plugin-root').innerHTML = \`
                    <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                        <div class="w-full max-w-md bg-white rounded-lg shadow-md p-6">
                            <div class="text-center mb-6">
                                <h2 class="text-2xl font-bold text-gray-900 mb-2">Delyva Integration</h2>
                                <p class="text-gray-600 text-sm">Enter your Delyva credentials to enable shipping</p>
                                <div id="location-display"></div>
                            </div>

                            <div id="message"></div>

                            <form class="space-y-4" onsubmit="return false;">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Delyva API Key *</label>
                                    <input type="text" id="api-key" placeholder="Enter your Delyva API Key" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer ID (Optional)</label>
                                    <input type="text" id="customer-id" placeholder="Customer ID (will auto-detect if empty)" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">API Secret (Optional)</label>
                                    <input type="password" id="api-secret" placeholder="API Secret (if available)" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Code</label>
                                    <input type="text" id="company-code" placeholder="e.g., demo" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Company ID</label>
                                    <input type="text" id="company-id" placeholder="e.g., 9e0aed8a-5c67-42a4-82b6-e01bf7687f31" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">User ID</label>
                                    <input type="text" id="user-id" placeholder="e.g., 3e21a1c0-912e-11f0-b030-1bfc12908131" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <div class="flex space-x-3">
                                    <button type="button" onclick="testCredentials()" class="flex-1 bg-gray-600 text-white py-2 px-4 rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm">
                                        Test Credentials
                                    </button>
                                    <button type="button" onclick="saveCredentials()" class="flex-1 bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                        Save
                                    </button>
                                </div>
                            </form>

                            <!-- Shipping Toggle Section -->
                            <div class="mt-6 p-4 bg-gray-50 rounded-lg border">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <h3 class="text-sm font-medium text-gray-900">Live Shipping Rates</h3>
                                        <p id="shipping-status" class="text-xs text-gray-600 mt-1">
                                            Customers will see live shipping rates at checkout
                                        </p>
                                    </div>
                                    <button
                                        id="shipping-toggle"
                                        onclick="toggleShipping(!shippingEnabled)"
                                        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 bg-blue-600"
                                        role="switch"
                                    >
                                        <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-lg transition-transform translate-x-6"></span>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-6 text-xs text-gray-500 text-center">
                                <p>After saving, Delyva will be registered as a shipping carrier in HighLevel.</p>
                                <p class="text-red-500 mt-1">Fallback mode - React assets failed to load</p>
                            </div>
                        </div>
                    </div>
                \`;

                // Initialize
                requestUserData();
                updateLocationDisplay();
                updateToggleUI();
            }, 100);

            // API functions
            window.testCredentials = async () => {
                const apiKey = document.getElementById('api-key').value;
                if (!apiKey) {
                    showMessage('Please enter your Delyva API Key', 'error');
                    return;
                }

                try {
                    const response = await fetch('/api/credentials/test', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            locationId,
                            apiKey,
                            customerId: document.getElementById('customer-id').value,
                            apiSecret: document.getElementById('api-secret').value,
                            companyCode: document.getElementById('company-code').value,
                            companyId: document.getElementById('company-id').value,
                            userId: document.getElementById('user-id').value
                        })
                    });

                    const data = await response.json();

                    if (response.ok && data.valid === true) {
                        showMessage('✅ Delyva credentials are valid!', 'success');
                    } else {
                        showMessage(\`❌ \${data.message || data.error_details || 'Invalid credentials'}\`, 'error');
                    }
                } catch (err) {
                    showMessage('❌ Error testing credentials', 'error');
                    console.error('Test credentials error:', err);
                }
            };

            window.saveCredentials = async () => {
                const apiKey = document.getElementById('api-key').value;
                if (!apiKey) {
                    showMessage('Please enter your Delyva API Key', 'error');
                    return;
                }

                if (!locationId) {
                    showMessage('Location ID not found. Please refresh the page.', 'error');
                    return;
                }

                try {
                    const response = await fetch('/api/credentials', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            locationId,
                            apiKey,
                            customerId: document.getElementById('customer-id').value,
                            apiSecret: document.getElementById('api-secret').value,
                            companyCode: document.getElementById('company-code').value,
                            companyId: document.getElementById('company-id').value,
                            userId: document.getElementById('user-id').value
                        })
                    });

                    const data = await response.json();

                    if (response.ok && data.status === 'success') {
                        showMessage('🎉 API Key saved successfully!', 'success');

                        // Auto-register as shipping carrier
                        try {
                            const carrierResponse = await fetch(\`/api/carrier/register/\${locationId}\`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' }
                            });
                            const carrierData = await carrierResponse.json();
                            if (carrierResponse.ok) {
                                showMessage('✅ Registered as shipping carrier in HighLevel!', 'success');
                            }
                        } catch (err) {
                            console.error('Carrier registration error:', err);
                        }
                    } else {
                        showMessage(\`❌ \${data.error || 'Failed to save credentials'}\`, 'error');
                    }
                } catch (err) {
                    showMessage('❌ Error saving credentials. Please try again.', 'error');
                    console.error('Save credentials error:', err);
                }
            };
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
