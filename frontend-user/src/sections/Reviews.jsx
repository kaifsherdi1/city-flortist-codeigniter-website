import { Link } from 'react-router-dom';
import { useState } from 'react';
import { useSite } from '../lib/site.jsx';
import { api } from '../lib/api.js';

/**
 * Customer reviews. Website reviews show once the shop approves them; the "Review us on Google" button
 * sends happy customers to the Google listing, which is what local search results are built on.
 */
export default function Reviews() {
  const { reviews = { summary: { count: 0 }, items: [] }, settings } = useSite();
  const [open, setOpen] = useState(false);
  const { count, average } = reviews.summary || {};
  const googleRating = parseFloat(settings.google_rating);

  return (
    <section className="reviews container" id="reviews" aria-labelledby="reviews-title">
      <div className="reviews-head">
        <div>
          <span className="eyebrow">Reviews</span>
          <h2 className="display" id="reviews-title">What customers say</h2>
        </div>
        <div className="reviews-scores">
          {count > 0 && (
            <div className="score">
              <Stars value={average} />
              <span><b>{average.toFixed(1)}</b> from {count} review{count === 1 ? '' : 's'} here</span>
            </div>
          )}
          {googleRating > 0 && (
            <a className="score" href={settings.map_link || settings.google_review_link} target="_blank" rel="noreferrer">
              <Stars value={googleRating} />
              <span><b>{googleRating.toFixed(1)}</b> on Google{settings.google_review_count ? ` · ${settings.google_review_count} reviews` : ''} ↗</span>
            </a>
          )}
        </div>
      </div>

      {reviews.items.length > 0 ? (
        <ul className="review-list">
          {reviews.items.map((r) => (
            <li className="review-card" key={r.id}>
              <Stars value={r.rating} />
              <p>“{r.text}”</p>
              <footer>
                <b>{r.name}</b>
                {r.verified && <span className="verified">✓ Verified order</span>}
                <time dateTime={r.date}>{new Date(r.date).toLocaleDateString('en-IN', { month: 'short', year: 'numeric' })}</time>
              </footer>
            </li>
          ))}
        </ul>
      ) : (
        <p className="muted reviews-empty">Ordered from us? Your words help other families in Hubballi choose their flowers.</p>
      )}

      <div className="reviews-actions">
        {settings.google_review_link && (
          <a className="btn btn-gold" href={settings.google_review_link} target="_blank" rel="noreferrer" data-cursor="Google">
            ★ Review us on Google
          </a>
        )}
        <button type="button" className="btn btn-outline" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
          {open ? 'Close' : 'Write a review here'}
        </button>
      </div>
      {open && <ReviewForm />}
    </section>
  );
}

function Stars({ value = 0, size = 18 }) {
  return (
    <span className="stars" role="img" aria-label={`${Number(value).toFixed(1)} out of 5 stars`} style={{ '--pct': `${(value / 5) * 100}%`, fontSize: size }}>
      <span aria-hidden="true">★★★★★</span>
    </span>
  );
}

function ReviewForm() {
  const [form, setForm] = useState({ name: '', rating: 0, text: '', phone: '', website: '' });
  const [status, setStatus] = useState('idle');
  const [message, setMessage] = useState('');
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function submit(e) {
    e.preventDefault();
    if (!form.rating) return setMessage('Please tap the stars to choose a rating.');
    setStatus('sending');
    setMessage('');
    try {
      const res = await api.review(form);
      setStatus('done');
      setMessage(res.message);
    } catch (err) {
      setStatus('idle');
      setMessage(err.offline ? 'Reviews can’t be sent from this copy of the site. Please review us on Google instead.' : err.message);
    }
  }

  if (status === 'done') return <div className="review-thanks" role="status">{message}</div>;

  return (
    <form className="review-form" onSubmit={submit} noValidate>
      <fieldset className="star-pick">
        <legend>Your rating</legend>
        {[1, 2, 3, 4, 5].map((n) => (
          <label key={n} className={n <= form.rating ? 'on' : ''}>
            <input type="radio" name="rating" value={n} checked={form.rating === n} onChange={() => setForm((f) => ({ ...f, rating: n }))} />
            <span aria-hidden="true">★</span>
            <span className="sr-only">{n} star{n > 1 ? 's' : ''}</span>
          </label>
        ))}
      </fieldset>
      <label className="field">
        <span>Your name</span>
        <input value={form.name} onChange={set('name')} autoComplete="name" maxLength={60} required placeholder="e.g. Priya Patil" />
      </label>
      <label className="field">
        <span>Your review</span>
        <textarea rows="3" value={form.text} onChange={set('text')} maxLength={1000} required placeholder="What did you order, and how was it?" />
      </label>
      <label className="field">
        <span>Mobile number <small>(optional, never shown: marks your review “Verified order” if you ordered on this site)</small></span>
        <div className="phone-input">
          <b>+91</b>
          <input value={form.phone} onChange={set('phone')} inputMode="tel" autoComplete="tel-national" placeholder="98765 43210" />
        </div>
      </label>
      <input className="hp" tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} aria-hidden="true" />
      {message && <div className="form-error" role="alert">{message}</div>}
      <button className="btn btn-gold" type="submit" disabled={status === 'sending'}>{status === 'sending' ? 'Sending…' : 'Send review'}</button>
      <p className="muted review-note">Reviews appear after the shop has seen them. Only your first name and initial are shown. See our <Link to="/privacy">Privacy Policy</Link>.</p>
    </form>
  );
}
