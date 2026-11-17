# City Florist · Hubballi

Website and shop-owner dashboard for City Florist: flower bouquets, chocolate bouquets, wedding car decoration, wedding-night room decoration, bridal garlands, teddy bears and cakes.

| Folder | What it is | Tech |
| --- | --- | --- |
| `frontend-user/` | Public website: 3D hero, scroll animations, shop, product pages, Buy → WhatsApp, customer accounts | React 19, Vite, Three.js (React Three Fiber), GSAP + ScrollTrigger, Lenis |
| `frontend-dashboard/` | Owner dashboard at `/admin`: products, photos, prices, daily offers, orders, reviews, settings | React 19 (mobile friendly), Vite |
| `backend/` | CodeIgniter app: public API (`/api`), dashboard API (`/api/admin`), customer accounts (`/api/account`), database migrations, seed photos, image processing | CodeIgniter 4.7, PHP 8.2+ (`intl`, `gd`, `mysqli`, `mbstring`), MySQL / MariaDB |

Three separate apps. Each React app has its own `package.json` and Vite build: `frontend-user` builds to `dist/index.html` + `dist/assets/`, `frontend-dashboard` to `dist/admin.html` + `dist/admin-assets/`. The deploy copies both into `backend/public/`, so the website, the dashboard (`/admin`) and the API (`/api`) are served from one domain and share the login cookies.

The backend was first written in Laravel. This CodeIgniter version answers every URL the same way (same JSON, same messages, same database tables), so the two React apps didn't change. See [Moving from the Laravel version](#moving-from-the-laravel-version).

## How ordering works

1. The customer taps **Buy** on any product.
2. A form asks for their name and mobile number, plus an optional date and message.
3. `POST /api/orders` saves the order. The server takes the price from the database, so it can't be changed in the browser.
4. The customer is sent to `wa.me/<owner number>` with the full order already typed: order number, product, category, price, name, phone, and the date and note if given. They press **Send**.
5. The order also appears in **Admin → Orders**, so the owner can follow up even if the customer never pressed Send.

## Local development

