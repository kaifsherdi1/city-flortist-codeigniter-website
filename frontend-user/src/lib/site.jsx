import { createContext, useContext, useEffect, useState } from 'react';
import { api, isStaticOnly, sameAsSnapshot, siteNow } from './api.js';

const fallback = {
  settings: {
    shop_name: 'City Florist',
    whatsapp_number: '919900094843',
    shop_phone: '+91 99000 94843',
    address: 'Shop No. B-6, C Block, Ravenkar Complex, T.B. Road, Near Court Circle, Deshpande Nagar, Hubballi, Karnataka 580029',
    hours: 'Open daily · 8:00 AM – 10:00 PM',
    // The shop's own Google Maps listing (see backend/app/Support/Settings.php).
    map_embed: 'https://maps.google.com/maps?cid=10825630060150326606&hl=en&z=17&output=embed',
    map_link: 'https://www.google.com/maps/search/?api=1&query=City+Florist+Hubballi&query_place_id=ChIJU9x_hUPXuDsRTgUN3S5dPJY',
    hero_title: 'Fresh flowers. Made up today.',
    hero_subtitle:
      'Bouquets, chocolate bouquets, wedding car and room decoration, bridal garlands, cakes and teddies. Near Court Circle, Deshpande Nagar, Hubballi.',
  },
  categories: [],
  offers: [],
  featured: [],
  ready: [],
  wholesale: [],
  reviews: { summary: { count: 0, average: 0 }, items: [] },
};

const merge = (data) => ({ ...fallback, ...data, settings: { ...fallback.settings, ...data.settings } });

const SiteContext = createContext({ ...merge(siteNow()), version: 0 });

/**
 * Renders straight away with the catalogue bundled into the site, then checks the live API in the
 * background. The page only re-renders (and rebuilds its scroll animations) if the shop's data has
 * actually changed since the snapshot was made; before, every visit rendered the whole home page twice.
 */
export function SiteProvider({ children }) {
  const [state, setState] = useState(() => ({ ...merge(siteNow()), version: 0 }));

  useEffect(() => {
    if (isStaticOnly()) return;
    const snap = siteNow();
    api
      .site()
      .then((data) => {
        if (sameAsSnapshot(data, snap)) return;
        setState((s) => ({ ...merge(data), version: s.version + 1 }));
      })
      .catch(() => {});
  }, []);

  return <SiteContext.Provider value={state}>{children}</SiteContext.Provider>;
}

export const useSite = () => useContext(SiteContext);

/** "Get directions": the shop's Google Maps listing, or a search by address if no link is saved. */
export const directionsUrl = (settings) =>
  settings.map_link || `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent('City Florist ' + (settings.address || 'Hubballi'))}`;
