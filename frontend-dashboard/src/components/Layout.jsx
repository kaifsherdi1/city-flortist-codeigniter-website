import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { post } from '../api.js';
import { useFlash, useSession } from '../session.jsx';
import { usePrefs } from '../prefs.js';
import Icon, { BrandMark } from './Icon.jsx';
import { FloristArt, OwnerArt } from './AuthArt.jsx';
import { SITE_URL } from '../urls.js';

/** Sidebar: a plain link, or a group with sub-links. `match` decides which group is "current". */
const NAV = [
  { to: '/', label: 'Dashboard', icon: 'home', end: true },
  {
    label: 'Products', icon: 'grid', match: '/products',
    items: [
      ['/products', 'All products'],
      ['/products/new', 'Add product'],
      ['/products?unpriced=1', 'Without price'],
      ['/products?featured=1', 'Featured'],
    ],
  },
  {
    label: 'Daily Offers', icon: 'tag', match: '/offers',
    items: [
      ['/offers', 'All offers'],
      ['/offers#new', 'New offer'],
    ],
  },
  {
    label: 'Orders', icon: 'bag', match: '/orders', badge: 'new',
    items: [
      ['/orders', 'All orders'],
      ['/orders?status=new', 'New'],
      ['/orders?status=contacted', 'Contacted'],
      ['/orders?status=done', 'Delivered'],
      ['/orders?status=cancelled', 'Cancelled'],
    ],
  },
  { label: 'Reviews', icon: 'star', to: '/reviews', badge: 'reviews' },
  {
    label: 'Settings', icon: 'settings', match: '/settings', badge: 'team',
    // Third value 'owner': hidden from staff, who can't change these.
    items: [
      ['/settings#shop', 'Shop details', 'owner'],
      ['/settings#categories', 'Categories', 'owner'],
      ['/settings#team', 'Team', 'owner'],
      ['/settings#account', 'Login details'],
      ['/settings#password', 'Password'],
      ['/settings#two-step', 'Two-step login'],
    ],
  },
];

function titleFor(path) {
  if (path === '/') return 'Dashboard';
  if (path === '/products/new') return 'Add product';
  if (/^\/products\/\d+/.test(path)) return 'Edit product';
  return NAV.find((n) => n.match && path.startsWith(n.match))?.label || 'Dashboard';
}

