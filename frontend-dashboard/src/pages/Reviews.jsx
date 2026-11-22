import { useSearchParams } from 'react-router-dom';
import { del, formatDate, patch } from '../api.js';
import { useFlash, useLoad, useSession } from '../session.jsx';
import { Loading } from '../components/Layout.jsx';
import Pager, { usePaged } from '../components/Pager.jsx';

const TABS = { pending: 'Waiting', approved: 'On website', hidden: 'Hidden' };

/** Admin → Reviews: customers' reviews appear on the website only after you approve them here. */
export default function Reviews() {
  const flash = useFlash();
  const { refresh } = useSession();
  const [params, setParams] = useSearchParams();
  const status = TABS[params.get('status')] ? params.get('status') : '';
  const [data, reload, error] = useLoad(`reviews?status=${status}`);
  const paged = usePaged(data?.reviews);

  async function setStatus(r, value) {
    try {
      flash((await patch(`reviews/${r.id}`, { status: value })).message);
      reload();
      refresh(); // sidebar badge
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  async function remove(r) {
    if (!window.confirm(`Delete the review from ${r.full_name}?`)) return;
    try {
      flash((await del(`reviews/${r.id}`)).message);
      reload();
      refresh();
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  return (
    <>
      <div className="page-head">
        <h1>Reviews {data && <small className="muted">{data.summary.count ? `${data.summary.average.toFixed(1)} ★ · ${data.summary.count} on website` : ''}</small>}</h1>
      </div>

      <div className="tabs">
        <button type="button" className={status === '' ? 'active' : ''} onClick={() => setParams({})}>All</button>
        {Object.entries(TABS).map(([k, label]) => (
          <button type="button" key={k} className={status === k ? 'active' : ''} onClick={() => setParams({ status: k })}>
            {label}{k === 'pending' && data?.pending ? ` (${data.pending})` : ''}
          </button>
        ))}
      </div>
      <p className="muted small">
        Approve the honest ones (good or bad). Hiding every critical review makes the rest less believable, and answering it politely on
        WhatsApp often wins the customer back. Google reviews count most in search: use <b>★ Ask for review</b> on delivered orders.
      </p>

      {!data ? (
        <Loading error={error} retry={reload} />
      ) : (
        <div className="order-list">
          {paged.slice.map((r) => (
            <article className="order card" key={r.id}>
              <header>
                <b><span className="review-stars" aria-label={`${r.rating} stars`}>{'★'.repeat(r.rating)}<span>{'★'.repeat(5 - r.rating)}</span></span> {r.full_name}</b>
                <span className="muted small">{formatDate(r.date, false)}</span>
              </header>
              <div className="order-body">
                <div>
                  <div className="note">“{r.text}”</div>
                  <div className="small muted">
                    {r.verified ? <b className="ok-text">✓ Verified order</b> : 'Not matched to an order'}
                    {r.phone && <> · <a href={`tel:+${r.phone}`}>+{r.phone}</a></>}
                    {' · '}shown as “{r.name}”
                  </div>
                </div>
                <div className="order-actions">
                  {r.status !== 'approved' && <button type="button" className="btn small" onClick={() => setStatus(r, 'approved')}>Show on website</button>}
                  {r.status !== 'hidden' && <button type="button" className="btn small ghost" onClick={() => setStatus(r, 'hidden')}>Hide</button>}
                  <button type="button" className="btn small danger" onClick={() => remove(r)} aria-label="Delete review">✕</button>
                </div>
              </div>
            </article>
          ))}
          {!data.reviews.length && <p className="muted">No reviews here yet. They arrive from the “Write a review” form on the home page.</p>}
          <Pager page={paged.page} pages={paged.pages} onChange={paged.setPage} />
        </div>
      )}
    </>
  );
}
