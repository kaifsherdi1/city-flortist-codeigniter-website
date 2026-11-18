import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { post, put } from '../api.js';
import { useSession } from '../session.jsx';
import { Screen } from '../components/Layout.jsx';
import Icon from '../components/Icon.jsx';
import PasswordInput from '../components/PasswordInput.jsx';
import { API_URL } from '../urls.js';

// All three go to the same `login` field; the server works out which one it is.
const METHODS = {
  email: { tab: 'Email', icon: 'mail', label: 'Email address', type: 'email', placeholder: 'owner@example.com', autoComplete: 'email', inputMode: 'email' },
  phone: { tab: 'Phone', icon: 'phone', label: 'Mobile number', type: 'tel', placeholder: '98765 43210', autoComplete: 'tel-national', inputMode: 'tel' },
  username: { tab: 'Username', icon: 'user', label: 'Username', type: 'text', placeholder: 'admin', autoComplete: 'username', inputMode: 'text' },
};
const METHOD_KEY = 'cf-login-method';

function savedMethod() {
  try {
    const m = localStorage.getItem(METHOD_KEY);
    return METHODS[m] ? m : 'username';
  } catch {
    return 'username';
  }
}

export default function Login() {
  const [params] = useSearchParams();
  const { twoFactorRequired } = useSession();
  const [view, setView] = useState('login');
  let body;
  if (twoFactorRequired) body = <TwoFactorForm />;
  else if (view === 'forgot') body = <ForgotForm onBack={() => setView('login')} />;
  else body = <LoginForm initialError={params.get('error')} notice={params.get('notice')} onForgot={() => setView('forgot')} />;
  return <Screen art>{body}</Screen>;
}

/** Second step: the password was right, now the 6-digit code from the authenticator app (or a backup code). */
function TwoFactorForm() {
  const { refresh } = useSession();
  const [backup, setBackup] = useState(false);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await post('login/two-factor', { code: new FormData(e.currentTarget).get('code') });
      await refresh();
    } catch (err) {
      setError(err.message);
      setBusy(false);
      if (err.status === 419) refresh(); // timed out: back to the password step
    }
  }

  async function startOver() {
    await post('logout').catch(() => {});
    refresh();
  }

  return (
    <form onSubmit={submit} className="login-form" autoComplete="off">
      <p className="muted">Two-step login</p>
      <div className="flash info">
        {backup ? 'Type one of the backup codes you saved when you turned on two-step login. Each code works once.' : 'Open your authenticator app and type the 6-digit code for City Florist.'}
      </div>
      {error && <div className="flash error" role="alert">{error}</div>}
      <label>
        {backup ? 'Backup code' : '6-digit code'}
        <input
          key={backup ? 'b' : 'c'}
          name="code"
          className="code-input"
          inputMode={backup ? 'text' : 'numeric'}
          autoComplete="one-time-code"
          pattern={backup ? undefined : '[0-9 ]{6,7}'}
          maxLength={backup ? 11 : 7}
          placeholder={backup ? 'XXXXX-XXXXX' : '123 456'}
          required
          autoFocus
        />
      </label>
      <button className="btn" type="submit" disabled={busy}>{busy ? 'Checking…' : 'Verify and log in'}</button>
      <div className="login-row center">
        <button type="button" className="link-btn" onClick={() => { setBackup((b) => !b); setError(null); }}>
          {backup ? 'Use the authenticator app instead' : 'Lost your phone? Use a backup code'}
        </button>
      </div>
      <button type="button" className="link-btn back" onClick={startOver}>← Start again</button>
    </form>
  );
}

