# Backend tests

PHPUnit feature tests for the City Florist API. They call the routes the way the React apps do: the session and
cookies carry over from one request to the next, and every change sends the CSRF token in the `X-XSRF-TOKEN` header.

## Running them

In `backend/`:

```bash
composer test                       # every test
vendor/bin/phpunit --filter Login   # only the tests whose name matches
```

- They use the `cityflorist_ci_test` database (the `database.tests.*` lines in `.env`), never the site's own
  `cityflorist_ci`. Create it once:
  `CREATE DATABASE cityflorist_ci_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
- The first test of a run drops every table in it and runs the migrations; each test after that starts from empty
  tables. `TEST_DB=<name> composer test` uses another database for one run.
- `.env` needs an `encryption.key` (`php spark key:generate`): the login routes refuse to work without one.
- PHP needs the `intl` extension. XAMPP: remove the `;` before `extension=intl` in `xampp/php/php.ini`.
- Photos go to a temporary folder (never `public/uploads/`) and emails are caught, never sent.

## What's here (238 tests)

| Folder / file | What it covers |
| --- | --- |
| `tests/Feature/LoginTest.php`, `TwoFactorTest.php`, `SecurityTest.php`, `ErrorResponseTest.php`, `ShopTest.php`, `CatalogAdminTest.php`, `ReviewTest.php` | The Laravel version's tests, ported one by one (same names, same checks); its customer-account tests went when the website dropped customer accounts |
| `tests/Feature/FoundationTest.php` | The plumbing: JSON errors, CSRF, security headers, rate limits, the tables, Laravel-compatible encryption |
| `tests/Feature/HardeningTest.php` | Protections added after the security review of the port (client IP behind a proxy, login locks per account) |
| `tests/Feature/ShareTest.php` | The product link in WhatsApp order messages (`/p/<slug>`) and its preview photo |
| `tests/Feature/Detailed/` | Endpoint-by-endpoint tests written during the port: every route's answers, messages and error paths, and the `florist:*` commands |

## Writing one

Tests live in `tests/Feature/` (namespace `Tests\Feature`) and extend `Tests\Support\FeatureTestCase`
(`tests/_support/FeatureTestCase.php`), which has `install()`, `getJson()` / `postJson()` / `postMultipart()`,
`actingAsAdmin()`, `assertJsonPath()`, `emails()` and more. `tests/Feature/FoundationTest.php`
shows the style.
