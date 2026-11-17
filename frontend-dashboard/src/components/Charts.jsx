import { useState } from 'react';

// Small hand-rolled SVG charts for the dashboard home. Colours are CSS variables from admin.css
// (--primary, --pink, --yellow, --neutral), validated together for colour-blind separation.

/** A "nice" axis maximum and step for 4–5 gridlines. */
function niceScale(max) {
  if (max <= 0) return { top: 4, step: 1 };
  const raw = max / 4;
  const mag = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw);
  return { top: Math.ceil(max / step) * step, step };
}

/** Rounded top corners (4px), flat on the baseline. */
function barPath(x, y, w, h, r = 4) {
  if (h <= 0) return '';
  const rr = Math.min(r, w / 2, h);
  return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`;
}

/**
 * Vertical bars, one series. data: [{ key, label, value, tip }]
 * `tip` is the tooltip text; `label` the axis label (empty to skip).
 */
export function BarChart({ data, height = 260, format = (v) => v, emptyText = 'No orders in this period yet' }) {
  const [hover, setHover] = useState(null);
  const W = 640;
  const H = height;
  const pad = { top: 12, right: 8, bottom: 28, left: 36 };
  const innerW = W - pad.left - pad.right;
  const innerH = H - pad.top - pad.bottom;
  const max = Math.max(0, ...data.map((d) => d.value));
  const { top, step } = niceScale(max);
  const slot = innerW / data.length;
  const barW = Math.min(22, slot * 0.55);
  const y = (v) => pad.top + innerH - (v / top) * innerH;
  const ticks = [];
  for (let t = 0; t <= top + 1e-9; t += step) ticks.push(t);
  const labelEvery = data.length > 14 ? Math.ceil(data.length / 8) : 1;

  return (
    <div className="chart">
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Bar chart">
        <g className="grid">
          {ticks.map((t) => (
            <line key={t} x1={pad.left} x2={W - pad.right} y1={y(t)} y2={y(t)} strokeDasharray={t === 0 ? undefined : '3 4'} />
          ))}
        </g>
        <g className="axis">
          {ticks.map((t) => (
            <text key={t} x={pad.left - 8} y={y(t) + 4} textAnchor="end">{format(t)}</text>
          ))}
          {data.map((d, i) =>
            i % labelEvery === 0 || i === data.length - 1 ? (
              <text key={d.key} x={pad.left + slot * i + slot / 2} y={H - 8} textAnchor="middle">{d.label}</text>
            ) : null
          )}
        </g>
        {data.map((d, i) => {
          const x = pad.left + slot * i + (slot - barW) / 2;
          const h = (d.value / top) * innerH;
          return (
            <g key={d.key}>
              <path className={`bar ${hover === i ? 'hover' : ''}`} d={barPath(x, y(d.value), barW, h)} fill="var(--primary)" />
              <rect
                className="hit"
                x={pad.left + slot * i}
                y={pad.top}
                width={slot}
                height={innerH}
                onMouseEnter={() => setHover(i)}
                onMouseLeave={() => setHover(null)}
                onFocus={() => setHover(i)}
                onBlur={() => setHover(null)}
                tabIndex={0}
                aria-label={d.tip}
              />
            </g>
          );
        })}
        {hover !== null && (
          <line x1={pad.left + slot * hover + slot / 2} x2={pad.left + slot * hover + slot / 2} y1={pad.top} y2={pad.top + innerH} stroke="var(--primary)" strokeOpacity=".15" strokeWidth={slot} pointerEvents="none" />
        )}
      </svg>
      {max === 0 && <div className="chart-empty">{emptyText}</div>}
      {hover !== null && (
        <div className="tip" style={{ left: `${((pad.left + slot * hover + slot / 2) / W) * 100}%`, top: `${(y(data[hover].value) / H) * 100}%` }}>
          {data[hover].tip}
        </div>
      )}
    </div>
  );
}

/**
 * Two series side by side per bucket, on one shared axis (both are order counts).
 * data: [{ key, label, values: [a, b], tip }]; series: [{ label, color }, { label, color }]
 */
export function GroupedBarChart({ data, series, height = 260, emptyText = 'No orders in this period yet' }) {
  const [hover, setHover] = useState(null);
  const W = 640;
  const H = height;
  const pad = { top: 12, right: 8, bottom: 28, left: 32 };
  const innerW = W - pad.left - pad.right;
  const innerH = H - pad.top - pad.bottom;
  const max = Math.max(0, ...data.flatMap((d) => d.values));
  const { top, step } = niceScale(max);
  const slot = innerW / data.length;
  const barW = Math.max(3, Math.min(12, (slot * 0.62 - 2) / 2));
  const y = (v) => pad.top + innerH - (v / top) * innerH;
  const ticks = [];
  for (let t = 0; t <= top + 1e-9; t += step) ticks.push(t);
  const labelEvery = data.length > 14 ? Math.ceil(data.length / 8) : 1;

  return (
    <div className="chart">
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`${series[0].label} and ${series[1].label} per period`}>
        <g className="grid">
          {ticks.map((t) => (
            <line key={t} x1={pad.left} x2={W - pad.right} y1={y(t)} y2={y(t)} strokeDasharray={t === 0 ? undefined : '3 4'} />
          ))}
        </g>
        <g className="axis">
          {ticks.map((t) => <text key={t} x={pad.left - 8} y={y(t) + 4} textAnchor="end">{t}</text>)}
          {data.map((d, i) =>
            d.label && (i % labelEvery === 0 || i === data.length - 1) ? (
              <text key={d.key} x={pad.left + slot * i + slot / 2} y={H - 8} textAnchor="middle">{d.label}</text>
            ) : null
          )}
        </g>
        {hover !== null && (
          <rect x={pad.left + slot * hover} y={pad.top} width={slot} height={innerH} rx="8" fill="var(--primary)" opacity=".07" pointerEvents="none" />
        )}
        {data.map((d, i) => {
          const cx = pad.left + slot * i + slot / 2;
          return (
            <g key={d.key}>
              {d.values.map((v, j) => {
                // 2px surface gap between the pair.
                const x = j === 0 ? cx - 1 - barW : cx + 1;
                return <path key={j} d={barPath(x, y(v), barW, (v / top) * innerH)} fill={series[j].color} />;
              })}
              <rect
                className="hit"
                x={pad.left + slot * i}
                y={pad.top}
                width={slot}
                height={innerH}
                tabIndex={0}
                aria-label={d.tip}
                onMouseEnter={() => setHover(i)}
                onMouseLeave={() => setHover(null)}
                onFocus={() => setHover(i)}
                onBlur={() => setHover(null)}
              />
            </g>
          );
        })}
      </svg>
      {max === 0 && <div className="chart-empty">{emptyText}</div>}
      {hover !== null && (
        <div className="tip" style={{ left: `${((pad.left + slot * hover + slot / 2) / W) * 100}%`, top: `${(y(Math.max(...data[hover].values)) / H) * 100}%` }}>
          <div style={{ marginBottom: 4 }}><b>{data[hover].title}</b></div>
          {series.map((s, j) => (
            <div key={s.label} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <span className="swatch" style={{ background: s.color }} /> {s.label}: <b>{data[hover].values[j]}</b>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/** Tiny bars for a stat card (no axes; the card's number is the headline). */
export function MiniBars({ values, color = 'var(--primary)', width = 96, height = 44 }) {
  const max = Math.max(1, ...values);
  const slot = width / values.length;
  const w = Math.max(3, Math.min(6, slot * 0.5));
  return (
    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} aria-hidden="true">
      {values.map((v, i) => {
        const h = Math.max(2, (v / max) * (height - 2));
        return <path key={i} d={barPath(slot * i + (slot - w) / 2, height - h, w, h, 3)} fill={v ? color : 'var(--line)'} />;
      })}
    </svg>
  );
}

/** Tiny line for a stat card. */
export function MiniLine({ values, color = 'var(--pink)', width = 96, height = 44 }) {
  const max = Math.max(1, ...values);
  const step = (width - 4) / Math.max(1, values.length - 1);
  const pts = values.map((v, i) => [2 + i * step, height - 3 - (v / max) * (height - 6)]);
  const d = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join('');
  return (
    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} aria-hidden="true">
      <path d={`${d}L${width - 2},${height}L2,${height}Z`} fill={color} opacity=".1" />
      <path d={d} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/** Donut with a 2px surface gap between segments. parts: [{ key, label, value, color }] */
export function Donut({ parts, size = 150, thickness = 18, centerLabel }) {
  const [hover, setHover] = useState(null);
  const total = parts.reduce((s, p) => s + p.value, 0);
  const r = (size - thickness) / 2;
  const c = 2 * Math.PI * r;
  const gap = total > 0 && parts.filter((p) => p.value > 0).length > 1 ? 2 : 0;
  let offset = 0;
  const active = hover !== null ? parts[hover] : null;

  return (
    <div className="donut" style={{ width: size }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={parts.map((p) => `${p.label}: ${p.value}`).join(', ')}>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--card-2)" strokeWidth={thickness} />
        {total > 0 &&
          parts.map((p, i) => {
            const len = (p.value / total) * c;
            const seg = (
              <circle
                key={p.key}
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke={p.color}
                strokeWidth={hover === i ? thickness + 4 : thickness}
                strokeDasharray={`${Math.max(0, len - gap)} ${c}`}
                strokeDashoffset={-offset}
                transform={`rotate(-90 ${size / 2} ${size / 2})`}
                onMouseEnter={() => setHover(i)}
                onMouseLeave={() => setHover(null)}
                style={{ transition: 'stroke-width .15s', cursor: 'default' }}
              />
            );
            offset += len;
            return p.value > 0 ? seg : null;
          })}
      </svg>
      <div className="center" aria-hidden="true">
        <b>{active ? active.value : total}</b>
        <span>{active ? active.label : centerLabel}</span>
      </div>
    </div>
  );
}

/** One value out of a total, as a ring. */
export function Ring({ value, total, size = 150, thickness = 14, label }) {
  const pct = total > 0 ? Math.round((value / total) * 100) : 0;
  const r = (size - thickness) / 2;
  const c = 2 * Math.PI * r;
  return (
    <div className={`donut ${size < 100 ? 'sm' : ''}`} style={{ width: size }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={`${label}: ${value} of ${total} (${pct}%)`}>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--primary-soft)" strokeWidth={thickness} />
        {pct > 0 && <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke="var(--primary)"
          strokeWidth={thickness}
          strokeLinecap="round"
          strokeDasharray={`${(pct / 100) * c} ${c}`}
          transform={`rotate(-90 ${size / 2} ${size / 2})`}
        />}
      </svg>
      <div className="center" aria-hidden="true">
        <b>{pct}%</b>
        <span>{label}</span>
      </div>
    </div>
  );
}
