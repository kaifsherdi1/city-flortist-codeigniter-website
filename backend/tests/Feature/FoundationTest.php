<?php

namespace Tests\Feature;

use App\Libraries\Crypto;
use App\Libraries\Installer;
use App\Libraries\RateLimiter;
use App\Libraries\Settings;
use CodeIgniter\Test\Mock\MockCache;
use Config\Encryption;
use Tests\Support\FeatureTestCase;

/**
 * The plumbing every endpoint relies on: JSON errors, CSRF, security headers, rate limits, the database layout
 * and encryption that is compatible with the Laravel version.
 *
 * @internal
 */
final class FoundationTest extends FeatureTestCase
{
    public function test_unknown_api_paths_answer_json_404_and_wrong_methods_405(): void
    {
        $res = $this->getJson('/api/nothing-here');
        $this->assertStatusCode($res, 404);
        $this->assertJsonPath($res, 'error', 'Not found.');
        $this->assertSame('no-cache, private', $res->response()->getHeaderLine('Cache-Control'));

        // Laravel's catch-all route took every GET, so a GET on a POST-only path is a 404 there too.
        $this->assertStatusCode($this->getJson('/api/orders'), 404);

        $res = $this->deleteJson('/api/site');
        $this->assertStatusCode($res, 405);
        $this->assertJsonPath($res, 'error', 'Method not allowed.');
    }

    public function test_health_page_missing_photo_and_redirect_pages_match_laravel(): void
    {
        $res = $this->get('/up');
        $this->assertStatusCode($res, 200);
        $this->assertStringContainsString('Application up', (string) $res->response()->getBody());
        $this->assertStringNotContainsString('{{ms}}', (string) $res->response()->getBody());

        $res = $this->get('/uploads/products/no-such-photo.webp');
        $this->assertStatusCode($res, 404);
        $this->assertStringContainsString('<title>Not Found</title>', (string) $res->response()->getBody());

        $this->install();
        $res = $this->getJson('/api/admin/oauth/google');
        $this->assertStatusCode($res, 302);
        $this->assertStringEndsWith('/admin/login?error=Google%20sign-in%20is%20not%20set%20up%20on%20this%20server%20yet.', $res->response()->getHeaderLine('Location'));
        $this->assertStringContainsString('Redirecting to <a href="http', (string) $res->response()->getBody());
        $this->assertSame('no-cache, private', $res->response()->getHeaderLine('Cache-Control'));
    }

    public function test_changes_without_the_csrf_token_get_419(): void
    {
        $this->install();
        $res = $this->withoutXsrfToken()->postJson('/api/admin/logout');
        $this->assertStatusCode($res, 419);
        $this->assertJsonPath($res, 'error', 'Your session expired. Please refresh the page and try again.');
        $this->assertNull($res->response()->getCookie('XSRF-TOKEN'), 'like Laravel, a 419 carries no new token cookie');
    }

    public function test_dashboard_api_needs_a_login_and_hands_out_the_xsrf_cookie(): void
    {
        $this->install();
        $res = $this->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 401);
        $this->assertJsonPath($res, 'error', 'Please log in again.');
        $this->assertNotNull($res->response()->getCookie('XSRF-TOKEN'));
        $this->assertSame('noindex, nofollow', $res->response()->getHeaderLine('X-Robots-Tag'));
    }

    public function test_security_headers_and_no_caching_of_api_answers(): void
    {
        $this->install();
        $res = $this->getJson('/api/site');
        $this->assertStatusCode($res, 200);
        $this->assertSame('nosniff', $res->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $res->response()->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString("script-src 'self'", $res->response()->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('no-store, private', $res->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('', $res->response()->getHeaderLine('X-Robots-Tag'));
        $this->assertNull($res->response()->getCookie('XSRF-TOKEN'), 'the public API does not use a session');
    }

    public function test_session_carries_over_between_requests(): void
    {
        $this->install();
        $this->getJson('/api/admin/dashboard');
        $token = $this->carriedSession['_token'] ?? null;
        $this->assertIsString($token);
        $this->getJson('/api/admin/dashboard');
        $this->assertSame($token, $this->carriedSession['_token']);
    }

    public function test_rate_limiter_counts_in_a_window(): void
    {
        $limiter = new RateLimiter(new MockCache());
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($limiter->tooManyAttempts('k', 3));
            $limiter->hit('k', 60);
        }
        $this->assertTrue($limiter->tooManyAttempts('k', 3));
        $this->assertGreaterThan(0, $limiter->availableIn('k'));
        $limiter->clear('k');
        $this->assertFalse($limiter->tooManyAttempts('k', 3));
    }

    public function test_install_creates_the_laravel_tables(): void
    {
        $this->install();
        $db = db_connect();
        foreach (['admins', 'categories', 'products', 'product_images', 'offers', 'orders', 'settings', 'reviews', 'customers', 'password_reset_tokens'] as $table) {
            $this->assertTrue($db->tableExists($table, false), "table {$table}");
        }
        $this->assertSame(8, $db->table('categories')->countAllResults());
        $this->assertSame('City Florist', Settings::get('shop_name'));
        $this->assertTrue(Installer::installed());
        // Running the migrations again (the dashboard does that on every visit) changes nothing.
        Installer::upgrade();
        Installer::migrate();
        $this->assertSame(1, $db->table('admins')->countAllResults());
    }

    public function test_encryption_reads_what_laravel_wrote(): void
    {
        // Laravel's Crypt::encryptString('JBSWY3DPEHPK3PXP') with APP_KEY base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=
        // (the bytes 0…31) produces this format; the round trip with that key must match exactly.
        config(Encryption::class)->key = implode('', array_map('chr', range(0, 31)));
        $payload = Crypto::encryptString('JBSWY3DPEHPK3PXP');
        $decoded = json_decode(base64_decode($payload), true);
        $this->assertSame(['iv', 'value', 'mac', 'tag'], array_keys($decoded));
        $this->assertSame(hash_hmac('sha256', $decoded['iv'] . $decoded['value'], config(Encryption::class)->key), $decoded['mac']);
        $this->assertSame('JBSWY3DPEHPK3PXP', Crypto::decryptString($payload));
    }
}
