/** Page numbers to show: the first, the last and one either side of the current one, with … gaps. */
function pageList(page, pages) {
  const out = [];
  for (let n = 1; n <= pages; n++) {
    if (n === 1 || n === pages || Math.abs(n - page) <= 1) out.push(n);
    else if (out[out.length - 1] !== '…') out.push('…');
  }
  return out;
}

/** ← 1 … 4 5 6 … 12 → */
export default function Pager({ page, pages, onChange }) {
  if (pages <= 1) return null;
  return (
    <nav className="pager" aria-label="Pages">
      <button type="button" className="pager-step" disabled={page <= 1} onClick={() => onChange(page - 1)} aria-label="Previous page" data-cursor="Back">
        ←
      </button>
      {pageList(page, pages).map((n, i) =>
        n === '…' ? (
          <span key={`gap${i}`} className="pager-gap">…</span>
        ) : (
          <button
            type="button"
            key={n}
            className={n === page ? 'active' : ''}
            aria-current={n === page ? 'page' : undefined}
            onClick={() => onChange(n)}
          >
            {n}
          </button>
        )
      )}
      <button type="button" className="pager-step" disabled={page >= pages} onClick={() => onChange(page + 1)} aria-label="Next page" data-cursor="Next">
        →
      </button>
    </nav>
  );
}
