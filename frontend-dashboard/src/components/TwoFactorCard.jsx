import { useEffect, useState } from 'react';
import { post } from '../api.js';
import { useFlash, useSession } from '../session.jsx';
import PasswordInput from './PasswordInput.jsx';

/**
 * Settings → Two-step login. Off → (password) → scan QR → (code) → backup codes → On.
 * On → new backup codes (password) or turn off (password + code).
 */
export default function TwoFactorCard({ initial }) {
  const flash = useFlash();
  const { refresh } = useSession();
  const [on, setOn] = useState(Boolean(initial.two_factor));
  const [left, setLeft] = useState(initial.backup_codes_left);
  const [step, setStep] = useState('idle'); // idle | password | scan | codes | disable | regen
  const [setup, setSetup] = useState(null);
  const [codes, setCodes] = useState([]);
  const [busy, setBusy] = useState(false);

  async function run(fn) {
    setBusy(true);
    try {
      await fn();
    } catch (err) {
      flash(err.message, 'error');
    }
    setBusy(false);
  }

  const values = (e) => {
    e.preventDefault();
    return Object.fromEntries(new FormData(e.currentTarget));
  };

  const start = (e) => {
    const v = values(e);
    run(async () => {
      setSetup(await post('settings/two-factor/setup', v));
      setStep('scan');
    });
  };

  const confirm = (e) => {
    const v = values(e);
    run(async () => {
      const res = await post('settings/two-factor/confirm', v);
      setCodes(res.recovery_codes);
      setOn(true);
      setLeft(res.admin.backup_codes_left);
      setSetup(null);
      setStep('codes');
      flash(res.message);
    });
  };

  const disable = (e) => {
    const v = values(e);
    run(async () => {
      const res = await post('settings/two-factor/disable', v);
      setOn(false);
      setStep('idle');
      flash(res.message);
      refresh();
    });
  };

  const regen = (e) => {
    const v = values(e);
    run(async () => {
      const res = await post('settings/two-factor/recovery-codes', v);
      setCodes(res.recovery_codes);
      setLeft(res.admin.backup_codes_left);
      setStep('codes');
      flash(res.message);
    });
  };

  return (
    <section className="card narrow" id="two-step">
      <h2>Two-step login</h2>
      <p className="muted">
        After your password, the login also asks for a 6-digit code from an app on your phone (Google Authenticator, Microsoft
        Authenticator or Authy). Someone who learns your password still can’t get in.
      </p>
      <p>
        <span className={`tfa-status${on ? ' on' : ''}`}>{on ? 'On' : 'Off'}</span>
        {on && left !== null && <span className="muted"> · {left} backup code{left === 1 ? '' : 's'} left</span>}
      </p>

      {step === 'idle' && (
        <div className="form-actions">
          {on ? (
            <>
              <button className="btn ghost" type="button" onClick={() => setStep('regen')}>New backup codes</button>
              <button className="btn danger" type="button" onClick={() => setStep('disable')}>Turn off</button>
            </>
          ) : (
            <button className="btn" type="button" onClick={() => setStep('password')}>Turn on two-step login</button>
          )}
        </div>
      )}

      {step === 'password' && (
        <form onSubmit={start}>
          <PasswordInput label="Your password (to confirm it’s you)" name="current_password" autoComplete="current-password" autoFocus />
          <div className="form-actions">
            <button className="btn" disabled={busy}>{busy ? 'Please wait…' : 'Continue'}</button>
            <button className="btn ghost" type="button" onClick={() => setStep('idle')}>Cancel</button>
          </div>
        </form>
      )}

      {step === 'scan' && setup && (
        <form onSubmit={confirm}>
          <div className="tfa-steps">
            <QrCode text={setup.uri} />
            <ol>
              <li>Install <b>Google Authenticator</b> (or any authenticator app) on your phone.</li>
              <li>In the app, tap <b>+</b> → <b>Scan a QR code</b> and scan this code.</li>
              <li>
                Can’t scan? Choose <b>Enter a setup key</b> and type:
                <br />
                <span className="tfa-key">{setup.secret}</span>
              </li>
              <li>Type the 6-digit code the app shows:</li>
            </ol>
          </div>
          <label>
            6-digit code
            <input name="code" className="code-input" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9 ]{6,7}" maxLength={7} required autoFocus placeholder="123 456" />
          </label>
          <div className="form-actions">
            <button className="btn" disabled={busy}>{busy ? 'Checking…' : 'Turn on'}</button>
            <button className="btn ghost" type="button" onClick={() => { setSetup(null); setStep('idle'); }}>Cancel</button>
          </div>
        </form>
      )}

      {step === 'codes' && (
        <div>
          <div className="flash info">
            <b>Save these backup codes now.</b> If you lose your phone, each one lets you log in once. They won’t be shown again.
          </div>
          <ul className="tfa-codes">
            {codes.map((c) => <li key={c}>{c}</li>)}
          </ul>
          <div className="form-actions">
            <button className="btn ghost" type="button" onClick={() => downloadCodes(codes)}>Download as a file</button>
            <button className="btn ghost" type="button" onClick={() => navigator.clipboard?.writeText(codes.join('\n')).then(() => flash('Copied.'))}>Copy</button>
            <button className="btn" type="button" onClick={() => { setCodes([]); setStep('idle'); }}>I’ve saved them</button>
          </div>
        </div>
      )}

      {step === 'disable' && (
        <form onSubmit={disable}>
          <PasswordInput label="Your password" name="current_password" autoComplete="current-password" autoFocus />
          <label>
            Code from your app (or a backup code)
            <input name="code" className="code-input" autoComplete="one-time-code" required maxLength={11} />
          </label>
          <div className="form-actions">
            <button className="btn danger" disabled={busy}>{busy ? 'Please wait…' : 'Turn off two-step login'}</button>
            <button className="btn ghost" type="button" onClick={() => setStep('idle')}>Cancel</button>
          </div>
        </form>
      )}

      {step === 'regen' && (
        <form onSubmit={regen}>
          <p className="muted">The old backup codes stop working.</p>
          <PasswordInput label="Your password" name="current_password" autoComplete="current-password" autoFocus />
          <div className="form-actions">
            <button className="btn" disabled={busy}>{busy ? 'Please wait…' : 'Make new codes'}</button>
            <button className="btn ghost" type="button" onClick={() => setStep('idle')}>Cancel</button>
          </div>
        </form>
      )}
    </section>
  );
}

/** QR code drawn in the browser (the secret never goes to a third-party QR service). */
function QrCode({ text }) {
  const [src, setSrc] = useState('');
  useEffect(() => {
    let alive = true;
    import('qrcode')
      .then((QR) => QR.toDataURL(text, { margin: 1, width: 360, errorCorrectionLevel: 'M' }))
      .then((url) => alive && setSrc(url))
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [text]);
  return <div className="tfa-qr">{src ? <img src={src} alt="QR code to scan with your authenticator app" /> : null}</div>;
}

function downloadCodes(codes) {
  const text = `City Florist dashboard: backup codes for two-step login\nEach code works once.\n\n${codes.join('\n')}\n`;
  const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
  const a = Object.assign(document.createElement('a'), { href: url, download: 'city-florist-backup-codes.txt' });
  a.click();
  URL.revokeObjectURL(url);
}
