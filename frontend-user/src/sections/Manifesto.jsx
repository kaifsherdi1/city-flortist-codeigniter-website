import { useRef } from 'react';
import { gsap, useLazyGSAP } from '../lib/motion.js';

const TEXT =
  'City Florist is a flower shop near Court Circle in Hubballi. We make fresh flower and chocolate bouquets, decorate wedding cars and rooms, and tie bridal garlands, for birthdays, anniversaries, apologies, proposals and weddings.';

/** Large statement whose words light up as you scroll past. */
export default function Manifesto() {
  const root = useRef(null);

  useLazyGSAP(
    () => {
      gsap.fromTo(
        '.manifesto-text .mw',
        { opacity: 0.12 },
        {
          opacity: 1,
          stagger: 0.05,
          ease: 'none',
          scrollTrigger: { trigger: '.manifesto-text', start: 'top 80%', end: 'bottom 45%', scrub: true },
        }
      );
      // On phones/tablets the photo sits under the text, so it only lifts a little (a bigger lift ran into the words).
      gsap.to('.manifesto-img', {
        yPercent: window.matchMedia('(max-width: 899px)').matches ? -8 : -25,
        rotate: 6,
        ease: 'none',
        scrollTrigger: { trigger: root.current, start: 'top bottom', end: 'bottom top', scrub: true },
      });
    },
    { scope: root }
  );

  return (
    <section className="manifesto container" ref={root}>
      <span className="eyebrow">About the shop</span>
      <p className="manifesto-text">
        {TEXT.split(' ').map((w, i) => (
          <span className="mw" key={i}>
            {w}{' '}
          </span>
        ))}
      </p>
      <img className="manifesto-img" src="/img/story.webp" alt="" loading="lazy" />
    </section>
  );
}
