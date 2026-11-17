import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { formatDate, put, rupees } from '../api.js';
import { useFlash, useLoad } from '../session.jsx';
import { Loading } from '../components/Layout.jsx';
import Icon from '../components/Icon.jsx';
import { Donut, GroupedBarChart, MiniBars, MiniLine, Ring } from '../components/Charts.jsx';

const STATUS = [
  ['new', 'New', 'var(--primary)'],
  ['contacted', 'Contacted', 'var(--pink)'],
  ['done', 'Delivered', 'var(--yellow)'],
  ['cancelled', 'Cancelled', 'var(--neutral)'],
];
const SERIES = [
  { label: 'Received', color: 'var(--primary)' },
  { label: 'Delivered', color: 'var(--pink)' },
];

function greeting() {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

const asDate = (key) => new Date(key.length === 7 ? `${key}-01T00:00:00` : key.length === 13 ? `${key.slice(0, 10)}T${key.slice(11)}:00:00` : `${key}T00:00:00`);
const hourName = (h) => `${h % 12 || 12} ${h < 12 ? 'am' : 'pm'}`;

/** Buckets for the Order statistics chart: today by hour, this week by day, the last 12 months. */
function bucketsFor(data, period) {
  if (period === 'today') {
    return data.hours.map((b, h) => ({ ...b, label: h % 3 === 0 ? hourName(h) : '', title: `Today ${hourName(h)}–${hourName((h + 1) % 24)}` }));
  }
  if (period === 'week') {
    return data.days.slice(-7).map((b) => {
      const d = asDate(b.key);
      return { ...b, label: d.toLocaleDateString('en-IN', { weekday: 'short' }), title: d.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' }) };
    });
  }
  return data.months.map((b) => {
    const d = asDate(b.key);
    return { ...b, label: d.toLocaleDateString('en-IN', { month: 'short' }), title: d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }) };
  });
}

/** "▲ 20% vs last week", or a plain note when there is nothing to compare with. */
function Trend({ now, before, period = 'week', fallback }) {
  if (!before) return <span className="sub">{fallback}</span>;
  const pct = Math.round(((now - before) / before) * 100);
  return (
    <span className={`trend ${pct >= 0 ? 'up' : 'down'}`}>
      {pct >= 0 ? '▲' : '▼'} {Math.abs(pct)}% <span className="sub">vs last {period}</span>
    </span>
  );
}