function LoginForm({ initialError, notice, onForgot }) {
  const { refresh, loginOptions = {} } = useSession();
  const [method, setMethod] = useState(savedMethod);
  const [error, setError] = useState(initialError);
  const [busy, setBusy] = useState(false);
  const m = METHODS[method];

  function pick(key) {
    setMethod(key);
    setError(null);
    try {
      localStorage.setItem(METHOD_KEY, key);
    } catch {
      /* private window: just don't remember it */
    }
  }

  async function submit(e) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    setBusy(true);
    setError(null);
    try {
      await post('login', { login: form.get('login'), password: form.get('password'), remember: form.get('remember') === 'on' });
      await refresh();
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} autoComplete="on" className="login-form">
      <p className="muted">Dashboard login</p>
      {notice && !error && <div className="flash success" role="status">{notice}</div>}
      {error && <div className="flash error" role="alert">{error}</div>}

      <div className="seg" role="tablist" aria-label="Log in with">
        {Object.entries(METHODS).map(([key, v]) => (
          <button key={key} type="button" role="tab" aria-selected={method === key} className={method === key ? 'on' : ''} onClick={() => pick(key)}>
            <Icon name={v.icon} size={16} /> {v.tab}
          </button>
        ))}
      </div>

      <label>
        {m.label}
        <span className={`input-wrap${method === 'phone' ? ' has-prefix' : ''}`}>
          {method === 'phone' && <span className="input-prefix">+91</span>}
          <input
            key={method}
            name="login"
            type={m.type}
            inputMode={m.inputMode}
            placeholder={m.placeholder}
            autoComplete={m.autoComplete}
            required
            autoFocus
          />
        </span>
      </label>
      <PasswordInput name="password" autoComplete="current-password" placeholder="Your password" />

      <div className="login-row">
        <label className="check"><input type="checkbox" name="remember" defaultChecked /> Remember me</label>
        <button type="button" className="link-btn" onClick={onForgot}>Forgot password?</button>
      </div>

      <button className="btn" type="submit" disabled={busy}>{busy ? 'Logging in…' : 'Log in'}</button>

      <div className="or"><span>or</span></div>
      {loginOptions.google ? (
        <a className="btn ghost google" href={API_URL + "/api/admin/oauth/google"}><GoogleG /> Continue with Google</a>
      ) : (
        <>
          <button type="button" className="btn ghost google" disabled title="Google sign-in isn't set up on this server yet"><GoogleG /> Continue with Google</button>
          <p className="hint">Google sign-in isn’t set up on this server yet.</p>
        </>
      )}
      <p className="hint">Email, phone and Google work once they’re saved in <b>Settings → Login details</b>.</p>
      <p className="hint">New staff member? <Link to="/register">Create a staff account</Link></p>
    </form>
  );
}

function ForgotForm({ onBack }) {
  const { loginOptions = {} } = useSession();
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(null);
  const [error, setError] = useState(null);

  async function submit(e) {
    e.preventDefault();
    const email = new FormData(e.currentTarget).get('email');
    setBusy(true);
    setError(null);
    try {
      setDone((await post('password/forgot', { email })).message);
    } catch (err) {
      setError(err.message);
    }
    setBusy(false);
  }

  return (
    <form onSubmit={submit} className="login-form">
      <p className="muted">Reset your password</p>
      {!loginOptions.reset_by_email && (
        <div className="flash info">Reset emails aren’t set up on this server yet, so the link may not arrive. Ask whoever manages the website to add the mail settings.</div>
      )}
      {error && <div className="flash error" role="alert">{error}</div>}
      {done ? (
        <div className="flash success">{done}</div>
      ) : (
        <>
          <label>
            Email on your account
            <input name="email" type="email" required autoFocus autoComplete="email" placeholder="owner@example.com" />
          </label>
          <button className="btn" type="submit" disabled={busy}>{busy ? 'Sending…' : 'Send reset link'}</button>
        </>
      )}
      <button type="button" className="link-btn back" onClick={onBack}>← Back to log in</button>
    </form>
  );
}

/**
 * /admin/register: a staff member asks for a dashboard account. It stays locked until the owner approves it in
 * Settings → Team, so signing up here gives nobody access on its own.
 */
