import { useEffect, useLayoutEffect } from 'react';
import { Link, Route, Routes, useLocation } from 'react-router-dom';
import { SiteProvider } from './lib/site.jsx';
import { startSmoothScroll, scrollToTop, ScrollTrigger } from './lib/motion.js';
import { OrderProvider } from './components/OrderModal.jsx';
import Preloader from './components/Preloader.jsx';
import Cursor from './components/Cursor.jsx';
import Header from './components/Header.jsx';
import Footer from './components/Footer.jsx';
import Home from './pages/Home.jsx';
import Shop from './pages/Shop.jsx';
import Product from './pages/Product.jsx';
import { Privacy, Terms } from './pages/Policy.jsx';
import { Account, Login, Register } from './pages/Auth.jsx';
import { AccountProvider } from './lib/account.jsx';

// The preloader only plays when the visitor first lands on the home page.
const showPreloader = window.location.pathname === '/';
if (!showPreloader) window.__cfReady = true;

export default function App() {
  const location = useLocation();

  useEffect(() => {
    startSmoothScroll();
  }, []);

  useLayoutEffect(() => {
    scrollToTop();
    const id = setTimeout(() => ScrollTrigger.refresh(), 100);
    return () => clearTimeout(id);
  }, [location.pathname]);

  return (
    <SiteProvider>
      <AccountProvider>
      <OrderProvider>
        {showPreloader && <Preloader />}
        <Cursor />
        <div className="grain" aria-hidden="true" />
        <Header />
        <main key={location.pathname} className="page">
          <Routes>
            <Route path="/" element={<Home />} />
            <Route path="/shop" element={<Shop />} />
            <Route path="/product/:slug" element={<Product />} />
            <Route path="/privacy" element={<Privacy />} />
            <Route path="/terms" element={<Terms />} />
            <Route path="/register" element={<Register />} />
            <Route path="/login" element={<Login />} />
            <Route path="/account" element={<Account />} />
            <Route path="*" element={<NotFound />} />
          </Routes>
        </main>
        <Footer />
      </OrderProvider>
      </AccountProvider>
    </SiteProvider>
  );
}

function NotFound() {
  return (
    <div className="empty-state container pd-missing">
      <span className="script">Oops</span>
      <p>This page doesn’t exist. Have a look at our bouquets instead.</p>
      <Link className="btn btn-gold" to="/">Go home</Link>
    </div>
  );
}
