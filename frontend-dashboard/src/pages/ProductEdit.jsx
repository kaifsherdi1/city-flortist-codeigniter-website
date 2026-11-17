import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { del, get, post, upload } from '../api.js';
import { useFlash, useLoad, useReport } from '../session.jsx';
import { Loading } from '../components/Layout.jsx';

const EMPTY = { name: '', category_id: '', description: '', price: '', sale_price: '', is_active: true, is_featured: false, images: [] };

export default function ProductEdit() {
  const { id } = useParams();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const flash = useFlash();
  const report = useReport();
  const [cats] = useLoad('categories');

  const [product, setProduct] = useState(id ? null : { ...EMPTY, category_id: params.get('cat') || '' });
  const [error, setError] = useState(null);
  const [files, setFiles] = useState([]);
  const [busy, setBusy] = useState(false);
  const fileInput = useRef(null);

  useEffect(() => {
    if (!id) {
      setProduct({ ...EMPTY, category_id: params.get('cat') || '' });
      return;
    }
    setProduct(null);
    get(`products/${id}`)
      .then((res) => setProduct(fromApi(res.product)))
      .catch((err) => {
        if (err.status === 404) {
          flash('Product not found.', 'error');
          navigate('/products', { replace: true });
        } else setError(err);
      });
  }, [id]); // eslint-disable-line react-hooks/exhaustive-deps

  const previews = useMemo(() => files.map((f) => URL.createObjectURL(f)), [files]);
  useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

  if (!product) return <Loading error={error} />;

  const set = (key, value) => setProduct((p) => ({ ...p, [key]: value }));

  async function save(e, close) {
    e.preventDefault();
    const form = new FormData();
    ['name', 'category_id', 'description', 'price', 'sale_price'].forEach((k) => form.append(k, product[k] ?? ''));
    form.append('is_active', product.is_active ? '1' : '0');
    form.append('is_featured', product.is_featured ? '1' : '0');
    files.forEach((f) => form.append('images[]', f));

    setBusy(true);
    try {
      const res = await upload(id ? `products/${id}` : 'products', form);
      report(res);
      setFiles([]);
      if (fileInput.current) fileInput.current.value = '';
      if (close) navigate('/products');
      else if (!id) navigate(`/products/${res.product.id}`, { replace: true });
      else setProduct(fromApi(res.product));
    } catch (err) {
      flash(err.message, 'error');
    }
    setBusy(false);
  }

  async function imageAction(img, action) {
    if (action === 'delete' && !window.confirm('Delete this photo?')) return;
    try {
      const res = action === 'delete' ? await del(`products/${id}/images/${img.id}`) : await post(`products/${id}/images/${img.id}/main`);
      setProduct((p) => ({ ...p, images: res.product.images }));
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  return (
    <>
      <div className="page-head">
        <h1>{id ? 'Edit product' : 'Add product'}</h1>
        <div className="actions">
          {id && product.slug && <a className="btn ghost" target="_blank" rel="noreferrer" href={`/product/${product.slug}`}>View on site ↗</a>}
          <Link className="btn ghost" to="/products">← All products</Link>
        </div>
      </div>

      <form className="card form-grid" onSubmit={(e) => save(e, true)}>
        <div className="col">
          <label>Product name
            <input value={product.name} onChange={(e) => set('name', e.target.value)} required placeholder="e.g. 25 Red Roses Bouquet" />
          </label>
          <label>Category
            <select value={product.category_id} onChange={(e) => set('category_id', e.target.value)} required>
              <option value="">Choose…</option>
              {cats?.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </label>
          <div className="two">
            <label>Price (₹)
              <input type="number" min="0" step="1" value={product.price} onChange={(e) => set('price', e.target.value)} placeholder="0 = Ask for price" />
            </label>
            <label>Sale price (₹)
              <input type="number" min="0" step="1" value={product.sale_price} onChange={(e) => set('sale_price', e.target.value)} placeholder="optional" />
            </label>
          </div>
          <label>Description
            <textarea rows="5" value={product.description} onChange={(e) => set('description', e.target.value)} placeholder="Flowers used, size, what’s included…" />
          </label>
          <div className="toggles">
            <label className="check"><input type="checkbox" checked={product.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Visible on website</label>
            <label className="check"><input type="checkbox" checked={product.is_featured} onChange={(e) => set('is_featured', e.target.checked)} /> Show on home page (featured)</label>
          </div>
        </div>

        <div className="col">
          <h3>Photos</h3>
          <div className="image-grid">
            {product.images.map((img, i) => (
              <figure key={img.id} className={i === 0 ? 'main' : ''}>
                <img src={img.thumb} alt="" />
                <figcaption>
                  {i === 0 ? <span className="pill">Main</span> : (
                    <button type="button" className="btn small ghost" onClick={() => imageAction(img, 'main')}>Make main</button>
                  )}
                  <button type="button" className="btn small danger" onClick={() => imageAction(img, 'delete')} aria-label="Delete photo">✕</button>
                </figcaption>
              </figure>
            ))}
          </div>
          <label className="dropzone">
            <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(e) => setFiles([...e.target.files])} />
            <span><b>Tap to add photos</b><br /><small>JPG / PNG / WebP · you can pick several · first photo is the main one</small></span>
          </label>
          {previews.length > 0 && (
            <div className="upload-preview">{previews.map((src) => <img key={src} src={src} alt="" />)}</div>
          )}
        </div>

        <div className="form-actions">
          <button className="btn" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
          <button className="btn ghost" type="button" disabled={busy} onClick={(e) => save(e, false)}>Save & keep editing</button>
        </div>
      </form>
    </>
  );
}

function fromApi(p) {
  return { ...p, price: p.price || '', sale_price: p.sale_price ?? '', category_id: String(p.category_id) };
}
