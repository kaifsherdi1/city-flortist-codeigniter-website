import { useRef } from 'react';
import { Link } from 'react-router-dom';
import { gsap, ScrollTrigger, reducedMotion, useLazyGSAP } from '../lib/motion.js';
import { useSite } from '../lib/site.jsx';
import { waLink } from '../lib/api.js';
import { useOrder, WhatsAppIcon } from '../components/OrderModal.jsx';
import Photo from '../components/Photo.jsx';
import SplitText from '../components/SplitText.jsx';

const pad = (n) => String(n).padStart(2, '0');
const PERKS = [
  ['Fresh every morning', 'Straight from the market to our shop in Hubballi.'],
  ['Any quantity', 'By the bunch or by the kilo, for one event or every week.'],
  ['Same-day pickup', 'Order on WhatsApp and collect today, or ask about delivery.'],
];

/** Loose flowers sold in bulk (the "Wholesale Flowers" category, managed in the dashboard). */
export default function Wholesale() {
  const { wholesale = [], settings } = useSite();
  const order = useOrder();
  const root = useRef(null);
  const n = wholesale.length;

  // Only transforms and opacity, each played once: nothing here re-paints while the page scrolls.
  useLazyGSAP(
    () => {
      if (!n || reducedMotion()) return;
      gsap.from('.ws-head .wi', {
        yPercent: 110,
        stagger: 0.06,
        duration: 1.1,
        ease: 'expo.out',
        scrollTrigger: { trigger: '.ws-head', start: 'top 85%' },
      });
      gsap.from('.ws-intro > *', {
        y: 30,
        opacity: 0,
        stagger: 0.08,
        duration: 0.9,
        ease: 'power3.out',
        scrollTrigger: { trigger: '.ws-intro', start: 'top 85%' },
      });
      revealInBatches('.ws-tile', { y: 90, opacity: 0, scale: 0.94 });
      revealInBatches('.ws-perk', { y: 40, opacity: 0 });
    },
    { scope: root, dependencies: [n], revertOnUpdate: true }
  );

  if (!n) return null;
  const bulk = waLink(settings.whatsapp_number, 'Hi City Florist 🌸 I would like to place a wholesale / bulk flower order.');

  return (
    <section className="wholesale" id="wholesale" ref={root}>
      <div className="container">
        <div className="ws-head">
          <span className="eyebrow">Wholesale · Fresh stock daily</span>
          <SplitText as="h2" className="display" text="Loose flowers in bulk" />
        </div>
        <div className="ws-intro">
          <p className="muted">
            Loose fresh flowers in bulk for weddings, events, temples, shops and fellow florists. Market rates change every day, so
            message us for today’s price and the quantity you need.
          </p>
          <div className="ws-ctas">
            <a className="btn btn-wa" href={bulk} target="_blank" rel="noreferrer" data-cursor="Chat">
              <WhatsAppIcon /> Bulk order on WhatsApp
            </a>
            <Link to="/shop?cat=wholesale-flowers" className="link-arrow" data-cursor="Shop">
              See all wholesale →
            </Link>
          </div>
        </div>

        <div className="ws-grid">
          {wholesale.map((p, i) => {
            const img = p.images?.[0];
            return (
              <article className="ws-tile" key={p.id}>
                <Link to={`/product/${p.slug}`} className="ws-media" data-cursor="View">
                  {img ? <Photo src={img.thumb} alt={p.name} loading="lazy" decoding="async" /> : <span className="script">{p.name}</span>}
                  <span className="ws-num">{pad(i + 1)}</span>
                </Link>
                <div className="ws-info">
                  <h3>
                    <Link to={`/product/${p.slug}`}>{p.name}</Link>
                  </h3>
                  <button className="btn btn-gold btn-sm" onClick={() => order(p)} data-cursor="Buy">
                    Buy
                  </button>
                </div>
              </article>
            );
          })}
        </div>

        <ul className="ws-perks">
          {PERKS.map(([title, text]) => (
            <li className="ws-perk" key={title}>
              <b>{title}</b>
              <span>{text}</span>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

/** Reveals elements in small groups as they scroll in, so tiles in the same row rise together. */
function revealInBatches(selector, from) {
  gsap.set(selector, from);
  ScrollTrigger.batch(selector, {
    start: 'top 92%',
    once: true,
    onEnter: (els) => gsap.to(els, { y: 0, opacity: 1, scale: 1, stagger: 0.08, duration: 1, ease: 'expo.out', overwrite: true }),
  });
}
