import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App.jsx';
import { FlashProvider, SessionProvider } from './session.jsx';
import { applyTheme } from './prefs.js';
import { ADMIN_BASE } from './urls.js';
import './admin.css';
import './extras.css';

applyTheme(); // before the first paint, so a dark-mode user never sees a white flash

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter basename={ADMIN_BASE}>
      <FlashProvider>
        <SessionProvider>
          <App />
        </SessionProvider>
      </FlashProvider>
    </BrowserRouter>
  </StrictMode>
);
