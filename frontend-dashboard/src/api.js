// Client for the CodeIgniter dashboard API (/api/admin/...). Login is a session cookie; every change
// sends the server's CSRF token back in the X-XSRF-TOKEN header, read from the XSRF-TOKEN cookie.

let onAuthLost = () => {};
/** Called when the server says the session has ended, so the app can show the login screen. */
export const setAuthLostHandler = (fn) => {
  onAuthLost = fn;
};

function xsrfToken() {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : '';
}

function failure(message, status = 0, extra = {}) {
  return Object.assign(new Error(message), { status, ...extra });
}

async function send(path, { method = 'GET', body, form } = {}) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (method !== 'GET') headers['X-XSRF-TOKEN'] = xsrfToken();
  let payload;
  if (form) {
    payload = form;
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  let res;
  try {
    res = await fetch('/api/admin/' + path, { method, headers, body: payload, credentials: 'same-origin' });
  } catch {
    throw failure('Cannot reach the shop server. Check the internet connection and try again.');
  }
  if (!(res.headers.get('content-type') || '').includes('application/json')) {
    // e.g. Vercel, which only hosts the website: there is no shop server (CodeIgniter) behind /api.
    throw failure('The dashboard needs the shop server (CodeIgniter), which isn’t running on this host.', res.status, { offline: true });
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw failure(data.error || 'Something went wrong. Please try again.', res.status);
  return data;
}

export async function request(path, options = {}) {
  try {
    return await send(path, options);
  } catch (err) {
    // CSRF token expired (e.g. the tab was left open overnight): fetch a fresh one and try once more.
    if (err.status === 419) {
      await send('status');
      return send(path, options);
    }
    if (err.status === 401 && path !== 'login') onAuthLost();
    throw err;
  }
}

export const get = (path) => request(path);
export const post = (path, body) => request(path, { method: 'POST', body });
export const put = (path, body) => request(path, { method: 'PUT', body });
export const patch = (path, body) => request(path, { method: 'PATCH', body });
export const del = (path) => request(path, { method: 'DELETE' });
export const upload = async (path, form) => request(path, { method: 'POST', form: await shrinkPhotos(form) });

/**
 * Phone photos are often 4–10 MB. Before uploading, anything over 1.5 MB is redrawn at most
 * 2400 px wide as a JPEG (the server makes a 1600 px WebP from it anyway), so uploads are quick on
 * mobile data and stay under the server's size limit. If the browser can't read a photo, the
 * original is sent and the server decides.
 */
async function shrinkPhotos(form) {
  const out = new FormData();
  for (const [key, value] of form.entries()) {
    out.append(key, value instanceof File ? await shrink(value) : value);
  }
  return out;
}

async function shrink(file, max = 2400) {
  if (!file.type.startsWith('image/') || file.size <= 1.5 * 1024 * 1024 || !window.createImageBitmap) return file;
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
    return blob && blob.size < file.size ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
  } catch {
    return file;
  }
}

export const rupees = (n) => {
  const v = Number(n) || 0;
  return '₹' + v.toLocaleString('en-IN', { maximumFractionDigits: v % 1 ? 2 : 0 });
};

const pad = (n) => String(n).padStart(2, '0');
/** Date → value for <input type="datetime-local">. */
export const localInput = (d) =>
  `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

export const formatDate = (value, withTime = true) => {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value || '';
  return d.toLocaleString('en-IN', {
    day: '2-digit',
    month: 'short',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : { year: 'numeric' }),
  });
};
