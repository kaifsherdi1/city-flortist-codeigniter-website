import { useRef } from 'react';
import { Link } from 'react-router-dom';
import { gsap, reducedMotion, useLazyGSAP } from '../lib/motion.js';
import { useSite } from '../lib/site.jsx';
import { waLink } from '../lib/api.js';

const PANELS = [
  {
    slug: 'car-decoration',
    kicker: 'For the procession',
    title: 'Wedding Car Decoration',
    text: 'Fresh gerberas, roses, orchids and ribbons on the bonnet, doors and mirrors, fixed to last the whole procession.',
    img: '/img/wedding-car.webp',
  },
  {
    slug: 'room-decoration',
    kicker: 'For the wedding night',
    title: 'Wedding-Night Room Décor',
    text: 'Rose petals, hearts, candles, fairy lights and fresh flowers, set up in your room before you arrive.',
    img: '/img/wedding-room.webp',
  },
  {
    slug: 'garlands',
    kicker: 'Varmala & haar',
    title: 'Bride & Groom Garlands',
    text: 'Jasmine, rose, carnation and orchid garlands, matched to the wedding outfit and made fresh that morning.',
    img: '/img/wedding-garland.webp',
  },
];

/** Stacked sticky cards: each new card slides over and pushes the previous one back in depth. */
export default function Wedding() {
  const { settings } = useSite();
  const root = useRef(null);

  useLazyGSAP(
    () => {
      if (reducedMotion()) return;
      const cards = gsap.utils.toArray('.wed-card');
      cards.forEach((card, i) => {
        if (i === cards.length - 1) return;
        const st = { trigger: cards[i + 1], start: 'top bottom', end: 'top 15%', scrub: true };
        gsap.to(card.querySelector('.wed-inner'), { scale: 0.9, rotateX: 8, ease: 'none', force3D: true, scrollTrigger: st });
        gsap.fromTo(card.querySelector('.wed-shade'), { opacity: 0 }, { opacity: 0.1, ease: 'none', scrollTrigger: st });
      });
      cards.forEach((card) => {
        gsap.from(card.querySelectorAll('.wed-copy > *'), {
          y: 40,
          opacity: 0,
          stagger: 0.08,
          duration: 1,
          ease: 'power3.out',
          scrollTrigger: { trigger: card, start: 'top 60%' },
        });
      });
      gsap.from('.wedding-head .display', {
        y: 80,
        opacity: 0,
        duration: 1.2,
        ease: 'expo.out',
        scrollTrigger: { trigger: '.wedding-head', start: 'top 80%' },
      });
    },
    { scope: root }
  );

  return (
    <section className="wedding" id="wedding" ref={root}>
      <div className="wedding-head container">
        <span className="eyebrow">Weddings</span>
        <h2 className="display">
          Car decoration, room décor and garlands
        </h2>
      </div>
      <div className="wed-stack">
        {PANELS.map((p, i) => (
          <div className="wed-card" key={p.slug} style={{ '--i': i }}>
            <div className="wed-inner">
              <div className={`wed-media ${p.img ? '' : 'wed-art'}`}>
                {p.img ? (
                  <img src={p.img} alt={p.title} loading="lazy" />
                ) : (
                  <div className="candles" aria-hidden="true">
                    {Array.from({ length: 14 }, (_, k) => (
                      <span key={k} style={{ '--k': k }} />
                    ))}
                    <b className="script">Forever</b>
                  </div>
                )}
              </div>
              <div className="wed-copy">
                <span className="wed-num">0{i + 1} / 03</span>
                <span className="eyebrow">{p.kicker}</span>
                <h3 className="display">{p.title}</h3>
                <p>{p.text}</p>
                <div className="wed-actions">
                  <Link to={`/shop?cat=${p.slug}`} className="btn btn-gold" data-cursor="View">
                    See designs
                  </Link>
                  <a
                    className="btn btn-outline"
                    href={waLink(settings.whatsapp_number, `Hi City Florist 🌸 I would like a quote for ${p.title}.`)}
                    target="_blank"
                    rel="noreferrer"
                  >
                    Get a quote
                  </a>
                </div>
              </div>
              <span className="wed-shade" aria-hidden="true" />
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}
