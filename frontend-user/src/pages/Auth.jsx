import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { accountRequest, useAccount } from '../lib/account.jsx';
import { usePageMeta } from '../lib/seo.js';
import PasswordField, { GoogleG } from '../components/PasswordField.jsx';

// The same rules the server checks (backend/app/Support/Signup.php), so mistakes show while typing.
const RULES = {
  username: (v) => (/^[A-Za-z0-9._-]{3,40}$/.test(v) ? '' : '3–40 letters, numbers, dots, dashes or underscores, no spaces'),
  email: (v) => (/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? '' : 'Enter a valid email address'),
  phone: (v) => (/^(\+?91|0)?[6-9]\d{9}$/.test(v.replace(/[\s-]/g, '')) ? '' : 'Enter a valid 10-digit mobile number'),
  password: (v) => (v.length >= 8 && /[A-Za-z]/.test(v) && /\d/.test(v) ? '' : 'At least 8 characters, with letters and a number'),
};

function GoogleButton({ enabled, label }) {
  return enabled ? (
    <a className="btn btn-google" href="/api/account/oauth/google" data-cursor="Google">
      <GoogleG /> {label}
    </a>
  ) : (
    <button type="button" className="btn btn-google" disabled title="Google sign-in isn’t set up on this website yet">
      <GoogleG /> {label}
    </button>
  );
}

/** Already logged in? The account page is the right place. */
function useRedirectIfLoggedIn() {
  const { customer, ready } = useAccount();
  const navigate = useNavigate();
  useEffect(() => {
    if (ready && customer) navigate('/account', { replace: true });
  }, [ready, customer, navigate]);
}

