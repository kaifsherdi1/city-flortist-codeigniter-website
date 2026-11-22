import { del, formatDate, patch } from '../api.js';
import { useFlash, useLoad, useSession } from '../session.jsx';
import { Loading } from './Layout.jsx';

/**
 * Settings → Team (owner only): switch staff accounts on or off, or remove them. (The dashboard has no staff
 * sign-up page any more, so no new accounts arrive here.) Staff can manage products, offers, orders and reviews, but not
 * shop details, categories or the team.
 */
export default function TeamCard() {
  const flash = useFlash();
  const { refresh } = useSession();
  const [data, reload, error] = useLoad('team');

  async function setStatus(member, status) {
    try {
      flash((await patch(`team/${member.id}`, { status })).message);
      reload();
      refresh(); // badge
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  async function remove(member) {
    if (!window.confirm(`Remove ${member.username}? They will no longer be able to log in.`)) return;
    try {
      flash((await del(`team/${member.id}`)).message);
      reload();
      refresh();
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  return (
    <section className="card narrow" id="team">
      <h2>Team</h2>
      <p className="muted">
        Staff accounts. Staff can manage products, offers, orders and reviews; only you can change shop details, categories and the team.
      </p>
      {!data ? (
        <Loading error={error} retry={reload} />
      ) : (
        <ul className="team-list">
          {data.team.map((m) => (
            <li key={m.id} className={`team-row ${m.status}`}>
              <div>
                <b>{m.username}</b> {m.me && <span className="muted small">(you)</span>}
                <span className={`team-badge ${m.status}`}>{m.role === 'owner' ? 'Owner' : data.statuses[m.status]}</span>
                <div className="muted small">
                  {m.email || 'no email'}{m.phone ? ` · +${m.phone}` : ''}{m.created_at ? ` · signed up ${formatDate(m.created_at, false)}` : ''}
                </div>
              </div>
              {m.role !== 'owner' && (
                <div className="team-actions">
                  {m.status !== 'active' && <button type="button" className="btn small" onClick={() => setStatus(m, 'active')}>{m.status === 'pending' ? 'Approve' : 'Switch on'}</button>}
                  {m.status === 'active' && <button type="button" className="btn small ghost" onClick={() => setStatus(m, 'disabled')}>Switch off</button>}
                  <button type="button" className="btn small danger" onClick={() => remove(m)} aria-label={`Remove ${m.username}`}>{m.status === 'pending' ? 'Decline' : 'Remove'}</button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
