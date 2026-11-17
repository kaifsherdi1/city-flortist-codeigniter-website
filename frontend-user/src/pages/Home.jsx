import { usePageMeta } from '../lib/seo.js';
import { useEffect } from 'react';
import Hero from '../sections/Hero.jsx';
import Offers from '../sections/Offers.jsx';
import Manifesto from '../sections/Manifesto.jsx';
import ReadyToGo from '../sections/ReadyToGo.jsx';
import Collections from '../sections/Collections.jsx';
import Wholesale from '../sections/Wholesale.jsx';
import Featured from '../sections/Featured.jsx';
import Wedding from '../sections/Wedding.jsx';
import HowToOrder from '../sections/HowToOrder.jsx';
import Reviews from '../sections/Reviews.jsx';
import Gallery from '../sections/Gallery.jsx';
import { useSite } from '../lib/site.jsx';
import { ScrollTrigger } from '../lib/motion.js';

export default function Home() {
  const { version } = useSite();

  // If newer data replaces the bundled snapshot, sections change height — re-measure all pinned triggers.
  useEffect(() => {
    if (!version) return;
    const id = setTimeout(() => {
      ScrollTrigger.sort();
      ScrollTrigger.refresh();
    }, 300);
    return () => clearTimeout(id);
  }, [version]);

  usePageMeta(); // site-wide title and description (bouquet shop & flower decoration in Hubballi)

  return (
    <>
      <Hero />
      <Offers />
      <Manifesto />
      <ReadyToGo />
      <Collections />
      <Wholesale />
      <Featured />
      <Wedding />
      <Gallery />
      <Reviews />
      <HowToOrder />
    </>
  );
}
