import { useEffect } from 'react';

// Per-page title, description and structured data (JSON-LD) for search engines. Google renders the
// React pages, so setting these when a page opens is enough for the shop, category and product pages.

const SITE = 'City Florist Hubballi';
const DEFAULT_DESCRIPTION =
  'City Florist, Hubballi (Hubli): bouquet shop and flower decorators near Court Circle, Deshpande Nagar. Fresh flower bouquets, chocolate bouquets, wedding car decoration, room decoration, bridal garlands, cakes and teddies. Order on WhatsApp +91 99000 94843.';

function setMeta(name, content, attr = 'name') {
  let el = document.head.querySelector(`meta[${attr}="${name}"]`);
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, name);
    document.head.appendChild(el);
  }
  el.setAttribute('content', content);
}

/** usePageMeta({ title, description, image, jsonLd }) — resets to the site defaults when the page closes. */
export function usePageMeta({ title, description, image, jsonLd } = {}) {
  const ld = jsonLd ? JSON.stringify(jsonLd) : '';
  useEffect(() => {
    const full = title ? `${title} · ${SITE}` : `${SITE} · Bouquet Shop & Flower Decoration near Court Circle`;
    const desc = (description || DEFAULT_DESCRIPTION).replace(/\s+/g, ' ').slice(0, 300);
    document.title = full;
    setMeta('description', desc);
    setMeta('og:title', full, 'property');
    setMeta('og:description', desc, 'property');
    if (image) setMeta('og:image', new URL(image, window.location.origin).href, 'property');

    let script = null;
    if (ld) {
      script = document.createElement('script');
      script.type = 'application/ld+json';
      script.dataset.page = '1';
      script.textContent = ld;
      document.head.appendChild(script);
    }
    return () => script?.remove();
  }, [title, description, image, ld]);
}

/** schema.org Product for a product page: name, photos, price in ₹ (when it has one). */
export function productJsonLd(p) {
  if (!p) return null;
  const url = `${window.location.origin}/product/${p.slug}`;
  const data = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: p.name,
    description: p.description || `${p.name} from City Florist, Hubballi.`,
    category: p.category?.name,
    image: (p.images || []).map((i) => new URL(i.src, window.location.origin).href),
    brand: { '@type': 'Brand', name: 'City Florist' },
    url,
  };
  if (p.pricing && !p.pricing.on_request && p.pricing.final > 0) {
    data.offers = {
      '@type': 'Offer',
      priceCurrency: 'INR',
      price: String(p.pricing.final),
      availability: 'https://schema.org/InStock',
      url,
      seller: { '@type': 'Florist', name: 'City Florist' },
    };
  }
  return data;
}
