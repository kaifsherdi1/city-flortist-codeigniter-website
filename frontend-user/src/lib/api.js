// Snapshot of the catalogue made by `php spark florist:export-static` (backend/). Bundled into the JavaScript,
// so it can't fail to load separately.
import siteSnapshot from '../catalog/site.json';
import productsSnapshot from '../catalog/products.json';

async function request(url, options = {}) {
  let res;
  try {
    res = await fetch(url, {
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      ...options,
    });
  } catch {
    throw offline();
  }
  // No PHP on this host (e.g. Vercel answers /api with a plain 404 page).
  if (!(res.headers.get('content-type') || '').includes('application/json')) throw offline();
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(data.error || 'Something went wrong. Please try again.');
    err.status = res.status;
    throw err;
  }
  return data;
}

function offline() {
  const err = new Error('The shop server is unreachable.');
  err.offline = true;
  return err;
}

const snapshot = async (name) => (name === 'site' ? siteSnapshot : productsSnapshot);

/** The bundled site data, ready to render on the very first frame (offers that have ended are dropped). */
export const siteNow = () => ({ ...siteSnapshot, offers: siteSnapshot.offers.filter((o) => new Date(o.ends_at) > new Date()) });
/** The bundled product list, for sections that can render before the API answers. */
export const productsNow = () => productsSnapshot.products;

/**
 * True when live API data says the same as the snapshot. The snapshot serves photos from /catalog/
 * (copies of /uploads/), so paths are compared as if they were the same folder.
 */
export const sameAsSnapshot = (live, snap) =>
  JSON.stringify(live).replaceAll('"/uploads/', '"/catalog/') === JSON.stringify(snap);

export const isStaticOnly = () => STATIC_ONLY;

// Set at build time on hosts without PHP (see vercel.json): skip the API and use the snapshot straight away.
const STATIC_ONLY = import.meta.env.VITE_STATIC_CATALOG === '1';

/** Calls the API, falling back to the bundled catalogue only when the server can't be reached. */
const withSnapshot = (live, fallback) =>
  STATIC_ONLY
    ? fallback()
    : live().catch((err) => {
        if (!err.offline) throw err;
        return fallback();
      });

export const api = {
  site: () =>
    withSnapshot(
      () => request('/api/site'),
      async () => {
        const site = await snapshot('site');
        return { ...site, offers: site.offers.filter((o) => new Date(o.ends_at) > new Date()) };
      }
    ),
  products: (cat) =>
    withSnapshot(
      () => request('/api/products' + (cat ? `?cat=${encodeURIComponent(cat)}` : '')),
      async () => {
        const data = await snapshot('products');
        return cat ? { ...data, products: data.products.filter((p) => p.category.slug === cat) } : data;
      }
    ),
  product: (slug) =>
    withSnapshot(
      () => request(`/api/products?slug=${encodeURIComponent(slug)}`),
      async () => {
        const { products } = await snapshot('products');
        const product = products.find((p) => p.slug === slug);
        if (!product) throw Object.assign(new Error('Product not found'), { status: 404 });
        const related = products
          .filter((p) => p.category.slug === product.category.slug && p.slug !== slug)
          .sort((a, b) => b.featured - a.featured)
          .slice(0, 4);
        return { product, related };
      }
    ),
  order: (payload) =>
    STATIC_ONLY ? Promise.reject(offline()) : request('/api/orders', { method: 'POST', body: JSON.stringify(payload) }),
  review: (payload) =>
    STATIC_ONLY ? Promise.reject(offline()) : request('/api/reviews', { method: 'POST', body: JSON.stringify(payload) }),
};

export const rupees = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');

/** Fallback WhatsApp link when the API is unreachable, so a sale is never lost. */
export function waLink(number, text) {
  const digits = String(number || '').replace(/\D/g, '');
  return `https://wa.me/${digits}?text=${encodeURIComponent(text)}`;
}
