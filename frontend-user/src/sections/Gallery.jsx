import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useGSAP } from '@gsap/react';
import { gsap } from '../lib/motion.js';
import { api, isStaticOnly, productsNow, sameAsSnapshot } from '../lib/api.js';
import Photo from '../components/Photo.jsx';

/** 15 photos spread across categories rather than the first 15 bouquets. */
function pick(products) {
  const all = products.filter((p) => p.images[0]).map((p) => ({ src: p.images[0].thumb, slug: p.slug, name: p.name }));
  return all.map((v, i) => [((i * 7919) % 97) / 97, v]).sort((a, b) => a[0] - b[0]).map(([, v]) => v).slice(0, 15);
}

/** Three columns of real work that scroll at different speeds. */
export default function Gallery() {
  const root = useRef(null);
  // Starts from the bundled catalogue so it renders with the rest of the page; the live list only
  // replaces it if the photos shown would actually change.
  const [images, setImages] = useState(() => pick(productsNow()));

  useEffect(() => {
    if (isStaticOnly()) return;
    api
      .products()
      .then(({ products }) => {
        const live = pick(products);
        setImages((cur) => (sameAsSnapshot(live, cur) ? cur : live));
      })
      .catch(() => {});
  }, []);

  useGSAP(
    () => {
      if (!images.length) return;
      // Columns slide past each other: the outer two rise, the middle one sinks (yPercent of each
      // column's height, from → to). None starts above its resting place, so while the heading is on
      // screen nothing rides over it; the outer columns only pass above rest once it has scrolled away.
      // Phones show two narrow columns: half the travel keeps the effect without big empty gaps.
      const k = window.matchMedia('(max-width: 767px)').matches ? 0.5 : 1;
      const drift = [[12, -12], [0, 20], [20, -20]].map(([a, b]) => [a * k, b * k]);
      gsap.utils.toArray('.gal-col').forEach((col, i) => {
        gsap.fromTo(col, { yPercent: drift[i][0] }, {
          yPercent: drift[i][1],
          ease: 'none',
          scrollTrigger: { trigger: root.current, start: 'top bottom', end: 'bottom top', scrub: true },
        });
      });
    },
    { scope: root, dependencies: [images.length], revertOnUpdate: true }
  );

  if (!images.length) return null;
  const cols = [0, 1, 2].map((c) => images.filter((_, i) => i % 3 === c));

  return (
    <section className="gallery" ref={root}>
      <div className="gallery-title container">
        <span className="eyebrow">From the shop</span>
        <h2 className="display">
          Photos of recent orders
        </h2>
      </div>
      <div className="gal-grid">
        {cols.map((col, c) => (
          <div className="gal-col" key={c}>
            {col.map((img) => (
              <Link to={`/product/${img.slug}`} key={img.slug} className="gal-item" data-cursor="View">
                <Photo src={img.src} alt={img.name} loading="lazy" />
              </Link>
            ))}
          </div>
        ))}
      </div>
    </section>
  );
}
