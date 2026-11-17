import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { isStaticOnly } from './api.js';

// Customer accounts: /api/account/* (session cookie + the server's CSRF token, like the dashboard, but a separate
// login: a customer account never opens the dashboard).

function xsrfToken() {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : '';
}

async function send(path, { method = 'GET', body } = {}) {
  if (isStaticOnly()) throw Object.assign(new Error('Accounts need the shop server, which isn’t running on this copy of the site.'), { offline: true });
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (method !== 'GET') {
    headers['X-XSRF-TOKEN'] = xsrfToken();
    headers['Content-Type'] = 'application/json';
  }
  let res;
  try {
    res = await fetch('/api/account/' + path, { method, headers, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin' });
  } catch {
    throw Object.assign(new Error('Can’t reach the shop server. Check your internet connection and try again.'), { offline: true });
  }
  if (!(res.headers.get('content-type') || '').includes('application/json')) {
    throw Object.assign(new Error('Accounts need the shop server, which isn’t running on this copy of the site.'), { offline: true });
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(data.error || 'Something went wrong. Please try again.'), { status: res.status });
  return data;
}

/** Retries once with a fresh CSRF token if the tab was left open long enough for it to expire. */
export async function accountRequest(path, options) {
  try {
    return await send(path, options);
  } catch (err) {
    if (err.status === 419) {
      await send('status');
      return send(path, options);
    }
    throw err;
  }
}

const AccountContext = createContext({ customer: null, google: false, ready: false, refresh: async () => {}, logout: async () => {} });

export function AccountProvider({ children }) {
  const [state, setState] = useState({ customer: null, google: false, ready: false });

  const refresh = useCallback(async () => {
    try {
      const s = await accountRequest('status');
      setState({ customer: s.customer, google: s.google, ready: true });
      return s.customer;
    } catch {
      setState((prev) => ({ ...prev, ready: true }));
      return null;
    }
  }, []);

  const logout = useCallback(async () => {
    await accountRequest('logout', { method: 'POST' }).catch(() => {});
    setState((prev) => ({ ...prev, customer: null }));
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const value = useMemo(() => ({ ...state, refresh, logout, setCustomer: (customer) => setState((p) => ({ ...p, customer })) }), [state, refresh, logout]);
  return <AccountContext.Provider value={value}>{children}</AccountContext.Provider>;
}

export const useAccount = () => useContext(AccountContext);
