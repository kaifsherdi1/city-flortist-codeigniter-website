import { useRef } from 'react';
import { gsap, useLazyGSAP } from '../lib/motion.js';

const STEPS = [
  ['Choose', 'Browse bouquets, chocolate bouquets, décor, garlands, teddies and cakes, or ask us for something custom.'],
  ['Tap Buy', 'Enter your name and number. The order message is typed for you.'],
  ['Confirm on WhatsApp', 'We confirm the design, delivery time and payment with you on WhatsApp.'],
];

export default function HowToOrder() {
  const root = useRef(null);

  useLazyGSAP(
    () => {
      gsap.from('.step', {
        y: 80,
        opacity: 0,
        stagger: 0.15,
        duration: 1.2,
        ease: 'expo.out',
        scrollTrigger: { trigger: '.steps', start: 'top 80%' },
      });
      gsap.fromTo('.steps-line i', { scaleX: 0 }, {
        scaleX: 1,
        ease: 'none',
        scrollTrigger: { trigger: '.steps', start: 'top 75%', end: 'bottom 50%', scrub: true },
      });
    },
    { scope: root }
  );

  return (
    <section className="how container" ref={root}>
      <span className="eyebrow">Ordering</span>
      <h2 className="display">
        Order on WhatsApp in three steps
      </h2>
      <div className="steps">
        <div className="steps-line"><i /></div>
        {STEPS.map(([title, text], i) => (
          <div className="step" key={title}>
            <span className="step-num">{i + 1}</span>
            <h3>{title}</h3>
            <p>{text}</p>
          </div>
        ))}
      </div>
    </section>
  );
}
