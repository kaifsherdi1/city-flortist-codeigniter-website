import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import { useGSAP } from '@gsap/react';

gsap.registerPlugin(ScrollTrigger);

export const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
export const isTouch = () => window.matchMedia('(hover: none), (pointer: coarse)').matches;

let lenis = null;

/** Starts Lenis smooth scrolling and wires it into GSAP's ticker so ScrollTrigger stays in sync. */
export function startSmoothScroll() {
  if (lenis || reducedMotion()) return lenis;
  lenis = new Lenis({ duration: 1.15, easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)), smoothWheel: true });
  lenis.on('scroll', ScrollTrigger.update);
  gsap.ticker.add((time) => lenis.raf(time * 1000));
  gsap.ticker.lagSmoothing(0);
  return lenis;
}

export const getLenis = () => lenis;

export function scrollToTop() {
  if (lenis) lenis.scrollTo(0, { immediate: true });
  else window.scrollTo(0, 0);
}

export function scrollTo(target) {
  if (lenis) lenis.scrollTo(target, { offset: -80, duration: 1.4 });
  else document.querySelector(target)?.scrollIntoView({ behavior: 'smooth' });
}

export function lockScroll(locked) {
  if (lenis) locked ? lenis.stop() : lenis.start();
  document.documentElement.classList.toggle('scroll-locked', locked);
}

/**
 * Phones and touch tablets. They get a lighter page: no 3D petals, and the scroll animations below the hero
 * are set up after the page is on screen instead of all at once while it first renders (on a mid-range phone
 * that first render, with every section measuring the page for its ScrollTriggers, froze it for seconds).
 * Desktops with a mouse are unaffected.
 */
export const isPhoneLike = () => window.matchMedia('(max-width: 899px), (hover: none) and (pointer: coarse)').matches;

const idleQueue = [];
let pumping = false;

/** Runs queued setups one per idle moment, starting once the preloader has lifted. */
function pump() {
  if (pumping) return;
  pumping = true;
  const ric = window.requestIdleCallback || ((fn) => setTimeout(fn, 60));
  const next = () =>
    ric(
      () => {
        const job = idleQueue.shift();
        if (job && !job.cancelled) job.run();
        if (idleQueue.length) return next();
        pumping = false;
        // Everything is set up: settle all start/end positions once (pinned sections push later ones down).
        ScrollTrigger.refresh();
      },
      { timeout: 700 }
    );
  if (window.__cfReady) next();
  else window.addEventListener('cf:ready', next, { once: true });
}

/**
 * useGSAP for sections below the hero. On desktops it is exactly useGSAP. On phones the setup is queued (in page
 * order) and runs when the browser is idle; it still belongs to the component's GSAP context, so it is reverted on
 * unmount like any other, and a setup that hasn't run yet is simply dropped.
 */
export function useLazyGSAP(setup, config) {
  return useGSAP((context, contextSafe) => {
    if (!isPhoneLike()) return setup(context, contextSafe);
    let cleanup;
    const job = { cancelled: false, run: contextSafe(() => (cleanup = setup(context, contextSafe))) };
    idleQueue.push(job);
    pump();
    return () => {
      job.cancelled = true;
      if (typeof cleanup === 'function') cleanup();
    };
  }, config);
}

export { gsap, ScrollTrigger };
