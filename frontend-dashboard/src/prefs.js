import { useCallback, useEffect, useState } from 'react';

// Per-device display preferences (theme, collapsed sidebar). Kept in localStorage, which may be
// unavailable (private windows), so every read and write is guarded and defaults are used instead.
const KEY = 'cf-admin-prefs';
const DEFAULTS = { theme: 'light', rail: false };

function read() {
  try {
    return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(KEY) || '{}') };
  } catch {
    return { ...DEFAULTS };
  }
}

function write(prefs) {
  try {
    localStorage.setItem(KEY, JSON.stringify(prefs));
  } catch {
    // Not saved; the choice still applies until the page is reloaded.
  }
}

const dark = (theme) => theme === 'dark' || (theme === 'system' && window.matchMedia?.('(prefers-color-scheme: dark)').matches);

/** Sets <html data-theme> so the CSS tokens switch. Called before the first render, too. */
export function applyTheme(theme = read().theme) {
  document.documentElement.dataset.theme = dark(theme) ? 'dark' : 'light';
}

export function usePrefs() {
  const [prefs, setPrefs] = useState(read);

  useEffect(() => {
    applyTheme(prefs.theme);
    if (prefs.theme !== 'system' || !window.matchMedia) return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const follow = () => applyTheme('system');
    mq.addEventListener?.('change', follow);
    return () => mq.removeEventListener?.('change', follow);
  }, [prefs.theme]);

  const update = useCallback((patch) => {
    setPrefs((p) => {
      const next = { ...p, ...patch };
      write(next);
      return next;
    });
  }, []);

  return [prefs, update];
}
