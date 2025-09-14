import React from 'react';
import { createRoot } from 'react-dom/client';
import SettingsPage from './components/SettingsPage';

// Pastikan DOM sudah ready
document.addEventListener('DOMContentLoaded', () => {
  const rootElement = document.getElementById('plugin-root');
  
  if (rootElement) {
    const root = createRoot(rootElement);
    root.render(<SettingsPage />);
  } else {
    console.error('Plugin root element not found');
  }
});