export function StaffRegister() {
  const [params] = useSearchParams();
  const { loginOptions = {} } = useSession();
  const [form, setForm] = useState({ username: '', email: '', phone: '', password: '', password_confirmation: '', website: '' });
  const [error, setError] = useState(params.get('error') || '');
  const [done, setDone] = useState('');
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function submit(e) {
    e.preventDefault();
    if (form.password !== form.password_confirmation) return setError('The two passwords don’t match.');
    setBusy(true);
    setError('');
    try {
      setDone((await post('register', form)).message);
    } catch (err) {
      setError(err.message);
    }
    setBusy(false);
  }

  return (
    <Screen art>
      <form onSubmit={submit} className="login-form" autoComplete="on">
        <p className="muted">Create a staff account</p>
        {done ? (
          <>
            <div className="flash success" role="status">{done}</div>
            <Link className="btn" to="/login">Back to log in</Link>
          </>
        ) : (
          <>
            <div className="flash info">Your account stays locked until the shop owner approves it.</div>
            {error && <div className="flash error" role="alert">{error}</div>}
            <label>User name <input name="username" value={form.username} onChange={set('username')} required minLength="3" maxLength="40" pattern="[A-Za-z0-9._\-]+" title="Letters, numbers, dots, dashes or underscores" autoComplete="username" autoFocus /></label>
            <label>Email <input type="email" name="email" value={form.email} onChange={set('email')} required autoComplete="email" /></label>
            <label>
              Mobile number
              <span className="input-wrap has-prefix">
                <span className="input-prefix">+91</span>
                <input type="tel" name="phone" value={form.phone} onChange={set('phone')} required inputMode="tel" autoComplete="tel-national" placeholder="98765 43210" />
              </span>
            </label>
            <PasswordInput label="Password" name="password" value={form.password} onChange={set('password')} minLength="8" autoComplete="new-password" placeholder="At least 8 characters, letters and a number" />
            <PasswordInput label="Confirm password" name="password_confirmation" value={form.password_confirmation} onChange={set('password_confirmation')} minLength="8" autoComplete="new-password" />
            <input className="hp" tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} aria-hidden="true" style={{ position: 'absolute', left: -9999 }} />
            <button className="btn" type="submit" disabled={busy}>{busy ? 'Creating…' : 'Create staff account'}</button>
            <div className="or"><span>or</span></div>
            {loginOptions.google ? (
              <a className="btn ghost google" href={API_URL + "/api/admin/oauth/google?intent=register"}><GoogleG /> Sign up with Google</a>
            ) : (
              <button type="button" className="btn ghost google" disabled title="Google sign-in isn't set up on this server yet"><GoogleG /> Sign up with Google</button>
            )}
          </>
        )}
        <Link className="link-btn back" to="/login">← Back to log in</Link>
      </form>
    </Screen>
  );
}

/** Reached from the link in the reset email: /admin/reset-password?token=…&email=… */
export function ResetPassword() {
  const [params] = useSearchParams();
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(null);
  const [error, setError] = useState(null);
  const token = params.get('token') || '';
  const email = params.get('email') || '';

  async function submit(e) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    setBusy(true);
    setError(null);
    try {
      setDone((await post('password/reset', { token, email, password: form.get('password'), password_confirmation: form.get('password_confirmation') })).message);
    } catch (err) {
      setError(err.message);
    }
    setBusy(false);
  }

  return (
    <Screen art>
      <form onSubmit={submit} className="login-form">
        <p className="muted">Choose a new password</p>
        {!token && <div className="flash error">This link is incomplete. Open the link from the reset email again.</div>}
        {error && <div className="flash error" role="alert">{error}</div>}
        {done ? (
          <>
            <div className="flash success">{done}</div>
            <Link className="btn" to="/login">Go to log in</Link>
          </>
        ) : (
          <>
            <label>Email <input value={email} readOnly /></label>
            <PasswordInput label="New password" name="password" minLength="8" autoComplete="new-password" autoFocus placeholder="At least 8 characters" />
            <PasswordInput label="Repeat new password" name="password_confirmation" minLength="8" autoComplete="new-password" />
            <button className="btn" type="submit" disabled={busy || !token}>{busy ? 'Saving…' : 'Save new password'}</button>
            <Link className="link-btn back" to="/login">← Back to log in</Link>
          </>
        )}
      </form>
    </Screen>
  );
}

/** Shown straight after logging in with the starting password (e.g. ChangeMe@123). Nothing else opens until it's changed. */
export function ChoosePassword() {
  const { refresh } = useSession();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function submit(e) {
    e.preventDefault();
    const form = Object.fromEntries(new FormData(e.currentTarget));
    setBusy(true);
    setError(null);
    try {
      await put('settings/password', form);
      await refresh();
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  async function logout() {
    await post('logout').catch(() => {});
    refresh();
  }

  return (
    <Screen art>
      <form onSubmit={submit} className="login-form">
        <p className="muted">Choose your own password</p>
        <div className="flash info">You logged in with the starting password, which other people may know. Pick a new one to open the dashboard.</div>
        {error && <div className="flash error" role="alert">{error}</div>}
        <PasswordInput label="Starting password" name="current_password" autoComplete="current-password" />
        <PasswordInput label="New password" name="new_password" minLength="8" autoComplete="new-password" autoFocus placeholder="At least 8 characters" />
        <PasswordInput label="Repeat new password" name="confirm_password" minLength="8" autoComplete="new-password" />
        <button className="btn" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save and open dashboard'}</button>
        <button type="button" className="link-btn back" onClick={logout}>Log out</button>
      </form>
    </Screen>
  );
}

function GoogleG() {
  return (
    <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
      <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z" />
      <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
      <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z" />
      <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z" />
    </svg>
  );
}
