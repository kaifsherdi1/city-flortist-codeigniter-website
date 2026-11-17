import { useEffect, useState } from 'react';
import { formatDate, put, upload } from '../api.js';
import { useFlash, useLoad, useReport, useSession } from '../session.jsx';
import { Loading } from '../components/Layout.jsx';
import PasswordInput from '../components/PasswordInput.jsx';
import TwoFactorCard from '../components/TwoFactorCard.jsx';
import TeamCard from '../components/TeamCard.jsx';

const FIELDS = [
  ['whatsapp_number', 'WhatsApp number for orders', 'Country code + number, e.g. 919876543210'],
  ['shop_phone', 'Shop phone (for Call button)', 'e.g. +91 98765 43210'],
  ['email', 'Email', ''],
  ['address', 'Shop address', ''],
  ['hours', 'Opening hours', 'e.g. Open daily · 8:00 AM – 10:00 PM'],
  ['map_embed', 'Google Maps embed link', 'Google Maps → Share → Embed a map → paste the code or just the src="…" link'],
  ['map_link', 'Google Maps link (for “Get directions”)', 'Google Maps → your shop → Share → Copy link'],
  ['google_review_link', 'Google review link', 'Google Business Profile → Ask for reviews → copy the link. Used by “Review us on Google” and “Ask for review”'],
  ['google_rating', 'Google rating (e.g. 4.7)', 'Copy from your Google listing now and then. Empty hides it'],
  ['google_review_count', 'Number of Google reviews', 'e.g. 40'],
  ['instagram', 'Instagram link', 'https://instagram.com/…'],
  ['facebook', 'Facebook link', ''],
  ['hero_title', 'Home page headline', ''],
  ['hero_subtitle', 'Home page intro line', ''],
];
const TEXTAREAS = ['hero_subtitle', 'address'];
const FULL = ['map_embed', 'hero_subtitle', 'address'];

export default function Settings() {
  const [data, reload, error] = useLoad('settings');
  const { admin } = useSession();
  // Staff only see their own login details, password and two-step login; the shop itself is the owner's.
  const owner = (admin?.role || 'owner') === 'owner';
  if (!data) return <Loading error={error} retry={reload} />;
  return (
    <>
      <div className="page-head"><h1>Settings</h1></div>
      {owner && <ShopForm initial={data.settings} />}
      {owner && <CategoriesForm initial={data.categories} />}
      {owner && <TeamCard />}
      {data.account && <AccountForm initial={data.account} />}
      <PasswordForm />
      {data.account && <TwoFactorCard initial={data.account} />}
    </>
  );
}

/** Username, email and phone the owner can log in with. Email is also used for Google sign-in and password resets. */
function AccountForm({ initial }) {
  const flash = useFlash();
  const { refresh, previousLogin } = useSession();
  const [busy, setBusy] = useState(false);

  async function save(e) {
    e.preventDefault();
    const formEl = e.currentTarget;
    setBusy(true);
    try {
      flash((await put('settings/account', Object.fromEntries(new FormData(formEl)))).message);
      formEl.elements.current_password.value = '';
      refresh();
    } catch (err) {
      flash(err.message, 'error');
    }
    setBusy(false);
  }

  return (
    <form className="card narrow" id="account" onSubmit={save}>
      <h2>Login details</h2>
      <p className="muted">You can log in with any of these. The email is also used for “Continue with Google” and for password-reset links.</p>
      {previousLogin && (
        <div className="flash info">
          Login before this one: <b>{formatDate(previousLogin.at)}</b> from <b>{previousLogin.ip}</b>. Not you? Change your password now.
        </div>
      )}
      <label>Username <input name="username" defaultValue={initial.username} required minLength="3" autoComplete="username" /></label>
      <label>Email <input name="email" type="email" defaultValue={initial.email || ''} placeholder="owner@example.com" autoComplete="email" /></label>
      <label>Mobile number <input name="phone" type="tel" defaultValue={initial.phone ? '+' + initial.phone : ''} placeholder="+91 98765 43210" autoComplete="tel" /></label>
      <PasswordInput label="Current password (to confirm)" name="current_password" autoComplete="current-password" />
      <div className="form-actions"><button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save login details'}</button></div>
    </form>
  );
}

