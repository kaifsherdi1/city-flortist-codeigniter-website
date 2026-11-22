import { usePageMeta } from '../lib/seo.js';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Flip } from 'gsap/Flip';
import { gsap, ScrollTrigger, reducedMotion, scrollTo } from '../lib/motion.js';
import { api, waLink } from '../lib/api.js';
import { useSite } from '../lib/site.jsx';
import ProductCard from '../components/ProductCard.jsx';
import SplitText from '../components/SplitText.jsx';
import Pager from '../components/Pager.jsx';
import { WhatsAppIcon } from '../components/OrderModal.jsx';

gsap.registerPlugin(Flip);

const PER_PAGE = 10;

export default function Shop() {
  const { settings } = useSite();
  const [params, setParams] = useSearchParams();
  const cat = params.get('cat') || '';
  const [data, setData] = useState({ products: [], categories: [] });
  const [loading, setLoading] = useState(true);
  const [sort, setSort] = useState('featured');
  const grid = useRef(null);
  const flipState = useRef(null);
  const head = useRef(null);

  useEffect(() => {
    api
      .products()
      .then(setData)
      .finally(() => setLoading(false));
  }, []);

  const current = data.categories.find((c) => c.slug === cat);

  const list = useMemo(() => {
    let l = cat ? data.products.filter((p) => p.category.slug === cat) : data.products;
    if (sort === 'low') l = [...l].sort((a, b) => (a.pricing.final || 1e9) - (b.pricing.final || 1e9));
    if (sort === 'high') l = [...l].sort((a, b) => (b.pricing.final || 0) - (a.pricing.final || 0));
    if (sort === 'offers') l = [...l].sort((a, b) => b.pricing.discount_pct - a.pricing.discount_pct);
    return l;
  }, [data.products, cat, sort]);

  // ?page= keeps the page across reloads and the back button; a page past the end shows the last one.
  const pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
  const page = Math.min(Math.max(1, Number(params.get('page')) || 1), pages);
  const pageItems = useMemo(() => list.slice((page - 1) * PER_PAGE, page * PER_PAGE), [list, page]);

  const goToPage = (n) => {
    const next = new URLSearchParams(params);
    if (n > 1) next.set('page', String(n));
    else next.delete('page');
    setParams(next);
    scrollTo('#shop-results');
  };

  const changeSort = (value) => {
    setSort(value);
    if (params.get('page')) {
      const next = new URLSearchParams(params);
      next.delete('page');
      setParams(next, { replace: true });
    }
  };

  usePageMeta({
    title: current ? `${current.name} in Hubballi` : 'Shop Bouquets, Decoration, Garlands & Cakes',
    description: current
      ? `${current.name} from City Florist, Hubballi: ${current.tagline || ''} Browse photos and prices, order on WhatsApp. Near Court Circle, Deshpande Nagar.`
      : 'All bouquets, chocolate bouquets, wedding car and room decoration, bridal garlands, teddies and cakes from City Florist, Hubballi. Order on WhatsApp.',
  });

  const choose = (slug) => {
    if (grid.current) {
      const cards = grid.current.querySelectorAll('.product-card');
      gsap.set(cards, { opacity: 1, y: 0, overwrite: true }); // cards still waiting to scroll into view
      flipState.current = Flip.getState(cards);
    }
    setParams(slug ? { cat: slug } : {});
  };

  // Category change: animate cards from their old positions to the new layout.
  // Otherwise each card fades up only when it scrolls into view.
  useLayoutEffect(() => {
    if (!grid.current) {
      flipState.current = null;
      return;
    }
    const cards = grid.current.querySelectorAll('.product-card');
    if (flipState.current) {
      Flip.from(flipState.current, {
        targets: cards,
        duration: 0.7,
        ease: 'power3.inOut',
        stagger: 0.02,
        absolute: true,
        onEnter: (els) => gsap.fromTo(els, { opacity: 0, scale: 0.85, y: 40 }, { opacity: 1, scale: 1, y: 0, duration: 0.6, stagger: 0.03 }),
        onLeave: (els) => gsap.to(els, { opacity: 0, scale: 0.85, duration: 0.4 }),
        onComplete: () => ScrollTrigger.refresh(),
      });
      flipState.current = null;
    } else if (cards.length && !reducedMotion()) {
      gsap.set(cards, { opacity: 0, y: 60 });
      const triggers = ScrollTrigger.batch(cards, {
        start: 'top 92%',
        once: true,
        onEnter: (els) => gsap.to(els, { opacity: 1, y: 0, stagger: 0.08, duration: 0.9, ease: 'expo.out', overwrite: true }),
      });
      return () => triggers.forEach((t) => t.kill());
    }
  }, [pageItems]);

  useLayoutEffect(() => {
    const ctx = gsap.context(() => {
      gsap.from('.shop-title .wi', { yPercent: 110, stagger: 0.06, duration: 1.2, ease: 'expo.out' });
      gsap.from('.shop-tabs > *', { y: 20, opacity: 0, stagger: 0.04, duration: 0.8, delay: 0.3 });
    }, head);
    return () => ctx.revert();
  }, [cat, loading]);

  const title = current ? current.name : 'All products';

  return (
    <div className="shop-page">
      <header className="shop-head container" ref={head}>
        <span className="eyebrow">{current ? 'Collection' : 'Shop'}</span>
        <SplitText as="h1" key={title} className="display shop-title" text={title} />
        {current?.tagline && <p className="muted shop-tagline">{current.tagline}</p>}

        <div className="shop-bar">
          <div className="shop-tabs" role="tablist">
            <button className={!cat ? 'active' : ''} onClick={() => choose('')}>
              All <sup>{data.products.length}</sup>
            </button>
            {data.categories.map((c) => (
              <button key={c.slug} className={cat === c.slug ? 'active' : ''} onClick={() => choose(c.slug)}>
                {c.name} <sup>{c.count}</sup>
              </button>
            ))}
          </div>
          <label className="shop-sort">
            <span className="muted">Sort</span>
            <select value={sort} onChange={(e) => changeSort(e.target.value)}>
              <option value="featured">Featured</option>
              <option value="offers">Best offers</option>
              <option value="low">Price: low to high</option>
              <option value="high">Price: high to low</option>
            </select>
          </label>
        </div>
      </header>

      <section className="container" id="shop-results">
        {loading ? (
          <div className="shop-grid">
            {Array.from({ length: 6 }, (_, i) => (
              <div className="skeleton" key={i} />
            ))}
          </div>
        ) : list.length ? (
          <>
            <div className="shop-grid" ref={grid}>
              {pageItems.map((p, i) => (
                <ProductCard product={p} index={i} key={p.id} />
              ))}
            </div>
            {pages > 1 && (
              <div className="shop-pages">
                <span className="muted">
                  {(page - 1) * PER_PAGE + 1}–{Math.min(page * PER_PAGE, list.length)} of {list.length}
                </span>
                <Pager page={page} pages={pages} onChange={goToPage} />
              </div>
            )}
          </>
        ) : (
          <div className="empty-state">
            <span className="script">{current?.name || 'Coming soon'}</span>
            <p>
              We create these designs to order. Send us your idea, date and budget on WhatsApp and we’ll share photos of
              our past work and a quote.
            </p>
            <a
              className="btn btn-wa"
              target="_blank"
              rel="noreferrer"
              href={waLink(settings.whatsapp_number, `Hi City Florist 🌸 I would like details and pricing for ${current?.name || 'a custom order'}.`)}
            >
              <WhatsAppIcon /> Enquire on WhatsApp
            </a>
          </div>
        )}
      </section>
    </div>
  );
}
