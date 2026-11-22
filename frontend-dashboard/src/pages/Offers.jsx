import { useRef, useState } from 'react';
import { del, localInput, post, upload } from '../api.js';
import { useFlash, useLoad, useReport } from '../session.jsx';
import { Loading } from '../components/Layout.jsx';
import Pager, { usePaged } from '../components/Pager.jsx';

function blankOffer() {
  const end = new Date();
  end.setHours(23, 59, 0, 0);
  return { id: null, title: '', subtitle: '', discount_pct: 10, target: 'all', starts_at: localInput(new Date()), ends_at: localInput(end), is_active: true, image: null };
}

/** Quick date ranges: today only, this weekend, or the next 7 days. */
function preset(kind) {
  const start = new Date();
  const end = new Date();
  if (kind === 'today') {
    end.setHours(23, 59, 0, 0);
  } else if (kind === 'weekend') {
    const day = start.getDay();
    const weekend = day === 0 || day === 6;
    if (!weekend) {
      start.setDate(start.getDate() + (6 - day));
      start.setHours(0, 0, 0, 0);
    }
    end.setTime(start.getTime());
    if (end.getDay() === 6) end.setDate(end.getDate() + 1);
    end.setHours(23, 59, 0, 0);
  } else {
    end.setDate(end.getDate() + 6);
    end.setHours(23, 59, 0, 0);
  }
  return { starts_at: localInput(start), ends_at: localInput(end) };
}

export default function Offers() {
  const flash = useFlash();
  const report = useReport();
  const [data, reload, error] = useLoad('offers');
  const paged = usePaged(data?.offers);
  const [offer, setOffer] = useState(blankOffer);
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const formRef = useRef(null);
  const fileRef = useRef(null);

  const set = (key, value) => setOffer((o) => ({ ...o, [key]: value }));

  function startEdit(o) {
    setOffer({ ...o });
    setFile(null);
    if (fileRef.current) fileRef.current.value = '';
    formRef.current?.scrollIntoView({ behavior: 'smooth' });
  }

  function reset() {
    setOffer(blankOffer());
    setFile(null);
    if (fileRef.current) fileRef.current.value = '';
  }

  async function save(e) {
    e.preventDefault();
    const form = new FormData();
    ['title', 'subtitle', 'discount_pct', 'target', 'starts_at', 'ends_at'].forEach((k) => form.append(k, offer[k] ?? ''));
    form.append('is_active', offer.is_active ? '1' : '0');
    if (file) form.append('image', file);
    setBusy(true);
    try {
      report(await upload(offer.id ? `offers/${offer.id}` : 'offers', form));
      reset();
      reload();
    } catch (err) {
      flash(err.message, 'error');
    }
    setBusy(false);
  }

  async function act(o, action) {
    try {
      if (action === 'delete') {
        if (!window.confirm('Delete this offer?')) return;
        flash((await del(`offers/${o.id}`)).message);
        if (offer.id === o.id) reset();
      } else {
        flash((await post(`offers/${o.id}/toggle`)).message);
      }
      reload();
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  return (
    <>
      <div className="page-head"><h1>Daily Offers</h1></div>
      <p className="muted">Offers show in the gold banner on the home page and automatically reduce prices. They switch off by themselves at the end time.</p>

      {!data ? (
        <Loading error={error} retry={reload} />
      ) : (
        <>
          <section className="card">
            {!data.offers.length ? (
              <p className="muted">No offers yet. Create your first one below.</p>
            ) : (
              <div className="table-scroll">
                <table>
                  <thead><tr><th>Offer</th><th>Discount</th><th>Applies to</th><th>Runs</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    {paged.slice.map((o) => (
                      <tr key={o.id}>
                        <td><b>{o.title}</b><br /><small className="muted">{o.subtitle}</small></td>
                        <td>{o.discount_pct}%</td>
                        <td>{o.applies_to}</td>
                        <td className="small">{fmt(o.starts_at)}<br />→ {fmt(o.ends_at)}</td>
                        <td><span className={`pill ${o.state}`}>{o.state}</span></td>
                        <td className="nowrap">
                          <button type="button" className="btn small ghost" onClick={() => startEdit(o)}>Edit</button>{' '}
                          <button type="button" className="btn small ghost" onClick={() => act(o, 'toggle')}>{o.is_active ? 'Pause' : 'Resume'}</button>{' '}
                          <button type="button" className="btn small danger" onClick={() => act(o, 'delete')} aria-label="Delete offer">✕</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <Pager page={paged.page} pages={paged.pages} onChange={paged.setPage} />
          </section>

          <form ref={formRef} id="new" className="card form-grid" onSubmit={save}>
            <h2 className="full">{offer.id ? 'Edit offer' : 'New offer'}</h2>
            <div className="col">
              <label>Title <input value={offer.title} onChange={(e) => set('title', e.target.value)} required placeholder="e.g. Valentine’s Rose Week" /></label>
              <label>Short line <input value={offer.subtitle} onChange={(e) => set('subtitle', e.target.value)} placeholder="e.g. Flat 20% off on all red rose bouquets" /></label>
              <div className="two">
                <label>Discount % <input type="number" min="0" max="90" value={offer.discount_pct} onChange={(e) => set('discount_pct', e.target.value)} /></label>
                <label>Applies to
                  <select value={offer.target} onChange={(e) => set('target', e.target.value)}>
                    <option value="all">Everything</option>
                    <optgroup label="Category">
                      {data.categories.map((c) => <option key={c.id} value={`category:${c.id}`}>{c.name}</option>)}
                    </optgroup>
                    <optgroup label="Single product">
                      {data.products.map((p) => <option key={p.id} value={`product:${p.id}`}>{p.name}</option>)}
                    </optgroup>
                  </select>
                </label>
              </div>
              <p className="small muted">Use 0% for an announcement without a discount (e.g. “Free delivery in Hubballi today”).</p>
            </div>
            <div className="col">
              <div className="presets">
                <span className="small muted">Quick:</span>
                <button type="button" className="btn small ghost" onClick={() => setOffer((o) => ({ ...o, ...preset('today') }))}>Today only</button>
                <button type="button" className="btn small ghost" onClick={() => setOffer((o) => ({ ...o, ...preset('weekend') }))}>This weekend</button>
                <button type="button" className="btn small ghost" onClick={() => setOffer((o) => ({ ...o, ...preset('week') }))}>7 days</button>
              </div>
              <div className="two">
                <label>Starts <input type="datetime-local" value={offer.starts_at} onChange={(e) => set('starts_at', e.target.value)} required /></label>
                <label>Ends <input type="datetime-local" value={offer.ends_at} onChange={(e) => set('ends_at', e.target.value)} required /></label>
              </div>
              <label>Banner photo (optional)
                <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setFile(e.target.files[0] || null)} />
              </label>
              {offer.image && !file && <img className="offer-img" src={offer.image} alt="" />}
              <label className="check"><input type="checkbox" checked={offer.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Active</label>
            </div>
            <div className="form-actions">
              <button className="btn" type="submit" disabled={busy}>{busy ? 'Saving…' : offer.id ? 'Save offer' : 'Publish offer'}</button>
              {offer.id && <button type="button" className="btn ghost" onClick={reset}>Cancel</button>}
            </div>
          </form>
        </>
      )}
    </>
  );
}

const fmt = (local) =>
  new Date(local).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
