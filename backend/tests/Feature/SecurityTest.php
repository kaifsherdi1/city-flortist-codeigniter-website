<?php

namespace Tests\Feature;

use App\Database\Migrations\CallNumberToOwnerNumber;
use App\Database\Migrations\SetOwnerContactDetails;
use App\Database\Migrations\WhatsappToOwnerNumber;
use App\Libraries\Installer;
use App\Libraries\Passwords;
use App\Libraries\Settings;
use App\Models\AdminModel;
use Config\Florist;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Port of the Laravel backend's tests/Feature/SecurityTest.php: the starting-password lock, "remember me" ending
 * with a password change, the live-site install rule, Google-Maps-only links, the contact-detail migrations that
 * keep what the owner typed, the owner's email/phone login, what the status page tells strangers, the security
 * headers and the public API's rate limit.
 *
 * @internal
 */
final class SecurityTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Laravel boots a fresh application for every test. Here the shared services outlive a test: the rate
        // limiter would keep the previous test's cache (and its request counts), Google/mail an older Config\Florist.
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }

        $this->install();
    }

    protected function tearDown(): void
    {
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }
        parent::tearDown();
    }

    private function firstAdmin(): array
    {
        $admin = (new AdminModel())->orderBy('id')->first();
        $this->assertNotNull($admin);

        return $admin;
    }

    public function test_starting_password_locks_the_dashboard_until_changed(): void
    {
        $start = config(Florist::class)->adminPassword;
        $res   = $this->postJson('/api/admin/login', ['login' => 'admin', 'password' => $start]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'must_change_password', true);

        $res = $this->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 403);
        $this->assertJsonPath($res, 'must_change_password', true);
        $this->assertStatusCode($this->getJson('/api/admin/products'), 403);

        // The starting password (or the default one) can't be chosen again.
        $this->assertStatusCode($this->putJson('/api/admin/settings/password', ['current_password' => $start, 'new_password' => Installer::DEFAULT_PASSWORD, 'confirm_password' => Installer::DEFAULT_PASSWORD]), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/password', ['current_password' => $start, 'new_password' => 'Petals#Hubballi9', 'confirm_password' => 'Petals#Hubballi9']), 200);

        $this->assertJsonPath($this->getJson('/api/admin/status'), 'must_change_password', false);
        $this->assertStatusCode($this->getJson('/api/admin/dashboard'), 200);
    }

    public function test_own_password_does_not_lock_anything(): void
    {
        (new AdminModel())->update($this->firstAdmin()['id'], ['password_hash' => Passwords::hash('Petals#Hubballi9')]);
        $this->assertJsonPath($this->postJson('/api/admin/login', ['login' => 'admin', 'password' => 'Petals#Hubballi9']), 'must_change_password', false);
        $this->assertStatusCode($this->getJson('/api/admin/dashboard'), 200);
    }

    public function test_changing_the_password_ends_remember_me_logins(): void
    {
        $admin = $this->firstAdmin();
        (new AdminModel())->update($admin['id'], ['password_hash' => Passwords::hash('Petals#Hubballi9'), 'remember_token' => 'old-token']);

        $res = $this->actingAsAdmin($admin)->putJson('/api/admin/settings/password', ['current_password' => 'Petals#Hubballi9', 'new_password' => 'Roses&Lilies2026', 'confirm_password' => 'Roses&Lilies2026']);
        $this->assertStatusCode($res, 200);
        $this->assertNotSame('old-token', (new AdminModel())->find($admin['id'])['remember_token']);
    }

    public function test_live_site_refuses_to_install_with_the_default_password(): void
    {
        $db = db_connect();
        $db->disableForeignKeyChecks();
        $db->table('admins')->emptyTable();
        $db->enableForeignKeyChecks();
        config(Florist::class)->production = true;
        // Laravel switched CSRF back on in production and turned it off for this test; the browser simulated
        // here always sends the token, so nothing to do.
        config(Florist::class)->adminPassword = Installer::DEFAULT_PASSWORD;

        $res = $this->postJson('/api/admin/install', ['seed' => false]);
        $this->assertStatusCode($res, 403);
        // The message names the setting to change: florist.adminPassword in CodeIgniter's .env (FLORIST_ADMIN_PASSWORD in Laravel's).
        $this->assertJsonPath($res, 'error', static fn ($e) => str_contains($e, 'florist.adminPassword'));
        $this->assertFalse(Installer::installed());

        config(Florist::class)->adminPassword = 'Petals#Hubballi9';
        $this->assertStatusCode($this->postJson('/api/admin/install', ['seed' => false]), 200);
    }

    public function test_map_must_be_a_google_maps_link(): void
    {
        $this->actingAsAdmin($this->firstAdmin());
        $base = ['whatsapp_number' => '919876543210'];
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['map_embed' => 'https://evil.example.com/phish']), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['map_embed' => 'https://www.google.com.evil.io/maps']), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['map_embed' => '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>']), 200);

        // "Get directions" link: Google Maps only (share links included).
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['map_link' => 'https://evil.example.com/maps']), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['map_link' => 'javascript:alert(1)']), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['map_link' => 'https://maps.app.goo.gl/AbC123']), 200);
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.map_link', 'https://maps.app.goo.gl/AbC123');
    }

    public function test_contact_update_keeps_what_the_owner_typed(): void
    {
        Settings::put('whatsapp_number', '919812345678');   // owner's own choice
        Settings::put('address', 'Hubballi, Karnataka');    // still the placeholder
        require_once APPPATH . 'Database/Migrations/2026-11-12-000001_SetOwnerContactDetails.php';
        (new SetOwnerContactDetails())->up();

        $this->assertSame('919812345678', Settings::get('whatsapp_number'));
        $this->assertStringContainsString('Deshpande Nagar', Settings::get('address'));
    }

    public function test_whatsapp_moves_to_the_owner_number_but_keeps_a_custom_one(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-11-15-000003_WhatsappToOwnerNumber.php';

        Settings::put('whatsapp_number', '919901794277'); // the previous default
        (new WhatsappToOwnerNumber())->up();
        $this->assertSame('919900094843', Settings::get('whatsapp_number'));

        Settings::put('whatsapp_number', '919812345678'); // owner's own choice
        (new WhatsappToOwnerNumber())->up();
        $this->assertSame('919812345678', Settings::get('whatsapp_number'));

        // Call button: same rule.
        require_once APPPATH . 'Database/Migrations/2026-11-15-000004_CallNumberToOwnerNumber.php';
        Settings::put('shop_phone', '+91 99017 94277');
        (new CallNumberToOwnerNumber())->up();
        $this->assertSame('+91 99000 94843', Settings::get('shop_phone'));
    }

    public function test_install_gives_the_owner_email_and_phone_login(): void
    {
        $admin = $this->firstAdmin();
        $this->assertSame('cityfloristh@gmail.com', $admin['email']);
        $this->assertSame('919900094843', $admin['phone']);
        foreach (['cityfloristh@gmail.com', '99000 94843', '+91 99000 94843'] as $login) {
            $res = $this->postJson('/api/admin/login', ['login' => $login, 'password' => config(Florist::class)->adminPassword]);
            $this->assertStatusCode($res, 200);
            $this->assertJsonPath($res, 'admin.username', 'admin');
            $this->postJson('/api/admin/logout');
            // Laravel's forgetGuards(): nothing to do, each request reads the login from the session again.
        }
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.email', 'cityfloristh@gmail.com');
    }

    public function test_shop_details_default_to_the_owners_number_and_listing(): void
    {
        $res = $this->getJson('/api/site');
        $this->assertJsonPath($res, 'settings.whatsapp_number', '919900094843');
        $this->assertJsonPath($res, 'settings.shop_phone', '+91 99000 94843');
        $this->assertJsonPath($res, 'settings.address', static fn ($a) => str_contains($a, 'Deshpande Nagar') && str_contains($a, '580029'));
        $this->assertJsonPath($res, 'settings.map_embed', static fn ($m) => str_contains($m, 'cid=10825630060150326606'));
        $this->assertJsonPath($res, 'settings.map_link', static fn ($m) => str_contains($m, 'ChIJU9x_hUPXuDsRTgUN3S5dPJY'));
    }

    public function test_status_does_not_tell_strangers_about_the_server(): void
    {
        $res = $this->getJson('/api/admin/status');
        $this->assertJsonPath($res, 'database', null);
        $this->assertJsonPath($res, 'admin', null);
    }

    public function test_security_headers_and_no_indexing_of_the_dashboard(): void
    {
        $res = $this->getJson('/api/site');
        $this->assertSame('nosniff', $res->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $res->response()->getHeaderLine('X-Frame-Options'));
        $this->assertSame('noindex, nofollow', $this->getJson('/api/admin/status')->response()->getHeaderLine('X-Robots-Tag'));
    }

    public function test_public_api_is_rate_limited(): void
    {
        for ($i = 0; $i < 240; $i++) {
            $this->getJson('/api/products?slug=none');
        }
        $this->assertStatusCode($this->getJson('/api/products?slug=none'), 429);
    }
}
