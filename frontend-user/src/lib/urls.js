// Where the other parts of the shop live, from the .env files (read at build time):
//   VITE_API_URL    the CodeIgniter backend, e.g. https://api.cityfloristhubli.com (empty: same domain, /api)
//   VITE_ADMIN_URL  the owner dashboard, e.g. https://admin.cityfloristhubli.com (default: /admin on this domain)
// In development both are empty/default and Vite's dev server passes /api and /admin through (vite.config.js).

const trim = (url) => String(url || '').replace(/\/+$/, '');

/** "https://api.cityfloristhubli.com" or "" (same domain). API paths are appended: API_URL + '/api/site'. */
export const API_URL = trim(import.meta.env.VITE_API_URL);

/** The dashboard's address, for the "Staff login" link. */
export const ADMIN_URL = trim(import.meta.env.VITE_ADMIN_URL) || '/admin';

/**
 * Cookies (customer login, CSRF token) must go with API calls. On one domain "same-origin" is enough; with the
 * API on its own subdomain the browser only sends them with "include".
 */
export const API_CREDENTIALS = API_URL ? 'include' : 'same-origin';