export default function Dashboard() {
  const [data, reload, error, setData] = useLoad('dashboard');
  const [period, setPeriod] = useState('week');

  const buckets = useMemo(() => (data ? bucketsFor(data, period) : []), [data, period]);

  if (!data) return <Loading error={error} retry={reload} />;

  const s = data.stats;
  const week = data.days.slice(-7);
  const statusParts = STATUS.map(([key, label, color]) => ({ key, label, color, value: data.statuses[key] || 0 }));
  const totalOrders = statusParts.reduce((a, p) => a + p.value, 0);
  const maxCat = Math.max(1, ...data.categories.map((c) => c.count));
  const received = buckets.reduce((a, b) => a + b.orders, 0);
  const delivered = buckets.reduce((a, b) => a + b.delivered, 0);
  const value = buckets.reduce((a, b) => a + b.value, 0);
  const periodName = { today: 'Today', week: 'Last 7 days', month: 'Last 12 months' }[period];

  return (
    <div className="home-grid">
      <div className="home-col">
        <section className="hero">
          <div>
            <h2>{greeting()}! Manage your flower shop in one place</h2>
            <p>Add bouquets, set today’s prices and offers, and follow up on every WhatsApp order from here.</p>
            <div className="actions">
              <Link className="btn" to="/products/new"><Icon name="plus" size={18} /> Add product</Link>
              <Link className="btn ghost" to="/offers#new">Today’s offer</Link>
            </div>
          </div>
          <HeroArt />
        </section>

        <section className="card">
          <div className="card-head">
            <div>
              <h2>Order statistics</h2>
              <p className="small muted">{periodName}</p>
            </div>
            <div className="seg" role="group" aria-label="Period">
              {[['month', 'Monthly'], ['week', 'Weekly'], ['today', 'Today']].map(([v, label]) => (
                <button key={v} type="button" className={period === v ? 'active' : ''} onClick={() => setPeriod(v)}>{label}</button>
              ))}
            </div>
          </div>

          <div className="stat-summary">
            <div className="item">
              <Ring value={delivered} total={received} size={64} thickness={8} label="" />
              <div><b>{received}</b><span>Orders received</span></div>
            </div>
            <div className="item">
              <span className="dotmark" style={{ background: 'var(--pink)' }} />
              <div><b>{delivered}</b><span>Delivered</span></div>
            </div>
            <div className="item">
              <span className="dotmark" style={{ background: 'var(--yellow)' }} />
              <div><b>{rupees(value)}</b><span>Order value</span></div>
            </div>
          </div>

          <ul className="legend" style={{ marginBottom: 8 }}>
            {SERIES.map((x) => <li key={x.label}><span className="swatch" style={{ background: x.color }} /> {x.label}</li>)}
          </ul>
          <GroupedBarChart
            series={SERIES}
            data={buckets.map((b) => ({
              key: b.key,
              label: b.label,
              title: b.title,
              values: [b.orders, b.delivered],
              tip: `${b.title}: ${b.orders} received, ${b.delivered} delivered`,
            }))}
          />
        </section>

        <section className="card">
          <div className="card-head"><h2>Latest orders</h2><Link to="/orders">All orders →</Link></div>
          {!data.recent.length ? (
            <p className="muted">No orders yet. When a customer taps “Buy” on the website it will show up here.</p>
          ) : (
            <ul className="mini-list">
              {data.recent.map((o) => (
                <li key={o.id}>
                  <span className="avatar" aria-hidden="true">{o.customer_name.slice(0, 1)}</span>
                  <div className="who">
                    <b>{o.customer_name} · #{o.id}</b>
                    <span>{o.product_name} · {formatDate(o.created_at)}</span>
                  </div>
                  <span className="amt">{o.price_at_order > 0 ? rupees(o.price_at_order) : 'On request'}</span>
                  <span className={`pill ${o.status}`}>{o.status === 'done' ? 'delivered' : o.status}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <div className="home-col">
        <div className="kpis">
          <Link to="/orders" className="card kpi">
            <span className="label">Orders this week</span>
            <div className="row">
              <div>
                <div className="value">{s.orders_week}</div>
                <Trend now={s.orders_week} before={s.orders_prev_week} fallback="last 7 days" />
              </div>
              <MiniBars values={week.map((d) => d.orders)} />
            </div>
          </Link>

          <TargetCard
            target={data.target}
            done={s.orders_month}
            onSaved={(target) => setData((d) => ({ ...d, target }))}
          />

          <div className="card kpi">
            <span className="label">Order value this week</span>
            <div className="row">
              <div>
                <div className="value">{rupees(s.value_week)}</div>
                <Trend now={s.value_week} before={s.value_prev_week} fallback="last 7 days" />
              </div>
              <MiniLine values={week.map((d) => d.value)} color="var(--pink)" />
            </div>
          </div>

          <div className="card kpi">
            <span className="label">Customers this month</span>
            <div className="row">
              <div>
                <div className="value">{s.customers_month}</div>
                <Trend now={s.customers_month} before={s.customers_prev_month} period="month" fallback="different phone numbers" />
              </div>
              <MiniLine values={data.days.map((d) => d.customers)} color="var(--primary)" />
            </div>
          </div>
        </div>

        <Goals goals={data.goals} unpriced={data.unpriced} />

        <section className="card">
          <div className="card-head"><h2>Orders by status</h2><Link to="/orders" className="small">All time →</Link></div>
          <div className="donut-row">
            <Donut parts={statusParts} centerLabel="Orders" />
            <ul className="legend">
              {statusParts.map((p) => (
                <li key={p.key}>
                  <span className="swatch" style={{ background: p.color }} />
                  <Link to={`/orders?status=${p.key}`} style={{ color: 'inherit' }}>{p.label}</Link>
                  <b>{p.value}</b>
                  <span className="muted small">{totalOrders ? `${Math.round((p.value / totalOrders) * 100)}%` : ''}</span>
                </li>
              ))}
            </ul>
          </div>
        </section>

        <section className="card">
          <div className="card-head"><h2>Products by category</h2><span className="small muted">Live on the website</span></div>
          <ul className="hbars">
            {data.categories.map((c) => (
              <li key={c.slug}>
                <Link to={`/products?cat=${c.slug}`}>{c.name}</Link>
                <div className="bar-track"><div className="bar-fill" style={{ width: `${(c.count / maxCat) * 100}%` }} /></div>
                <b>{c.count}</b>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </div>
  );
}

/** Orders this month against the owner's monthly goal, editable in place. */
function TargetCard({ target, done, onSaved }) {
  const flash = useFlash();
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(target);
  const pct = Math.min(100, Math.round((done / Math.max(1, target)) * 100));
  const left = Math.max(0, target - done);

  async function save(e) {
    e.preventDefault();
    try {
      const res = await put('dashboard/target', { target: Number(value) });
      onSaved(res.target);
      flash(res.message);
      setEditing(false);
    } catch (err) {
      flash(err.message, 'error');
    }
  }

  return (
    <div className="card kpi">
      <div className="row" style={{ alignItems: 'center' }}>
        <span className="label">Monthly target</span>
        {!editing && <button type="button" className="link-btn" onClick={() => { setValue(target); setEditing(true); }}>Edit</button>}
      </div>
      {editing ? (
        <form className="target-edit" onSubmit={save}>
          <input type="number" min="1" value={value} onChange={(e) => setValue(e.target.value)} aria-label="Orders per month" autoFocus />
          <button className="btn small" type="submit">Save</button>
        </form>
      ) : (
        <div>
          <div className="bar-track" style={{ height: 8, marginTop: 14 }} role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Monthly target">
            <div className="bar-fill" style={{ width: `${pct}%` }} />
          </div>
          <div className="target-row">
            <span>{left > 0 ? `${left} left to reach the target` : 'Target reached 🎉'}</span>
            <b>{done}/{target}</b>
          </div>
        </div>
      )}
    </div>
  );
}

/** One goal at a time with ‹ › arrows, like a small carousel. */
function Goals({ goals, unpriced }) {
  const [i, setI] = useState(0);
  const list = [
    {
      title: 'Prices set',
      text: unpriced > 0 ? `${unpriced} visible products still show “Ask for price” on the website.` : 'Every visible product shows its price on the website.',
      value: goals.priced, total: goals.products, unit: 'products priced',
      link: unpriced > 0 && ['/products?unpriced=1', 'Add prices'],
    },
    {
      title: 'Products with photos',
      text: 'Products with at least one photo. The first photo is the one shown in the shop.',
      value: goals.with_photos, total: goals.products, unit: 'have photos',
      link: ['/products', 'Open products'],
    },
    {
      title: 'Delivered this month',
      text: 'Orders this month that are marked Delivered (cancelled orders not counted).',
      value: goals.delivered_month, total: goals.orders_month, unit: 'delivered',
      link: ['/orders?status=contacted', 'Orders to deliver'],
    },
    {
      title: 'Featured picks',
      text: 'The home page shows up to 12 featured products. Tick Featured in Products to choose them.',
      value: Math.min(goals.featured, 12), total: 12, unit: 'of 12 slots used',
      link: ['/products?featured=1', 'See featured'],
    },
  ];
  const g = list[i];
  const pct = g.total ? Math.round((g.value / g.total) * 100) : 0;

  return (
    <section className="card" aria-roledescription="carousel" aria-label="Shop goals">
      <div className="goal">
        <div>
          <span className="small muted">Shop goal {i + 1} of {list.length}</span>
          <h3>{g.title}</h3>
          <p>{g.text}</p>
          <div className="actions" style={{ alignItems: 'center' }}>
            <div className="nav-arrows">
              <button type="button" onClick={() => setI((i + list.length - 1) % list.length)} aria-label="Previous goal"><Icon name="left" size={18} /></button>
              <button type="button" onClick={() => setI((i + 1) % list.length)} aria-label="Next goal"><Icon name="chevron" size={18} /></button>
            </div>
            {g.link && <Link to={g.link[0]} className="small">{g.link[1]} →</Link>}
          </div>
          <div className="dots" aria-hidden="true">{list.map((x, j) => <span key={x.title} className={j === i ? 'on' : ''} />)}</div>
        </div>
        <div>
          <Ring value={g.value} total={g.total} size={150} thickness={16} label={`${g.value}/${g.total}`} />
          <div className="goal-ring-label">{g.unit} <b>{pct}%</b></div>
        </div>
      </div>
    </section>
  );
}

/** Banner illustration: a wrapped bouquet with a rising chart line. */
function HeroArt() {
  return (
    <svg className="hero-art" viewBox="0 0 220 200" aria-hidden="true">
      <rect x="18" y="128" width="150" height="58" rx="10" fill="#fff" opacity=".18" />
      <polyline points="30,170 62,152 88,160 118,136 150,142" fill="none" stroke="#fff" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="150" cy="142" r="5" fill="#ffd166" />
      <path d="M118 112 L150 196 L182 112 Z" fill="#f4a3c4" />
      <path d="M118 112 L150 196 L150 112 Z" fill="#e8619a" />
      <path d="M150 112 V60 M150 104 C136 90 132 76 134 64 M150 104 C164 90 170 78 166 66" stroke="#2f9e6e" strokeWidth="3" fill="none" strokeLinecap="round" />
      {[
        [150, 58, '#fff'],
        [130, 70, '#ffd166'],
        [170, 70, '#ffb3cf'],
        [140, 94, '#ffffff'],
        [162, 92, '#ffd166'],
      ].map(([cx, cy, color], k) => (
        <g key={k} transform={`translate(${cx} ${cy})`}>
          {[0, 72, 144, 216, 288].map((a) => (
            <ellipse key={a} cx="0" cy="-7" rx="5" ry="7.5" fill={color} transform={`rotate(${a})`} />
          ))}
          <circle r="4" fill="#7353c9" />
        </g>
      ))}
      <circle cx="40" cy="40" r="9" fill="none" stroke="#ffd166" strokeWidth="3" />
      <circle cx="196" cy="34" r="6" fill="#fff" opacity=".6" />
      <circle cx="78" cy="96" r="4" fill="#fff" opacity=".7" />
      <path d="M58 64 l4 -10 l4 10 l10 4 l-10 4 l-4 10 l-4 -10 l-10 -4 z" fill="#fff" opacity=".85" />
    </svg>
  );
}
