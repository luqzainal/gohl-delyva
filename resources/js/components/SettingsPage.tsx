import React, { useState, useEffect } from 'react';

interface HighLevelContext {
  locationId: string;
  userId?: string;
  companyId?: string;
}

interface ApiResponse {
  status: string;
  message?: string;
  error?: string;
}

const SettingsPage: React.FC = () => {
  const [apiKey, setApiKey] = useState<string>('');
  const [customerId, setCustomerId] = useState<string>('');
  const [apiSecret, setApiSecret] = useState<string>('');
  const [locationId, setLocationId] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(false);
  const [message, setMessage] = useState<string>('');
  const [messageType, setMessageType] = useState<'success' | 'error' | ''>('');
  const [isTestingCredentials, setIsTestingCredentials] = useState<boolean>(false);

  useEffect(() => {
    // Dapatkan context HighLevel (locationId) apabila komponen mount
    const requestUserData = () => {
      window.parent.postMessage({ message: 'REQUEST_USER_DATA' }, '*');
    };

    const handler = (event: MessageEvent) => {
      if (event.data?.message === 'REQUEST_USER_DATA_RESPONSE') {
        const encryptedPayload = event.data.payload;
        
        // Hantar encryptedPayload ke server untuk decrypt
        fetch('/api/decrypt-context', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ encryptedData: encryptedPayload })
        })
        .then(res => res.json())
        .then((data: HighLevelContext) => {
          setLocationId(data.locationId);
          console.log('HighLevel Context:', data);
        })
        .catch(err => {
          console.error('Error decrypting context:', err);
          // Fallback: guna test location ID untuk development
          setLocationId('test_location_dev');
        });
      }
    };

    window.addEventListener('message', handler);
    
    // Request data selepas component mount
    setTimeout(requestUserData, 100);

    return () => window.removeEventListener('message', handler);
  }, []);

  const showMessage = (text: string, type: 'success' | 'error') => {
    setMessage(text);
    setMessageType(type);
    setTimeout(() => {
      setMessage('');
      setMessageType('');
    }, 5000);
  };

  const handleTestCredentials = async () => {
    if (!apiKey) {
      showMessage('Please enter your Delyva API Key', 'error');
      return;
    }

    setIsTestingCredentials(true);
    
    try {
      const response = await fetch('/api/credentials/test', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          locationId,
          apiKey,
          customerId,
          apiSecret
        })
      });

      const data: any = await response.json();

      if (response.ok && data.valid === true) {
        showMessage('✅ Delyva credentials are valid!', 'success');
      } else {
        showMessage(`❌ ${data.message || data.error_details || 'Invalid credentials'}`, 'error');
      }
    } catch (err) {
      showMessage('❌ Error testing credentials', 'error');
      console.error('Test credentials error:', err);
    } finally {
      setIsTestingCredentials(false);
    }
  };

  const registerAsCarrier = async () => {
    if (!locationId) {
      console.log('Cannot register carrier - no location ID');
      return;
    }

    try {
      const response = await fetch(`/carrier/register/${locationId}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
      });

      const data = await response.json();

      if (response.ok) {
        showMessage('✅ Registered as shipping carrier in HighLevel!', 'success');
        console.log('Carrier registration successful:', data);
      } else {
        console.error('Carrier registration failed:', data);
        // Don't show error to user - credential save was successful
      }
    } catch (err) {
      console.error('Carrier registration error:', err);
      // Don't show error to user - credential save was successful
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!apiKey) {
      showMessage('Please enter your Delyva API Key', 'error');
      return;
    }

    if (!locationId) {
      showMessage('Location ID not found. Please refresh the page.', 'error');
      return;
    }

    setLoading(true);

    try {
      const response = await fetch('/api/credentials', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          locationId,
          apiKey,
          customerId,
          apiSecret
        })
      });

      const data: ApiResponse = await response.json();

      if (response.ok && data.status === 'success') {
        showMessage('🎉 API Key saved successfully!', 'success');
        
        // Auto-register sebagai shipping carrier
        registerAsCarrier();
      } else {
        showMessage(`❌ ${data.error || 'Failed to save credentials'}`, 'error');
      }
    } catch (err) {
      showMessage('❌ Error saving credentials. Please try again.', 'error');
      console.error('Save credentials error:', err);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="w-full max-w-md bg-white rounded-lg shadow-md p-6">
        <div className="text-center mb-6">
          <h2 className="text-2xl font-bold text-gray-900 mb-2">
            Delyva Integration
          </h2>
          <p className="text-gray-600 text-sm">
            Enter your Delyva credentials to enable shipping
          </p>
          {locationId && (
            <p className="text-xs text-gray-500 mt-2">
              Location: {locationId}
            </p>
          )}
        </div>

        {message && (
          <div className={`mb-4 p-3 rounded-md text-sm ${
            messageType === 'success' 
              ? 'bg-green-100 text-green-700 border border-green-200'
              : 'bg-red-100 text-red-700 border border-red-200'
          }`}>
            {message}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Delyva API Key *
            </label>
            <input
              type="text"
              value={apiKey}
              onChange={(e) => setApiKey(e.target.value)}
              placeholder="Enter your Delyva API Key"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Customer ID (Optional)
            </label>
            <input
              type="text"
              value={customerId}
              onChange={(e) => setCustomerId(e.target.value)}
              placeholder="Customer ID (will auto-detect if empty)"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              API Secret (Optional)
            </label>
            <input
              type="password"
              value={apiSecret}
              onChange={(e) => setApiSecret(e.target.value)}
              placeholder="API Secret (if available)"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>

          <div className="flex space-x-3">
            <button
              type="button"
              onClick={handleTestCredentials}
              disabled={isTestingCredentials || !apiKey}
              className="flex-1 bg-gray-600 text-white py-2 px-4 rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
            >
              {isTestingCredentials ? 'Testing...' : 'Test Credentials'}
            </button>

            <button
              type="submit"
              disabled={loading || !apiKey || !locationId}
              className="flex-1 bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
            >
              {loading ? 'Saving...' : 'Save'}
            </button>
          </div>
        </form>

        <div className="mt-6 text-xs text-gray-500 text-center">
          <p>
            After saving, Delyva will be registered as a shipping carrier in HighLevel.
          </p>
        </div>
      </div>
    </div>
  );
};

export default SettingsPage;