You need PHP 8.2+ (with `intl`, `gd`, `mysqli`, `mbstring`), Composer, Node 20+ and **MySQL or MariaDB** (XAMPP's MySQL is fine). The app is MySQL-only, the same as on Hostinger.

**XAMPP:** CodeIgniter needs the `intl` extension, which XAMPP leaves switched off. In `xampp/php/php.ini` remove the `;` before `extension=intl`, save, and restart Apache from the XAMPP Control Panel. `php -m` should now list `intl`.

```bash
# 0. Two databases: one for the site, one the tests wipe and refill
mysql -u root -e "CREATE DATABASE cityflorist_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE DATABASE cityflorist_ci_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 1. Backend (CodeIgniter)
cd backend
composer install
cp .env.example .env            # then set database.default.* and database.tests.* for your MySQL (XAMPP is often port 3307)
php spark key:generate          # writes encryption.key into .env
php spark florist:install       # tables, admin login, categories, the starter photos
php spark serve --host 127.0.0.1 --port 8000   # → http://127.0.0.1:8000 (API); `composer dev` does the same

# 2. Website, in a second terminal
cd frontend-user
npm install
npm run dev                     # → http://localhost:5173

# 3. Dashboard, in a third terminal
cd frontend-dashboard
npm install
npm run dev                     # → http://localhost:5174/admin
```

There is no separate `php spark migrate` step: `florist:install` runs the migrations itself. Keep `--host 127.0.0.1 --port 8000` on `php spark serve` (on its own it uses port 8080): both Vite servers forward `/api` and `/uploads` to the backend at 127.0.0.1:8000, and the website's server also passes `/admin` to the dashboard, so **http://localhost:5173/admin** opens the dashboard too, just like on the live site. Instead of `florist:install` you can open `/admin` and press **Install**.

Default local admin login: `admin` / `ChangeMe@123` (from `florist.adminUsername` / `florist.adminPassword` in `.env`). The first login with it asks for a new password before the dashboard opens.

Backend tests: `cd backend && composer test` (they use the `cityflorist_ci_test` database, never `cityflorist_ci`; see [backend/tests/README.md](backend/tests/README.md)).

### API

| Route | Use |
| --- | --- |
| `GET /api/site` | Everything the home page needs |
| `GET /api/products` · `?cat=<slug>` · `?slug=<slug>` | Shop list, one category, one product + related |
| `POST /api/orders` | Save an order, returns the WhatsApp link |
| `POST /api/reviews` | A customer review; it shows on the site once the owner approves it |
| `/api/account/*` | Customer accounts on the website: register, log in, order history |
| `/api/admin/*` | Dashboard: session login with a CSRF token from the `XSRF-TOKEN` cookie (see [backend/app/Config/Routes.php](backend/app/Config/Routes.php)) |

## Hosting on Hostinger (auto-deploy from GitHub)

This works on any Hostinger web hosting plan (Premium, Business or Cloud). Every push to `main` runs the backend tests, builds both React apps, installs CodeIgniter's dependencies and uploads everything over FTP with GitHub Actions ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)). The whole CodeIgniter app goes into `public_html/`. A `.htaccess` there sends every request into the app's `public/` folder, so `.env`, `app/`, `vendor/` and `writable/` are never reachable from the web.

### One-time setup

1. **Use PHP 8.2 or newer.** hPanel → Advanced → PHP Configuration. Under PHP extensions, make sure `intl` is ticked (CodeIgniter won't start without it), along with `gd`, `mysqli` and `mbstring`.
2. **Create the database.** hPanel → Databases → MySQL Databases: create a database and user, and note the name, username and password (they look like `u123456789_florist`).
3. **Get the FTP details.** hPanel → Files → FTP Accounts: note the FTP host, username and password.
4. **Add GitHub secrets.** In the repository: Settings → Secrets and variables → Actions → New repository secret:
   - `FTP_SERVER`: the FTP host
   - `FTP_USERNAME`: the FTP username
   - `FTP_PASSWORD`: the FTP password
   - `FTP_SERVER_DIR` (optional): the folder to deploy into. The default is `public_html/`. If your FTP account already opens inside `public_html`, set this to `./`.
5. **Push to `main`.** The first upload includes CodeIgniter's `vendor/` folder (about 800 files), so it takes a few minutes; later deploys only send changed files. Watch it under the repository's **Actions** tab.
6. **Create `.env` on the server.** In hPanel File Manager, open `public_html`, copy [`.env.production.example`](backend/.env.production.example) to `.env`, and fill in every `<…>` value:
   - `encryption.key`: generate one on your computer with `cd backend && php spark key:generate --show`. Keep a private copy: two-step login secrets are encrypted with it. (Coming from the Laravel version? Use its key instead, see below.)
   - `app.baseURL` (`https://yourdomain.com/`, with the slash) and `florist.siteUrl` (`https://yourdomain.com`)
   - the MySQL details from step 2 (`database.default.*`)
   - `florist.adminUsername` and a long `florist.adminPassword`. The installer refuses the example password and anything under 10 characters.
   - the mail settings (`florist.mailer = smtp` and the `email.*` lines; keep `email.SMTPCrypto = ssl` for port 465), so "Forgot password?" can send reset emails
7. **Enable SSL** for the domain (hPanel → Security → SSL) before installing, so the first login is already encrypted.
8. **Install.** Visit `https://yourdomain.com/admin` and press **Install**. This creates the tables, the admin login and the starter products. It locks itself afterwards.
9. **First login.** You're asked to choose a new password straight away. Then, in **Settings**: set the WhatsApp number, phone, address, hours and Google Maps link; save the owner's email and mobile in **Login details**; and turn on **Two-step login**.
10. **Run through the [launch checklist](#launch-checklist).**

From then on, every `git push` to `main` updates the live site. Product photos (`public/uploads/`), `.env`, and the sessions, logs and cache in `writable/` on the server are never overwritten. Database changes in new versions are applied automatically the next time the dashboard is opened, so no SSH is needed.

### Moving from the Laravel version

This backend replaces the Laravel one without touching the data:

- **The same database works unchanged.** The tables are the same, so point `database.default.*` at the Laravel site's database. CodeIgniter keeps its own migration history in a new `ci_migrations` table: its migrations find every table and column already there and adopt them as they are, and Laravel's `migrations` table is left alone.
- **Use the Laravel key:** set `encryption.key = base64:…` to the Laravel `APP_KEY`, copied whole, `base64:` included. Two-step login secrets and backup codes were encrypted with it, so they keep working. With a new key, two-step login would have to be turned off (`php spark florist:admin-recover <username> --two-factor-off`) and set up again.
- **Photos:** they live in `public/uploads/` in both versions. On Hostinger they stay where they are (`public_html/public/uploads/`; deploys never touch that folder). Locally, copy the Laravel project's `backend/public/uploads/` into this `backend/public/`.
- **Everyone logs in again once.** Sessions and "remember me" cookies are CodeIgniter's own now. Passwords, accounts, orders and settings are unchanged.
- **`.env` has new names** for the same settings (see [`.env.production.example`](backend/.env.production.example)):

| Laravel `.env` | CodeIgniter `.env` |
| --- | --- |
| `APP_ENV=production`, `APP_DEBUG=false` | `CI_ENVIRONMENT = production` |
| `APP_KEY` | `encryption.key` (the same `base64:…` value) |
| `APP_URL` | `app.baseURL` (with a trailing `/`) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `database.default.hostname`, `.port`, `.database`, `.username`, `.password` |
| `FLORIST_SITE_URL`, `FLORIST_WHATSAPP` | `florist.siteUrl`, `florist.whatsappNumber` |
| `FLORIST_ADMIN_USERNAME`, `_PASSWORD`, `_EMAIL`, `_PHONE` | `florist.adminUsername`, `florist.adminPassword`, `florist.adminEmail`, `florist.adminPhone` |
| `SESSION_LIFETIME=720` (minutes) | `session.expiration = 43200` (seconds) |
| `TRUSTED_PROXIES` | `florist.trustedProxies` |
| `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME=smtps` | `florist.mailer = smtp`, `email.SMTPHost`, `email.SMTPPort`, `email.SMTPCrypto = ssl` |
| `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `email.SMTPUser`, `email.SMTPPass`, `email.fromEmail`, `email.fromName` |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | `florist.googleClientId`, `florist.googleClientSecret` |

On a live Hostinger site:

1. Make the new `.env` on your computer from `.env.production.example`, with the values from the Laravel `.env` (table above).
2. Push this version to `main`. As soon as the upload finishes, replace `public_html/.env` with the new one: until then the site runs CodeIgniter with the Laravel settings, which it can't read.
3. Open `/admin` and log in. Nothing needs installing.
4. If the Laravel version deployed to the same folder, this deploy deletes the Laravel files and folders that deploy had uploaded (`bootstrap/`, `config/`, `database/`, `routes/`, `storage/`, `artisan` …). Delete anything else left over in File Manager; none of it is reachable from the web.

### Moving a live site from the old PHP version

If the site was still running the PHP version from before Laravel, the next deploy replaces it. The data carries over:

1. Create `.env` as in step 6, using the database details from the old `config.php`.
2. In File Manager, move the old `public_html/uploads/products` (and `offers`, `categories`, if they exist) into `public_html/public/uploads/`.
3. Open `/admin` and log in with the existing username and password. The existing tables, products, orders and settings are adopted as they are; nothing needs reinstalling.
4. Delete the old `config.php`, `includes/`, `api/` and `admin/` folders if they are still there (they're no longer reachable, but there's no reason to keep them).

### Hostinger notes
- The `.htaccess` forces HTTPS, so SSL must be enabled for the domain.
- If something fails, read `public_html/writable/logs/log-<date>.log` first. Only set `florist.debug = true` in `.env` for a minute if you must, because API errors then show their details (file paths, database errors) to every visitor, and remove it straight away. Never change `CI_ENVIRONMENT` from `production` on the live site.
- CodeIgniter writes one log file a day and never deletes them; clear out old ones in `writable/logs/` now and then.

### Launch checklist

Before handing over, on the live site:

- [ ] `https://` works and `http://` redirects to it
- [ ] `.env` has `CI_ENVIRONMENT = production` and a real `encryption.key`, and a copy of the key is kept somewhere safe
- [ ] `https://yourdomain.com/.env`, `/writable/logs/` and `/vendor/` never show those files (you get the website's "page not found", or a 403/404)
- [ ] Logged in, chose a new password, turned on two-step login, and saved the backup codes
- [ ] Owner's email and mobile saved in **Settings → Login details**; "Forgot password?" sends an email
- [ ] WhatsApp number in **Settings** is the owner's real one; a test order arrives on that phone
- [ ] Demo prices from `florist:demo-data` reviewed by the owner (Admin → Products)
- [ ] Google Maps link points to the shop
- [ ] A backup of the database (hPanel → Databases → phpMyAdmin → Export) and of `public/uploads/` is taken

## Security

| Protection | How |
| --- | --- |
| Dashboard login | Session cookie (HttpOnly, SameSite=Lax, Secure on the live site), started by the `session` filter. Every change must send the session's CSRF token in the `X-XSRF-TOKEN` header (the browser reads it from the `XSRF-TOKEN` cookie; `App\Libraries\Auth\SessionState`), otherwise it gets 419. The session id and the CSRF token are renewed at login. Session files in `writable/session` are encrypted with `encryption.key` |
| Passwords | bcrypt, the same hashes as the Laravel version. The starting password must be changed before anything else opens (the `admin` filter answers 403 until then). Changing it signs out every other device and "remember me" cookie |
| Two-step login (optional) | Authenticator-app codes (RFC 6238), each accepted once; 10 one-time backup codes stored as hashes; the secret is encrypted with `encryption.key` (`App\Libraries\Crypto`: AES-256-CBC + HMAC-SHA256, the format Laravel used, so Laravel-era secrets still open) |
| Guessing | 8 wrong passwords per device or per account → 15-minute lock; 6 wrong codes → 15-minute lock; reset emails throttled. Counted by `App\Libraries\RateLimiter` in `writable/cache`. The per-account counter follows the account itself, so typing the same login another way (`ADMIN`, `+91 98765 43210`, the email) doesn't reset it |
| Visitor IP | `REMOTE_ADDR`. Only with `florist.trustedProxies` set is `X-Forwarded-For` read, from the right and skipping those proxies (`App\Libraries\ClientIp`), so a visitor can't pick their own IP to dodge the limits |
| Google sign-in | Only verified Google emails. When "Continue with Google" joins a customer account that was made with a password, that password stops working (the email was never checked at sign-up, so it may have been someone else's) |
| Installer | Locked once an admin exists; on the live site (`CI_ENVIRONMENT = production`) it refuses the example password |
| Prices | Always taken from the database; whatever the browser sends is ignored |
| Uploads | Every photo is decoded and re-saved as WebP (GD) under a random name, so a disguised file can't run. Scripts are blocked in `uploads/` |
| Customer input | Orders: honeypot field, 10 per device per 30 minutes. CSV export neutralises Excel formulas |
| Browser | Content-Security-Policy (only the site's own scripts run), HSTS, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy: `public/.htaccess` sets them on files Apache serves, the `securityheaders` filter on everything CodeIgniter answers. The dashboard is marked `noindex` |
| Public API | 240 requests a minute per device (the `apithrottle` filter) |
| Server files | `.env`, `app/`, `vendor/`, `writable/` and the database sit outside the web root |

The filters are in `backend/app/Filters/`; `backend/app/Config/Routes.php` attaches them to the routes (`securityheaders` runs on every answer, see `backend/app/Config/Filters.php`). Tests: `cd backend && composer test` (258 tests: the Laravel version's 74 tests ported one by one, plus endpoint-by-endpoint tests covering login, two-step, uploads, offers and the attacks above; see [backend/tests/README.md](backend/tests/README.md)).

## Hosting the website on Vercel (no PHP)
Vercel only hosts the React website; it can't run PHP, so the dashboard isn't there (`/admin` leads back to the home page). The project's Root Directory can be the repo root ([vercel.json](vercel.json)) or `frontend-user` ([frontend-user/vercel.json](frontend-user/vercel.json)); both are configured the same way. The site carries a snapshot of the catalogue (data in `frontend-user/src/catalog/`, photos in `frontend-user/public/catalog/`). Vercel builds with `VITE_STATIC_CATALOG=1`, so it uses the snapshot without calling `/api`. Elsewhere the snapshot is only used when `/api` can't be reached. Buy then opens WhatsApp with the order details, but orders aren't saved to Admin → Orders.

The snapshot doesn't update by itself. After changing products, prices or settings locally, run this, then commit and push:

```bash
cd backend && php spark florist:export-static
```

## Owner's daily tasks
- **Add a product:** Admin → Products → **+ Add product**. Add a name, category, price and photos (you can pick several straight from the phone).
- **Price the starter products quickly:** Admin → Products → tick **Only without price**, type the prices in the list, then **Save all changes**. Products at ₹0 show “Ask for price”.
- **Today’s discount:** Admin → Daily Offers. Choose a %, what it applies to (everything / a category / one product) and **Today only**. Prices drop automatically and the offer appears in the gold banner with a countdown. It switches off by itself when it ends.
- **Ready to go (home page ring):** Admin → Products → choose **Flower Bouquets**, tick **Ready to go** on the 4–6 bouquets made up in the shop today, then **Save all changes**. Until you choose, the first five bouquets are shown.
- **Wholesale flowers:** loose flowers sold in bulk are the **Wholesale Flowers** category. They get their own section on the home page and never show a price (market rates change daily), so customers ask on WhatsApp. Add or edit them like any product.
- **Orders:** Admin → Orders. Tap **WhatsApp** to reply, change the status, or download a CSV.

## Maintenance commands (run in `backend/`)

| Command | What it does |
| --- | --- |
| `php spark florist:install` | First-time setup, the same as the dashboard's Install screen (`--no-seed` skips the starter photos). On a site that is already installed it only runs new migrations |
| `php spark florist:demo-data` | Imports starter photos that aren't products yet and gives every product still at ₹0 a typical Hubballi price (about 1 in 4 on sale). Prices the owner has set are never changed, and running it again changes nothing. **These are demo prices**: the owner should review them in Admin → Products before launch. |
| `php spark florist:export-static` | Refreshes the catalogue snapshot used on Vercel (see above) |
| `php spark florist:admin-recover admin --two-factor-off` | Owner lost their phone and backup codes: turns two-step login off |
| `php spark florist:admin-recover admin --reset-password` | Owner forgot the password and has no email saved: prints a temporary password and signs out every device |

`php spark list` shows them all under **City Florist**.

The starter photos live in `backend/app/Database/Seeds/starter/` (WebP, listed in `manifest.json`). To add a starter photo, put the WebP and its `-thumb.webp` in the category folder, add it to the manifest, and run `florist:demo-data`.

The Room Decoration photos in `backend/app/Database/Seeds/starter/room-decoration/` are free stock placeholders (see its `CREDITS.md`), labelled on the site as representative photos. Replace them with photos of real setups when possible.