export default function Layout() {
  const { admin, newOrders, pricing, counts, refresh, pendingStaff } = useSession();
  const isOwner = (admin?.role || 'owner') === 'owner';
  const flash = useFlash();
  const navigate = useNavigate();
  const location = useLocation();
  const [prefs, setPrefs] = usePrefs();
  const [open, setOpen] = useState(false); // mobile drawer
  const [menu, setMenu] = useState(false); // account menu
  const [panel, setPanel] = useState(null); // 'settings' | 'help'
  const [groups, setGroups] = useState(() => ({ [NAV.find((n) => n.match && location.pathname.startsWith(n.match))?.label]: true }));
  const [scrolled, setScrolled] = useState(false);
  const [q, setQ] = useState('');
  const menuRef = useRef(null);

  // Close the drawer and menus on navigation; open the group of the page we're on; refresh badges.
  useEffect(() => {
    setOpen(false);
    setMenu(false);
    const current = NAV.find((n) => n.match && location.pathname.startsWith(n.match));
    if (current) setGroups((g) => ({ ...g, [current.label]: true }));
    refresh();
  }, [location.pathname]); // eslint-disable-line react-hooks/exhaustive-deps

  // Sub-menu links like /settings#password scroll to that section.
  useEffect(() => {
    if (!location.hash) return;
    const t = setTimeout(() => document.getElementById(location.hash.slice(1))?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 350);
    return () => clearTimeout(t);
  }, [location.hash, location.pathname]);

  useEffect(() => {
    if (!menu) return;
    const close = (e) => !menuRef.current?.contains(e.target) && setMenu(false);
    document.addEventListener('pointerdown', close);
    return () => document.removeEventListener('pointerdown', close);
  }, [menu]);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 400);
    const onKey = (e) => e.key === 'Escape' && (setPanel(null), setMenu(false), setOpen(false));
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('keydown', onKey);
    };
  }, []);

  async function logout() {
    try {
      await post('logout');
    } catch (err) {
      flash(err.message, 'error');
    }
    await refresh();
    navigate('/login');
  }

  function search(e) {
    e.preventDefault();
    navigate(q.trim() ? `/products?q=${encodeURIComponent(q.trim())}` : '/products');
  }

  /** Hamburger: drawer on phones, rail on desktop. */
  function toggleMenu() {
    if (window.matchMedia('(max-width: 991px)').matches) setOpen((o) => !o);
    else setPrefs({ rail: !prefs.rail });
  }

  // A sub-link is active when the path and the query/hash it names both match.
  const subActive = (to) => {
    const [path, rest = ''] = to.split(/(?=[?#])/);
    if (location.pathname !== path) return false;
    const here = location.search + location.hash;
    return rest ? here === rest : !location.search && !location.hash;
  };

  const priced = pricing?.priced ?? 0;
  const total = pricing?.total ?? 0;
  const initial = (admin?.username || '?').slice(0, 1);
  const badge = (n, cls) => n > 0 && <span className={`dot ${cls}`}>{n > 99 ? '99+' : n}</span>;

  return (
    <div className={`shell ${open ? 'nav-open' : ''} ${prefs.rail ? 'rail' : ''}`}>
      <aside className="sidebar" aria-label="Main menu">
        <Link className="sidebar-brand" to="/" title="City Florist">
          <span className="brand-mark"><BrandMark /></span>
          <span>
            <span className="brand-name">City Florist</span>
            <span className="brand-sub">Shop admin · Hubballi</span>
          </span>
        </Link>

        <nav className="side-nav">
          {NAV.map((n) =>
            n.items ? (
              <div key={n.label} className={`group ${groups[n.label] ? 'open' : ''} ${location.pathname.startsWith(n.match) ? 'current' : ''}`}>
                <button
                  type="button"
                  title={n.label}
                  aria-expanded={!!groups[n.label]}
                  onClick={() => (prefs.rail && !open ? navigate(n.items[0][0]) : setGroups((g) => ({ ...g, [n.label]: !g[n.label] })))}
                >
                  <Icon name={n.icon} />
                  <span className="label">{n.label}</span>
                  {n.badge === 'new' && newOrders > 0 && <span className="badge">{newOrders}</span>}
                  {n.badge === 'team' && isOwner && pendingStaff > 0 && <span className="badge" title="Staff waiting for approval">{pendingStaff}</span>}
                  <Icon name="chevron" size={16} className="chev" />
                </button>
                <ul className="sub">
                  {n.items.filter(([, , who]) => who !== 'owner' || isOwner).map(([to, label]) => (
                    <li key={to}>
                      <Link to={to} className={subActive(to) ? 'active' : ''}>{label}</Link>
                    </li>
                  ))}
                </ul>
              </div>
            ) : (
              <NavLink key={n.to} to={n.to} end={n.end} title={n.label}>
                <Icon name={n.icon} />
                <span className="label">{n.label}</span>
                {n.badge === 'reviews' && counts?.reviews > 0 && <span className="badge" title="Reviews waiting for you">{counts.reviews}</span>}
              </NavLink>
            )
          )}
          <div className="section">Website</div>
          <a href={SITE_URL + "/"} target="_blank" rel="noreferrer" title="View site"><Icon name="external" /> <span className="label">View site</span></a>
          <a href={SITE_URL + "/shop"} target="_blank" rel="noreferrer" title="Shop page"><Icon name="cart" /> <span className="label">Shop page</span></a>
        </nav>

        <div className="side-foot">
          <div className="side-user">
            <span className="avatar">{initial}</span>
            <div>
              <b>{admin?.username}</b>
              <span>{isOwner ? 'Shop owner' : 'Staff'}</span>
            </div>
          </div>
          {total > 0 && (
            <Link to={priced < total ? '/products?unpriced=1' : '/products'} className="side-progress" title="Products with a price">
              <div className="row"><span>★ Prices set</span><span>{priced}/{total}</span></div>
              <div className="bar-track"><div className="bar-fill" style={{ width: `${Math.round((priced / total) * 100)}%` }} /></div>
            </Link>
          )}
          <div className="side-copy">City Florist Admin · © {new Date().getFullYear()}<br />Made for the shop in Hubballi</div>
        </div>
      </aside>
      <div className="scrim" onClick={() => setOpen(false)} />

      <div className="main">
        <header className="header">
          <button className="icon-btn menu-btn" type="button" onClick={toggleMenu} aria-label="Toggle menu" aria-expanded={open || !prefs.rail}>
            <Icon name="menu" />
          </button>
          <h1>{titleFor(location.pathname)}</h1>

          <form className="search" onSubmit={search} role="search">
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search products…" aria-label="Search products" />
            <button type="submit" aria-label="Search"><Icon name="search" size={18} /></button>
          </form>

          <div className="header-actions">
            <Link className="icon-btn hide-sm" to="/products?featured=1" title="Featured products" aria-label={`Featured products: ${counts?.featured ?? 0}`}>
              <Icon name="star" />{badge(counts?.featured, 'purple')}
            </Link>
            <Link className="icon-btn" to="/orders?status=new" title="New orders" aria-label={`New orders: ${newOrders}`}>
              <Icon name="bell" />{badge(newOrders, 'pink')}
            </Link>
            <Link className="icon-btn hide-sm" to="/orders?status=contacted" title="Contacted, waiting for delivery" aria-label={`Contacted orders: ${counts?.contacted ?? 0}`}>
              <Icon name="chat" />{badge(counts?.contacted, 'yellow')}
            </Link>
            <Link className="icon-btn hide-sm" to="/offers" title="Running offers" aria-label={`Running offers: ${counts?.offers ?? 0}`}>
              <Icon name="gift" />{badge(counts?.offers, 'green')}
            </Link>
            <div className="user-menu" ref={menuRef}>
              <button type="button" onClick={() => setMenu((m) => !m)} aria-haspopup="menu" aria-expanded={menu} aria-label="Account">
                <span className="avatar">{initial}</span>
              </button>
              {menu && (
                <div className="menu" role="menu">
                  <div className="who"><b>{admin?.username}</b><div className="small muted">{isOwner ? 'Shop owner' : 'Staff'}</div></div>
                  {isOwner && <Link to="/settings#shop" role="menuitem"><Icon name="settings" size={18} /> Shop settings</Link>}
                  <Link to="/settings#password" role="menuitem"><Icon name="lock" size={18} /> Change password</Link>
                  <button type="button" role="menuitem" onClick={() => setPanel('settings')}><Icon name="sliders" size={18} /> Appearance</button>
                  <button type="button" role="menuitem" onClick={logout}><Icon name="logout" size={18} /> Log out</button>
                </div>
              )}
            </div>
          </div>
        </header>

        <main className="wrap">
          <Outlet />
        </main>
      </div>

      {/* Right edge: appearance panel and a one-tap light/dark switch. */}
      <div className="fab-right">
        <button type="button" onClick={() => setPanel('settings')} title="Appearance" aria-label="Appearance"><Icon name="settings" /></button>
        <button
          type="button"
          onClick={() => setPrefs({ theme: document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark' })}
          title="Light / dark"
          aria-label="Switch light or dark mode"
        >
          <Icon name={prefs.theme === 'dark' ? 'sun' : 'moon'} />
        </button>
      </div>

      {/* Bottom left: help and the shop. */}
      <div className="fab-left">
        <button type="button" className="help" onClick={() => setPanel('help')} title="Help" aria-label="Help"><Icon name="headset" size={24} /></button>
        <a className="shop" href={SITE_URL + "/shop"} target="_blank" rel="noreferrer" title="Open the shop" aria-label="Open the shop"><Icon name="cart" size={24} /></a>
      </div>

      {scrolled && (
        <button type="button" className="to-top" onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })} aria-label="Back to top">
          <Icon name="up" />
        </button>
      )}

      {panel && (
        <>
          <div className="panel-scrim" onClick={() => setPanel(null)} />
          <aside className={`panel ${panel === 'help' ? 'left' : ''}`} role="dialog" aria-label={panel === 'help' ? 'Help' : 'Appearance'}>
            <div className="panel-head">
              <h2>{panel === 'help' ? 'Quick help' : 'Appearance'}</h2>
              <button type="button" className="icon-btn" onClick={() => setPanel(null)} aria-label="Close"><Icon name="close" /></button>
            </div>
            {panel === 'settings' ? (
              <>
                <h3>Theme</h3>
                <div className="choice">
                  {[['light', 'Light', 'sun'], ['dark', 'Dark', 'moon'], ['system', 'Auto', 'monitor']].map(([v, label, icon]) => (
                    <button key={v} type="button" className={prefs.theme === v ? 'active' : ''} onClick={() => setPrefs({ theme: v })}>
                      <Icon name={icon} /> {label}
                    </button>
                  ))}
                </div>
                <h3>Sidebar</h3>
                <div className="choice two-up">
                  <button type="button" className={!prefs.rail ? 'active' : ''} onClick={() => setPrefs({ rail: false })}><Icon name="menu" /> Full</button>
                  <button type="button" className={prefs.rail ? 'active' : ''} onClick={() => setPrefs({ rail: true })}><Icon name="rail" /> Icons only</button>
                </div>
                <p className="small muted" style={{ marginTop: 18 }}>Saved on this device only.</p>
              </>
            ) : (
              <ul className="tips">
                <li><b>New order?</b> The bell shows how many. Open it, tap <b>WhatsApp</b> to reply, then set the status to Contacted.</li>
                <li><b>Prices:</b> Products → Without price. Type prices in the list and press <b>Save all changes</b>.</li>
                <li><b>Today’s discount:</b> Daily Offers → New offer → <b>Today only</b>. It switches off by itself.</li>
                <li><b>Ready to go:</b> tick it on the 4–6 bouquets made up in the shop today.</li>
                <li><b>Photos:</b> open a product and tap <b>Tap to add photos</b>. The first photo is the main one.</li>
                <li><b>Monthly target:</b> on the Dashboard, tap <b>Edit</b> on the target card.</li>
              </ul>
            )}
          </aside>
        </>
      )}
    </div>
  );
}

/** Centred card used by the login, install and loading screens. */
/** Centred card for login, install and loading. `art` adds the illustrations either side (wide screens only). */
export function Screen({ children, art = false }) {
  return (
    <div className={`auth-screen${art ? ' with-art' : ''}`}>
      {art && <FloristArt className="auth-art left" />}
      <div className="auth-card">
        <span className="brand-mark"><BrandMark size={28} /></span>
        <h1>City Florist</h1>
        {children}
      </div>
      {art && <OwnerArt className="auth-art right" />}
    </div>
  );
}

export function Loading({ error, retry }) {
  if (error) {
    return (
      <div className="flash error">
        {error.message} {retry && <button className="btn small ghost" type="button" onClick={retry}>Try again</button>}
      </div>
    );
  }
  return <div className="loading">Loading…</div>;
}
