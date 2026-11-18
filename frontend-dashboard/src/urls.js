// Where the other parts of the shop live, from the .env files (read at build time):
//   VITE_API_URL     the CodeIgniter backend, e.g. https://api.cityfloristhubli.com (empty: same domain, /api)
//   VITE_SITE_URL    the public website, e.g. https://cityfloristhubli.com (empty: same domain)
//   VITE_ADMIN_BASE  the path the dashboard is served under: "/" on its own subdomain, "/admin" on the
//                    website's domain (development)

const trim = (url) => String(url || '').replace(/\/+$/, '');

/** "https://api.cityfloristhubli.com" or "" (same domain). API paths are appended: API_URL + '/api/admin/…'. */
export const API_URL = trim(import.meta.env.VITE_API_URL);

/** "https://cityfloristhubli.com" or "" (same domain). Page paths are appended: SITE_URL + '/shop'. */
export const SITE_URL = trim(import.meta.env.VITE_SITE_URL);

/** React Router's basename: "" on admin.cityfloristhubli.com, "/admin" when served under the website. */
export const ADMIN_BASE = trim(import.meta.env.VITE_ADMIN_BASE ?? '/admin');

/** With the API on its own subdomain the browser only sends the login cookies with "include". */
export const API_CREDENTIALS = API_URL ? 'include' : 'same-origin';
