import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { del, post } from '../api.js';
import { useFlash, useLoad } from '../session.jsx';
import { Loading } from '../components/Layout.jsx';
import Pager, { usePaged } from '../components/Pager.jsx';

export default function Products() {
  const flash = useFlash();
  const [params, setParams] = useSearchParams();
  const q = params.get('q') || '';
  const cat = params.get('cat') || '';
  const unpriced = params.get('unpriced') === '1';
  const featured = params.get('featured') === '1';

  const [cats] = useLoad('categories');
  const query = new URLSearchParams({ q, cat, ...(unpriced ? { unpriced: '1' } : {}), ...(featured ? { featured: '1' } : {}) }).toString();
  const [data, reload, error] = useLoad(`products?${query}`);
  const paged = usePaged(data?.products);

  // Editable copy of the list, sent as a whole by "Save all changes".
  const [rows, setRows] = useState({});
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState(q);

  useEffect(() => setSearch(q), [q]); // the header search box changes ?q=

  useEffect(() => {
    if (!data) return;
    setRows(
      Object.fromEntries(
        data.products.map((p) => [
          p.id,
          { name: p.name, price: p.price, sale: p.sale_price ?? '', active: p.active, featured: p.featured, ready: p.ready },
        ])
      )
    );
  }, [data]);

  const setFilter = (key, value) => {
    const next = new URLSearchParams(params);
    next.delete('page');
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };
  const edit = (id, key, value) => setRows((r) => ({ ...r, [id]: { ...r[id], [key]: value } }));

  async function saveAll(e) {
    e.preventDefault();
    setSaving(true);
    try {
      const res = await post('products/bulk', { rows });
      flash(res.message);
      await reload();
    } catch (err) {
      flash(err.message, 'error');
    }
    setSaving(false);
  }

  async function remove(p) {
    if (!window.confirm(`Delete “${p.name}” and its photos?`)) return;
    try {
      flash((await del(`products/${p.id}`)).message);
      reload();
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  return (
    <>
      <div className="page-head">
        <h1>Products {data && <small className="muted">{data.products.length}</small>}</h1>
        <Link className="btn" to="/products/new">+ Add product</Link>
      </div>

      <form className="filters" onSubmit={(e) => { e.preventDefault(); setFilter('q', search.trim()); }}>
        <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by name…" />
        <select value={cat} onChange={(e) => setFilter('cat', e.target.value)}>
          <option value="">All categories</option>
          {cats?.categories.map((c) => <option key={c.id} value={c.slug}>{c.name}</option>)}
        </select>
        <label className="check">
          <input type="checkbox" checked={unpriced} onChange={(e) => setFilter('unpriced', e.target.checked ? '1' : '')} /> Only without price
        </label>
        <label className="check">
          <input type="checkbox" checked={featured} onChange={(e) => setFilter('featured', e.target.checked ? '1' : '')} /> Only featured
        </label>
        <button className="btn ghost" type="submit">Filter</button>
      </form>

      <p className="muted small">
        Tip: edit names and prices right here and press <b>Save all changes</b>. Leave the price at 0 to show “Ask for price”.
        Click a photo to edit photos and description. Tick <b>Ready to go</b> on 4–6 bouquets you have made up in the shop
        today to show them in the home page’s “Ready to go” ring.
      </p>

      {!data ? (
        <Loading error={error} retry={reload} />
      ) : (
        <form onSubmit={saveAll}>
          <div className="product-list">
            {paged.slice.map((p) => {
              const r = rows[p.id];
              if (!r) return null;
              return (
                <div key={p.id} className={`product-row ${r.active ? '' : 'inactive'}`}>
                  <Link className="thumb" to={`/products/${p.id}`}>
                    {p.thumb ? <img src={p.thumb} alt="" loading="lazy" /> : <span>No photo</span>}
                  </Link>
                  <div className="fields">
                    <input className="name" value={r.name} onChange={(e) => edit(p.id, 'name', e.target.value)} aria-label="Name" />
                    <span className="muted small">{p.category}</span>
                    <div className="price-fields">
                      <label>Price ₹<input type="number" min="0" step="1" value={r.price} onChange={(e) => edit(p.id, 'price', e.target.value)} /></label>
                      <label>Sale ₹<input type="number" min="0" step="1" value={r.sale} placeholder="—" onChange={(e) => edit(p.id, 'sale', e.target.value)} /></label>
                    </div>
                    <div className="toggles">
                      <label className="check"><input type="checkbox" checked={r.active} onChange={(e) => edit(p.id, 'active', e.target.checked)} /> Visible</label>
                      <label className="check"><input type="checkbox" checked={r.featured} onChange={(e) => edit(p.id, 'featured', e.target.checked)} /> Featured</label>
                      <label className="check" title="Show in the “Ready to go” ring on the home page">
                        <input type="checkbox" checked={r.ready} onChange={(e) => edit(p.id, 'ready', e.target.checked)} /> Ready to go
                      </label>
                    </div>
                  </div>
                  <div className="row-actions">
                    <Link className="btn small ghost" to={`/products/${p.id}`}>Edit</Link>
                    <button className="btn small danger" type="button" onClick={() => remove(p)}>Delete</button>
                  </div>
                </div>
              );
            })}
          </div>
          <Pager page={paged.page} pages={paged.pages} onChange={paged.setPage} />
          {data.products.length ? (
            <div className="sticky-save">
              <button className="btn" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save all changes'}</button>
            </div>
          ) : (
            <p className="muted">No products found.</p>
          )}
        </form>
      )}
    </>
  );
}
