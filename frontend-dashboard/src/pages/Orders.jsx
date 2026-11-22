import { useSearchParams } from 'react-router-dom';
import { del, formatDate, patch, rupees } from '../api.js';
import { useFlash, useLoad, useSession } from '../session.jsx';
import { Loading } from '../components/Layout.jsx';
import Pager from '../components/Pager.jsx';
import { API_URL } from '../urls.js';

const STATUSES = { new: 'New', contacted: 'Contacted', done: 'Delivered', cancelled: 'Cancelled' };

export default function Orders() {
  const flash = useFlash();
  const { setNewOrders } = useSession();
  const [params, setParams] = useSearchParams();
  const status = STATUSES[params.get('status')] ? params.get('status') : '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const [data, reload, error, setData] = useLoad(`orders?status=${status}&page=${page}`);

  const go = (next) => setParams(Object.fromEntries(Object.entries(next).filter(([, v]) => v && v !== 1)));

  async function setStatus(order, value) {
    try {
      const res = await patch(`orders/${order.id}`, { status: value });
      setNewOrders(res.new_orders);
      setData((d) => ({ ...d, orders: d.orders.map((o) => (o.id === order.id ? res.order : o)) }));
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  async function remove(order) {
    if (!window.confirm(`Delete order #${order.id}?`)) return;
    try {
      const res = await del(`orders/${order.id}`);
      setNewOrders(res.new_orders);
      flash(res.message);
      reload();
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  return (
    <>
      <div className="page-head">
        <h1>Orders {data && <small className="muted">{data.total}</small>}</h1>
        {/* A normal download link: the session cookie is sent along. */}
        <a className="btn ghost" href={`${API_URL}/api/admin/orders/export?status=${status}`}>Download CSV</a>
      </div>

      <div className="tabs">
        <button type="button" className={status === '' ? 'active' : ''} onClick={() => go({})}>All</button>
        {Object.entries(STATUSES).map(([k, label]) => (
          <button type="button" key={k} className={status === k ? 'active' : ''} onClick={() => go({ status: k })}>{label}</button>
        ))}
      </div>

      <p className="muted small">Every “Buy” on the website is saved here, even if the customer didn’t press send in WhatsApp, so you can follow up.</p>

      {!data ? (
        <Loading error={error} retry={reload} />
      ) : (
        <>
          <div className="order-list">
            {data.orders.map((o) => {
              const reply = `Hello ${o.customer_name}, thank you for your order #${o.id} (${o.product_name}) at City Florist 🌸`;
              return (
                <article className="order card" key={o.id}>
                  <header>
                    <b>#{o.id} · {o.product_name}</b>
                    <span className="muted small">{formatDate(o.created_at)}</span>
                  </header>
                  <div className="order-body">
                    <div>
                      <div>{o.customer_name} · <a href={`tel:+${o.customer_phone}`}>+{o.customer_phone}</a></div>
                      <div className="price">{o.price_at_order > 0 ? rupees(o.price_at_order) : 'Price on request'}</div>
                      {o.delivery_date && <div className="small">Needed on: <b>{formatDate(o.delivery_date, false)}</b></div>}
                      {o.note && <div className="small note">“{o.note}”</div>}
                    </div>
                    <div className="order-actions">
                      <a className="btn small wa" target="_blank" rel="noreferrer" href={`https://wa.me/${o.customer_phone}?text=${encodeURIComponent(reply)}`}>WhatsApp</a>
                      {o.status === 'done' && data.review_link && (
                        <a
                          className="btn small ghost"
                          target="_blank"
                          rel="noreferrer"
                          title="Send the customer the Google review link on WhatsApp"
                          href={`https://wa.me/${o.customer_phone}?text=${encodeURIComponent(`Hello ${o.customer_name}, thank you for choosing City Florist 🌸 We hope you loved your ${o.product_name}! If you have a minute, a Google review helps our small shop a lot: ${data.review_link}`)}`}
                        >
                          ★ Ask for review
                        </a>
                      )}
                      <select className={`pill-select ${o.status}`} value={o.status} onChange={(e) => setStatus(o, e.target.value)} aria-label="Status">
                        {Object.entries(STATUSES).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                      </select>
                      <button type="button" className="btn small danger" onClick={() => remove(o)} aria-label="Delete order">✕</button>
                    </div>
                  </div>
                </article>
              );
            })}
            {!data.orders.length && <p className="muted">No orders here yet.</p>}
          </div>

          <Pager page={data.page} pages={data.pages} onChange={(n) => go({ status, page: n })} />
        </>
      )}
    </>
  );
}
