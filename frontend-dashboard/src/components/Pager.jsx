import { useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';

export const PER_PAGE = 10;

/** Page numbers to show: always the first, the last and two either side of the current one, with … gaps. */
function pageList(page, pages) {
  const out = [];
  for (let n = 1; n <= pages; n++) {
    if (n === 1 || n === pages || Math.abs(n - page) <= 2) out.push(n);
    else if (out[out.length - 1] !== '…') out.push('…');
  }
  return out;
}

/** ‹ 1 … 4 5 6 … 12 › */
export default function Pager({ page, pages, onChange }) {
  if (pages <= 1) return null;
  const go = (n) => {
    onChange(n);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  return (
    <nav className="pager" aria-label="Pages">
      <button type="button" disabled={page <= 1} onClick={() => go(page - 1)} aria-label="Previous page">‹</button>
      {pageList(page, pages).map((n, i) =>
        n === '…' ? (
          <span key={`gap${i}`} className="gap">…</span>
        ) : (
          <button type="button" key={n} className={n === page ? 'active' : ''} aria-current={n === page ? 'page' : undefined} onClick={() => go(n)}>
            {n}
          </button>
        )
      )}
      <button type="button" disabled={page >= pages} onClick={() => go(page + 1)} aria-label="Next page">›</button>
    </nav>
  );
}

/**
 * Splits a list that is already loaded into pages of PER_PAGE, keeping the page in ?page= so it survives a reload.
 * A page past the end (the list shrank) falls back to the last page.
 */
export function usePaged(items) {
  const [params, setParams] = useSearchParams();
  const total = items?.length || 0;
  const pages = Math.max(1, Math.ceil(total / PER_PAGE));
  const asked = Math.max(1, Number(params.get('page')) || 1);
  const page = Math.min(asked, pages);

  const setPage = (n) => {
    const next = new URLSearchParams(params);
    if (n > 1) next.set('page', String(n));
    else next.delete('page');
    setParams(next);
  };

  useEffect(() => {
    if (items && asked !== page) setPage(page);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [items, asked, page]);

  return { page, pages, setPage, slice: (items || []).slice((page - 1) * PER_PAGE, page * PER_PAGE) };
}
