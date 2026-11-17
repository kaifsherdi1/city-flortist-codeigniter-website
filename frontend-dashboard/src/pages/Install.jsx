import { useState } from 'react';
import { post } from '../api.js';
import { useSession } from '../session.jsx';
import { Screen } from '../components/Layout.jsx';

/** One-time installer. The server refuses it once an admin login exists. */
export default function Install() {
  const { database, refresh } = useSession();
  const [seed, setSeed] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [done, setDone] = useState(null);

  async function install(e) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      setDone(await post('install', { seed }));
    } catch (err) {
      setError(err.message);
    }
    setBusy(false);
  }

  if (done) {
    return (
      <Screen>
        <p className="muted">Website installer</p>
        <ul className="install-log">{done.log.map((l) => <li key={l}>✓ {l}</li>)}</ul>
        <p>
          Log in with username <b>{done.username}</b> and the password from the server’s <code>.env</code> file
          (<code>florist.adminPassword</code>), then change the password in Settings.
        </p>
        <button className="btn" type="button" onClick={refresh}>Go to login</button>
      </Screen>
    );
  }

  return (
    <Screen>
      <form onSubmit={install}>
        <p className="muted">Website installer</p>
        {error && <div className="flash error">{error}</div>}
        <p>Database: <b>{database}</b></p>
        <label className="check">
          <input type="checkbox" checked={seed} onChange={(e) => setSeed(e.target.checked)} /> Import the starter product photos
        </label>
        <button className="btn" type="submit" disabled={busy}>{busy ? 'Installing… (this can take a minute)' : 'Install'}</button>
      </form>
    </Screen>
  );
}
