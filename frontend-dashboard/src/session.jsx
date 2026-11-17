import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { get, setAuthLostHandler } from './api.js';

const SessionContext = createContext(null);

const FlashContext = createContext(() => {});

/** Who is logged in, whether the site is installed, and the "new orders" badge count. */
export function SessionProvider({ children }) {
  const [state, setState] = useState({ loading: true, error: null, installed: false, admin: null, newOrders: 0, database: '', defaultUsername: '' });

  const refresh = useCallback(async () => {
    try {
      const s = await get('status');
      setState({
        loading: false,
        error: null,
        installed: s.installed,
        admin: s.admin,
        newOrders: s.new_orders,
        pricing: s.pricing,
        counts: s.counts,
        loginOptions: s.login_options || {},
        mustChangePassword: Boolean(s.must_change_password),
        twoFactorRequired: Boolean(s.two_factor_required),
        previousLogin: s.previous_login || null,
        pendingStaff: s.pending_staff || 0,
        database: s.database,
        defaultUsername: s.default_username,
      });
      return s;
    } catch (err) {
      setState((prev) => ({ ...prev, loading: false, error: err }));
      return null;
    }
  }, []);

  useEffect(() => {
    refresh();
    setAuthLostHandler(() => setState((prev) => ({ ...prev, admin: null })));
  }, [refresh]);

  const value = useMemo(
    () => ({ ...state, refresh, setNewOrders: (n) => setState((prev) => ({ ...prev, newOrders: n })) }),
    [state, refresh]
  );
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export const useSession = () => useContext(SessionContext);

/** Short messages in the corner: flash('Saved.') or flash('Oops', 'error'). */
export function FlashProvider({ children }) {
  const [items, setItems] = useState([]);
  const nextId = useRef(1);

  const dismiss = useCallback((id) => setItems((list) => list.filter((f) => f.id !== id)), []);
  const flash = useCallback(
    (message, type = 'success') => {
      if (!message) return;
      const id = nextId.current++;
      setItems((list) => [...list.slice(-3), { id, message, type }]);
      setTimeout(() => dismiss(id), type === 'error' ? 9000 : 4500);
    },
    [dismiss]
  );

  return (
    <FlashContext.Provider value={flash}>
      {children}
      <div className="flashes" role="status" aria-live="polite">
        {items.map((f) => (
          <div key={f.id} className={`flash ${f.type}`}>
            <span>{f.message}</span>
            <button type="button" onClick={() => dismiss(f.id)} aria-label="Dismiss">✕</button>
          </div>
        ))}
      </div>
    </FlashContext.Provider>
  );
}

export const useFlash = () => useContext(FlashContext);

/** Shows the API's "saved" message plus any per-photo warnings it sent back. */
export function useReport() {
  const flash = useFlash();
  return useCallback(
    (res) => {
      if (res?.message) flash(res.message);
      (res?.warnings || []).forEach((w) => flash(w, 'error'));
    },
    [flash]
  );
}

/** Loads data from the API for a page. Returns [data, reload, error]. */
export function useLoad(path) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const load = useCallback(async () => {
    try {
      setError(null);
      setData(await get(path));
    } catch (err) {
      setError(err);
    }
  }, [path]);
  useEffect(() => {
    load();
  }, [load]);
  return [data, load, error, setData];
}
