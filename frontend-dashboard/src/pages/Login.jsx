import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { post, put } from '../api.js';
import { useSession } from '../session.jsx';
import { Screen } from '../components/Layout.jsx';
import PasswordInput from '../components/PasswordInput.jsx';

export default function Login() {
  const [params] = useSearchParams();
  const { twoFactorRequired } = useSession();
  const body = twoFactorRequired ? <TwoFactorForm /> : <LoginForm initialError={params.get('error')} notice={params.get('notice')} />;
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

function LoginForm({ initialError, notice }) {
  const { refresh } = useSession();
  const [error, setError] = useState(initialError);
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    setBusy(true);
    setError(null);
    try {
      await post('login', { login: form.get('login'), password: form.get('password'), remember: false });
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

      <label>
        Username
        <input name="login" type="text" placeholder="Your username" autoComplete="username" autoCapitalize="none" spellCheck="false" required autoFocus />
      </label>
      <PasswordInput name="password" autoComplete="current-password" placeholder="Your password" />


      <button className="btn" type="submit" disabled={busy}>{busy ? 'Logging in…' : 'Log in'}</button>
    </form>
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