function ShopForm({ initial }) {
  const flash = useFlash();
  const [values, setValues] = useState(initial);
  const [busy, setBusy] = useState(false);

  async function save(e) {
    e.preventDefault();
    setBusy(true);
    try {
      const res = await put('settings/shop', values);
      setValues(res.settings);
      flash(res.message);
    } catch (err) {
      flash(err.message, 'error');
    }
    setBusy(false);
  }

  return (
    <form className="card" id="shop" onSubmit={save}>
      <h2>Shop details</h2>
      <div className="grid-2">
        {FIELDS.map(([key, label, hint]) => (
          <label key={key} className={FULL.includes(key) ? 'full' : ''}>
            {label}
            {TEXTAREAS.includes(key) ? (
              <textarea rows="2" value={values[key] ?? ''} onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.value }))} />
            ) : (
              <input
                value={values[key] ?? ''}
                onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.value }))}
                {...(key === 'whatsapp_number' ? { required: true, inputMode: 'numeric' } : {})}
              />
            )}
            {hint && <small className="muted">{hint}</small>}
          </label>
        ))}
      </div>
      <div className="form-actions"><button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save shop details'}</button></div>
    </form>
  );
}

function CategoriesForm({ initial }) {
  const report = useReport();
  const flash = useFlash();
  const [cats, setCats] = useState(initial);
  const [covers, setCovers] = useState({});
  const [busy, setBusy] = useState(false);
  const [formKey, setFormKey] = useState(0); // remounts the file inputs after saving

  useEffect(() => setCats(initial), [initial]);
  const edit = (id, key, value) => setCats((list) => list.map((c) => (c.id === id ? { ...c, [key]: value } : c)));

  async function save(e) {
    e.preventDefault();
    const form = new FormData();
    cats.forEach((c) => {
      form.append(`cat[${c.id}][name]`, c.name);
      form.append(`cat[${c.id}][tagline]`, c.tagline);
      form.append(`cat[${c.id}][sort]`, c.sort);
    });
    Object.entries(covers).forEach(([id, file]) => file && form.append(`cover[${id}]`, file));
    setBusy(true);
    try {
      const res = await upload('settings/categories', form);
      report(res);
      setCats(res.categories);
      setCovers({});
      setFormKey((k) => k + 1);
    } catch (err) {
      flash(err.message, 'error');
    }
    setBusy(false);
  }

  return (
    <form className="card" id="categories" onSubmit={save} key={formKey}>
      <h2>Categories</h2>
      <p className="muted small">If no cover photo is uploaded, the first product photo of that category is used.</p>
      {cats.map((c) => (
        <div className="cat-row" key={c.id}>
          {c.cover ? <img src={c.cover} alt="" /> : <span className="noimg">Auto</span>}
          <input value={c.name} onChange={(e) => edit(c.id, 'name', e.target.value)} aria-label="Name" />
          <input value={c.tagline} onChange={(e) => edit(c.id, 'tagline', e.target.value)} aria-label="Tagline" className="grow" />
          <input type="number" value={c.sort} onChange={(e) => edit(c.id, 'sort', e.target.value)} aria-label="Order" className="tiny" />
          <input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Cover" onChange={(e) => setCovers((v) => ({ ...v, [c.id]: e.target.files[0] }))} />
        </div>
      ))}
      <div className="form-actions"><button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save categories'}</button></div>
    </form>
  );
}

function PasswordForm() {
  const flash = useFlash();
  const [busy, setBusy] = useState(false);

  async function save(e) {
    e.preventDefault();
    const formEl = e.currentTarget;
    const form = new FormData(formEl);
    setBusy(true);
    try {
      flash((await put('settings/password', Object.fromEntries(form))).message);
      formEl.reset();
    } catch (err) {
      flash(err.message, 'error');
    }
    setBusy(false);
  }

  return (
    <form className="card narrow" id="password" onSubmit={save}>
      <h2>Change password</h2>
      <PasswordInput label="Current password" name="current_password" autoComplete="current-password" />
      <PasswordInput label="New password" name="new_password" minLength="8" autoComplete="new-password" />
      <PasswordInput label="Repeat new password" name="confirm_password" minLength="8" autoComplete="new-password" />
      <div className="form-actions"><button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Change password'}</button></div>
    </form>
  );
}