export function Register() {
  usePageMeta({ title: 'Create an account', description: 'Create a City Florist account to see your orders and order faster.' });
  useRedirectIfLoggedIn();
  const { google, setCustomer } = useAccount();
  const navigate = useNavigate();
  const [form, setForm] = useState({ username: '', email: '', phone: '', password: '', password_confirmation: '', website: '' });
  const [touched, setTouched] = useState({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const errors = {
    username: RULES.username(form.username.trim()),
    email: RULES.email(form.email.trim()),
    phone: RULES.phone(form.phone),
    password: RULES.password(form.password),
    password_confirmation: form.password_confirmation === form.password && form.password ? '' : 'The two passwords don’t match',
  };
  const show = (k) => (touched[k] ? errors[k] : '');
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const blur = (k) => () => setTouched((t) => ({ ...t, [k]: true }));

  async function submit(e) {
    e.preventDefault();
    setTouched({ username: true, email: true, phone: true, password: true, password_confirmation: true });
    if (Object.values(errors).some(Boolean)) return;
    setBusy(true);
    setError('');
    try {
      const res = await accountRequest('register', { method: 'POST', body: { ...form, username: form.username.trim(), email: form.email.trim(), remember: true } });
      setCustomer(res.customer);
      navigate('/account', { replace: true, state: { welcome: res.message } });
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  return (
    <section className="auth container">
      <div className="auth-box">
        <span className="eyebrow">City Florist account</span>
        <h1 className="display">Create an account</h1>
        <p className="muted">See your orders in one place, and your details are filled in for you next time.</p>

        <GoogleButton enabled={google} label="Sign up with Google" />
        <div className="auth-or"><span>or fill in the form</span></div>

        <form onSubmit={submit} noValidate>
          <label className={`field ${show('username') ? 'has-error' : ''}`}>
            <span>User name</span>
            <input value={form.username} onChange={set('username')} onBlur={blur('username')} autoComplete="username" maxLength={40} placeholder="e.g. priya_patil" />
            {show('username') && <em>{show('username')}</em>}
          </label>
          <label className={`field ${show('email') ? 'has-error' : ''}`}>
            <span>Email</span>
            <input type="email" value={form.email} onChange={set('email')} onBlur={blur('email')} autoComplete="email" inputMode="email" placeholder="you@example.com" />
            {show('email') && <em>{show('email')}</em>}
          </label>
          <label className={`field ${show('phone') ? 'has-error' : ''}`}>
            <span>Mobile number</span>
            <div className="phone-input">
              <b>+91</b>
              <input value={form.phone} onChange={set('phone')} onBlur={blur('phone')} inputMode="tel" autoComplete="tel-national" placeholder="98765 43210" />
            </div>
            {show('phone') && <em>{show('phone')}</em>}
          </label>
          <PasswordField label="Password" value={form.password} onChange={set('password')} onBlur={blur('password')} autoComplete="new-password" error={show('password')} hint="At least 8 characters, with letters and a number" />
          <PasswordField label="Confirm password" value={form.password_confirmation} onChange={set('password_confirmation')} onBlur={blur('password_confirmation')} autoComplete="new-password" error={show('password_confirmation')} />
          <input className="hp" tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} aria-hidden="true" />

          {error && <div className="form-error" role="alert">{error}</div>}
          <button className="btn btn-gold auth-submit" type="submit" disabled={busy}>{busy ? 'Creating your account…' : 'Create account'}</button>
          <p className="auth-fine">
            By creating an account you agree to our <Link to="/terms">Terms</Link> and <Link to="/privacy">Privacy Policy</Link>.
          </p>
        </form>
        <p className="auth-switch">Already have an account? <Link to="/login">Log in</Link></p>
      </div>
    </section>
  );
}

export function Login() {
  usePageMeta({ title: 'Log in', description: 'Log in to your City Florist account.' });
  useRedirectIfLoggedIn();
  const [params] = useSearchParams();
  const { google, setCustomer } = useAccount();
  const navigate = useNavigate();
  const [form, setForm] = useState({ login: '', password: '', remember: true });
  const [error, setError] = useState(params.get('error') || '');
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: k === 'remember' ? e.target.checked : e.target.value }));

  async function submit(e) {
    e.preventDefault();
    if (!form.login.trim() || !form.password) return setError('Enter your username, email or mobile number and your password.');
    setBusy(true);
    setError('');
    try {
      const res = await accountRequest('login', { method: 'POST', body: form });
      setCustomer(res.customer);
      navigate('/account', { replace: true });
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  return (
    <section className="auth container">
      <div className="auth-box">
        <span className="eyebrow">City Florist account</span>
        <h1 className="display">Log in</h1>

        <GoogleButton enabled={google} label="Continue with Google" />
        <div className="auth-or"><span>or</span></div>

        <form onSubmit={submit} noValidate>
          <label className="field">
            <span>User name, email or mobile number</span>
            <input value={form.login} onChange={set('login')} autoComplete="username" autoFocus />
          </label>
          <PasswordField label="Password" value={form.password} onChange={set('password')} autoComplete="current-password" />
          <label className="auth-check">
            <input type="checkbox" checked={form.remember} onChange={set('remember')} /> Keep me logged in on this device
          </label>
          {error && <div className="form-error" role="alert">{error}</div>}
          <button className="btn btn-gold auth-submit" type="submit" disabled={busy}>{busy ? 'Logging in…' : 'Log in'}</button>
        </form>
        <p className="auth-switch">New here? <Link to="/register">Create an account</Link></p>
        <p className="auth-fine">Forgot your password? Message us on WhatsApp and we’ll help you.</p>
      </div>
    </section>
  );
}

const STATUS = { new: 'Received', contacted: 'Confirmed', done: 'Delivered', cancelled: 'Cancelled' };

export function Account() {
  usePageMeta({ title: 'My account' });
  const { customer, ready, logout } = useAccount();
  const navigate = useNavigate();
  const [orders, setOrders] = useState(null);
  const welcome = useLocation().state?.welcome;

  useEffect(() => {
    if (ready && !customer) navigate('/login', { replace: true });
  }, [ready, customer, navigate]);

  useEffect(() => {
    if (!customer) return;
    accountRequest('orders').then((d) => setOrders(d.orders)).catch(() => setOrders([]));
  }, [customer]);

  if (!customer) return <section className="auth container"><p className="muted">Loading…</p></section>;

  return (
    <section className="account container">
      {welcome && <div className="review-thanks" role="status">{welcome}</div>}
      <div className="account-head">
        <div>
          <span className="eyebrow">My account</span>
          <h1 className="display">Hello, {customer.username}</h1>
        </div>
        <button type="button" className="btn btn-outline" onClick={async () => { await logout(); navigate('/'); }}>Log out</button>
      </div>

      <dl className="account-details">
        <div><dt>User name</dt><dd>{customer.username}</dd></div>
        <div><dt>Email</dt><dd>{customer.email}</dd></div>
        <div><dt>Mobile</dt><dd>{customer.phone ? '+' + customer.phone : <span className="muted">Not added yet. It’s asked for when you order.</span>}</dd></div>
        <div><dt>Signed in with</dt><dd>{customer.google ? 'Google' : 'Email and password'}</dd></div>
      </dl>

      <h2 className="account-title">My orders</h2>
      {orders === null ? (
        <p className="muted">Loading…</p>
      ) : orders.length ? (
        <ul className="account-orders">
          {orders.map((o) => (
            <li key={o.id}>
              <div>
                <b>#{o.id} · {o.product_name}</b>
                <span className="muted">{new Date(o.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
              </div>
              <div>
                <span>{o.price_at_order > 0 ? '₹' + Math.round(o.price_at_order).toLocaleString('en-IN') : 'Price on request'}</span>
                <span className={`order-pill ${o.status}`}>{STATUS[o.status] || o.status}</span>
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <p className="muted">No orders yet. <Link to="/shop">Have a look at the shop</Link>. Orders you place while logged in appear here.</p>
      )}
    </section>
  );
}
