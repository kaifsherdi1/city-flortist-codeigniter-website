<?php

namespace Tests\Feature\Detailed;

use App\Libraries\Crypto;
use App\Libraries\GoogleOAuth;
use App\Libraries\Installer;
use App\Libraries\Mailer;
use App\Libraries\Passwords;
use App\Libraries\Text;
use App\Libraries\Totp;
use App\Models\AdminModel;
use CodeIgniter\Test\TestResponse;
use Config\Florist;
use Config\Services;
use RuntimeException;
use Tests\Support\FeatureTestCase;
use Throwable;

/**
 * App\Controllers\Admin\Auth: status, install, login (+ two-step), logout, forgot/reset password, staff sign-up and
 * Google sign-in, checked against the Laravel version's behaviour (status codes, messages, JSON keys and types).
 *
 * @internal
 */
final class AuthTest extends FeatureTestCase
{
    private const PASSWORD = 'Petals#Hubballi9';

    private const STATUS_KEYS = [
        'installed', 'admin', 'new_orders', 'counts', 'pricing', 'login_options', 'must_change_password',
        'pending_staff', 'two_factor_required', 'previous_login', 'database', 'default_username',
    ];

    private const WRONG_LOGIN = 'Wrong login details. Check your username, email or phone number and password.';

    private const PENDING_LOGIN = 'Your staff account is waiting for the shop owner’s approval. You can log in once it’s approved.';

    private const SWITCHED_OFF = 'This account has been switched off by the shop owner.';

    private const WRONG_CODE = 'That code is not right. Use the newest 6-digit code from your authenticator app, or a backup code.';

    private const FORGOT_SENT = 'If that email belongs to the shop account, a reset link is on its way. It works for 60 minutes.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSharedServices();
    }

    protected function tearDown(): void
    {
        $this->resetSharedServices();
        parent::tearDown();
    }

    /**
     * Shared services outlive a test: the rate limiter keeps the first test's MockCache (attempt counts would carry
     * over), and googleOAuth/mailer keep an older Config\Florist and any mock injected here.
     */
    private function resetSharedServices(): void
    {
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }
    }

    // ------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------

    private function owner(): array
    {
        return (new AdminModel())->where('role', 'owner')->first();
    }

    private function admin(int $id): array
    {
        return (new AdminModel())->find($id);
    }

    private function setPassword(int $id, string $password): void
    {
        (new AdminModel())->builder()->where('id', $id)->update(['password_hash' => Passwords::hash($password)]);
    }

    /** Owner with their own password (not the starting one), email owner@example.com and phone 919876543210. */
    private function installWithOwner(): array
    {
        $this->install();
        $owner = $this->owner();
        (new AdminModel())->builder()->where('id', $owner['id'])->update([
            'email' => 'owner@example.com', 'phone' => '919876543210', 'password_hash' => Passwords::hash(self::PASSWORD),
        ]);

        return $this->admin((int) $owner['id']);
    }

    private function staff(string $username, string $status, ?string $email = null): array
    {
        $model = new AdminModel();
        $id    = $model->insert([
            'username' => $username, 'email' => $email ?? "{$username}@example.com", 'password_hash' => Passwords::hash(self::PASSWORD),
            'role'     => 'staff', 'status' => $status,
        ]);

        return $this->admin((int) $id);
    }

    /** Turns two-step login on directly in the table. Returns [secret, backup codes]. */
    private function enableTwoFactor(int $id): array
    {
        $secret = Totp::newSecret();
        (new AdminModel())->builder()->where('id', $id)->update([
            'two_factor_secret'       => Crypto::encryptString($secret),
            'two_factor_confirmed_at' => date('Y-m-d H:i:s'),
        ]);

        return [$secret, (new AdminModel())->newRecoveryCodes($id)];
    }

    private function login(string $login, string $password, array $extra = []): TestResponse
    {
        return $this->postJson('/api/admin/login', ['login' => $login, 'password' => $password] + $extra);
    }


    /** Replaces Google with a fake that answers with $user (a profile) or throws it. */
    private function fakeGoogle(array|Throwable $user, bool $configured = true): void
    {
        $fake = new class (config(Florist::class)) extends GoogleOAuth {
            public bool $on = true;

            public array|Throwable $result = [];

            public function configured(): bool
            {
                return $this->on;
            }

            public function user(string $callbackUrl, $request): array
            {
                if ($this->result instanceof Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
        $fake->on     = $configured;
        $fake->result = $user;
        Services::injectMock('googleOAuth', $fake);
    }

    private function profile(string $email, bool $verified = true): array
    {
        return ['id' => 'g-1', 'email' => $email, 'email_verified' => $verified, 'name' => 'X'];
    }

    private function insertRow(string $table, array $row): void
    {
        db_connect()->table($table)->insert($row + ['created_at' => date('Y-m-d H:i:s')]);
    }

    // ------------------------------------------------------------------------------------------------------
    // status + install
    // ------------------------------------------------------------------------------------------------------

    public function test_status_before_install(): void
    {
        $res = $this->getJson('/api/admin/status');
        $this->assertStatusCode($res, 200);
        $this->assertSame(self::STATUS_KEYS, array_keys($this->json($res)));
        $this->assertSame([
            'installed'            => false,
            'admin'                => null,
            'new_orders'           => 0,
            'counts'               => null,
            'pricing'              => null,
            'login_options'        => ['google' => false, 'reset_by_email' => true],
            'must_change_password' => false,
            'pending_staff'        => 0,
            'two_factor_required'  => false,
            'previous_login'       => null,
            'database'             => 'mysql',
            'default_username'     => config(Florist::class)->adminUsername,
        ], $this->json($res));
        $this->assertNotNull($res->response()->getCookie('XSRF-TOKEN'));
        $this->assertSame('no-store, private', $res->response()->getHeaderLine('Cache-Control'));
    }

    public function test_status_tells_strangers_nothing_about_the_server(): void
    {
        $this->install();
        $res = $this->getJson('/api/admin/status');
        $this->assertJsonPath($res, 'installed', true);
        $this->assertJsonPath($res, 'admin', null);
        $this->assertJsonPath($res, 'database', null);
        $this->assertJsonPath($res, 'default_username', null);
        $this->assertJsonPath($res, 'counts', null);
        $this->assertJsonPath($res, 'pricing', null);
    }

    public function test_reset_by_email_follows_the_mailer(): void
    {
        config(Florist::class)->mailer = 'log';
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'login_options.reset_by_email', false);
    }

    public function test_install_runs_once(): void
    {
        $res = $this->postJson('/api/admin/install', ['seed' => false]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['log' => ['Tables created', 'Admin login created', 'Categories created'], 'username' => config(Florist::class)->adminUsername], $this->json($res));
        $this->assertTrue(Installer::installed());

        $res = $this->postJson('/api/admin/install', ['seed' => false]);
        $this->assertStatusCode($res, 403);
        $this->assertSame(['error' => 'The site is already installed.'], $this->json($res));
    }

    public function test_install_with_starter_photos(): void
    {
        $res = $this->postJson('/api/admin/install', ['seed' => '1']);
        $this->assertStatusCode($res, 200);
        $this->assertCount(4, $this->json($res, 'log'));
        $this->assertMatchesRegularExpression('/^[1-9]\d* starter products imported$/', $this->json($res, 'log.3'));
    }

    public function test_live_site_refuses_to_install_with_the_default_password(): void
    {
        $config             = config(Florist::class);
        $config->production = true;
        $config->adminPassword = Installer::DEFAULT_PASSWORD;

        $res = $this->postJson('/api/admin/install', ['seed' => false]);
        $this->assertStatusCode($res, 403);
        $this->assertJsonPath($res, 'error', 'Set a strong florist.adminPassword (10+ characters, not the example one) in .env before installing.');
        $this->assertFalse(Installer::installed());

        $config->adminPassword = self::PASSWORD;
        $this->assertStatusCode($this->postJson('/api/admin/install', ['seed' => false]), 200);
    }

    public function test_a_failed_install_says_why(): void
    {
        $db = db_connect();
        $db->query("CREATE TRIGGER scratch_no_admins BEFORE INSERT ON admins FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'no admins today'");

        try {
            $res = $this->postJson('/api/admin/install', ['seed' => false]);
        } finally {
            $db->query('DROP TRIGGER IF EXISTS scratch_no_admins');
        }
        $this->assertStatusCode($res, 500);
        $this->assertJsonPath($res, 'error', static fn ($e) => str_starts_with($e, 'Install failed: ') && str_contains($e, 'no admins today'));
    }

    public function test_status_for_the_logged_in_owner(): void
    {
        $owner = $this->installWithOwner();
        $cat   = (int) db_connect()->table('categories')->select('id')->get()->getRow()->id;
        $this->insertRow('products', ['category_id' => $cat, 'name' => 'A', 'slug' => 'a', 'price' => 500, 'is_featured' => 1, 'is_active' => 1]);
        $this->insertRow('products', ['category_id' => $cat, 'name' => 'B', 'slug' => 'b', 'price' => 0, 'is_featured' => 1, 'is_active' => 0]);
        $this->insertRow('products', ['category_id' => $cat, 'name' => 'C', 'slug' => 'c', 'price' => 99.5]);
        foreach (['new', 'new', 'contacted', 'done'] as $status) {
            $this->insertRow('orders', ['product_name' => 'A', 'customer_name' => 'Asha', 'customer_phone' => '919876543210', 'status' => $status]);
        }
        $this->insertRow('reviews', ['name' => 'Asha', 'rating' => 5, 'text' => 'Lovely', 'status' => 'pending']);
        $this->insertRow('reviews', ['name' => 'Ravi', 'rating' => 4, 'text' => 'Nice', 'status' => 'approved']);
        $this->insertRow('offers', ['title' => 'Live', 'discount_pct' => 10, 'starts_at' => date('Y-m-d H:i:s', time() - 3600), 'ends_at' => date('Y-m-d H:i:s', time() + 86400)]);
        $this->insertRow('offers', ['title' => 'Over', 'discount_pct' => 50, 'starts_at' => date('Y-m-d H:i:s', time() - 7200), 'ends_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->staff('ravi', 'pending');
        $this->staff('meena', 'active');

        $this->actingAsAdmin($owner);
        $this->carriedSession['must_change_password'] = true;
        $this->carriedSession['previous_login']       = ['at' => '2026-11-16T10:00:00+05:30', 'ip' => '10.0.0.7'];
        $res = $this->getJson('/api/admin/status');
        $this->assertStatusCode($res, 200);
        $this->assertSame(self::STATUS_KEYS, array_keys($this->json($res)));
        $this->assertSame([
            'id' => (int) $owner['id'], 'username' => 'admin', 'email' => 'owner@example.com', 'phone' => '919876543210',
            'role' => 'owner', 'status' => 'active', 'two_factor' => false, 'backup_codes_left' => null,
        ], $this->json($res, 'admin'));
        $this->assertJsonPath($res, 'new_orders', 2);
        $this->assertSame(['featured' => 1, 'contacted' => 1, 'offers' => 1, 'reviews' => 1], $this->json($res, 'counts'));
        $this->assertSame(['priced' => 2, 'total' => 3], $this->json($res, 'pricing'));
        $this->assertJsonPath($res, 'must_change_password', true);
        $this->assertJsonPath($res, 'pending_staff', 1);
        $this->assertJsonPath($res, 'two_factor_required', false);
        $this->assertSame(['at' => '2026-11-16T10:00:00+05:30', 'ip' => '10.0.0.7'], $this->json($res, 'previous_login'));
        $this->assertJsonPath($res, 'database', 'mysql');
        $this->assertJsonPath($res, 'default_username', null);
    }

    public function test_staff_do_not_see_the_pending_sign_ups(): void
    {
        $this->install();
        $this->staff('ravi', 'pending');
        $meena = $this->staff('meena', 'active');
        $res   = $this->actingAsAdmin($meena)->getJson('/api/admin/status');
        $this->assertJsonPath($res, 'admin.role', 'staff');
        $this->assertJsonPath($res, 'pending_staff', 0);
        $this->assertJsonPath($res, 'must_change_password', false);
        $this->assertJsonPath($res, 'previous_login', null);
    }

    // ------------------------------------------------------------------------------------------------------
    // login / logout
    // ------------------------------------------------------------------------------------------------------

    public function test_login_with_username_email_or_phone(): void
    {
        $this->installWithOwner();
        foreach (['admin', 'owner@example.com', 'OWNER@example.com', '98765 43210', '+91 98765 43210', '09876543210'] as $login) {
            $res = $this->login($login, self::PASSWORD);
            $this->assertStatusCode($res, 200);
            $this->assertJsonPath($res, 'admin.username', 'admin');
            $logout = $this->postJson('/api/admin/logout');
            $this->assertStatusCode($logout, 200);
            $this->assertSame('{"ok":true}', (string) $logout->response()->getBody());
        }

        // The older dashboard sent `username` instead of `login`.
        $res = $this->postJson('/api/admin/login', ['username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(self::STATUS_KEYS, array_keys($this->json($res)));
        $this->assertSame(['id', 'username', 'email', 'phone', 'role', 'status', 'two_factor', 'backup_codes_left'], array_keys($this->json($res, 'admin')));
        $this->assertJsonPath($res, 'must_change_password', false);
        $this->assertJsonPath($res, 'two_factor_required', false);
        $this->assertJsonPath($res, 'pending_staff', 0);
        $this->assertJsonPath($res, 'database', 'mysql');
        $this->assertSame(['featured' => 0, 'contacted' => 0, 'offers' => 0, 'reviews' => 0], $this->json($res, 'counts'));
        $this->assertSame(['priced' => 0, 'total' => 0], $this->json($res, 'pricing'));
        $this->assertSame((int) $this->owner()['id'], $this->carriedSession['login_admin'] ?? null);
    }

    public function test_an_empty_login_field_does_not_fall_back_to_username(): void
    {
        $this->installWithOwner();
        $res = $this->postJson('/api/admin/login', ['login' => '', 'username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertStatusCode($res, 422);
        $this->assertJsonPath($res, 'error', self::WRONG_LOGIN);
    }

    public function test_wrong_details_give_one_message_for_unknown_and_known_accounts(): void
    {
        $this->installWithOwner();
        $a = $this->login('nobody@example.com', 'x');
        $b = $this->login('owner@example.com', 'x');
        $c = $this->postJson('/api/admin/login', ['login' => ['admin'], 'password' => ['x']]);
        foreach ([$a, $b, $c] as $res) {
            $this->assertStatusCode($res, 422);
            $this->assertSame(['error' => self::WRONG_LOGIN], $this->json($res));
        }
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
    }

    public function test_starting_password_must_be_changed(): void
    {
        $this->install();
        $res = $this->login('admin', config(Florist::class)->adminPassword);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'must_change_password', true);
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'must_change_password', true);

        $this->postJson('/api/admin/logout');
        $this->setPassword((int) $this->owner()['id'], Installer::DEFAULT_PASSWORD);
        $this->assertJsonPath($this->login('admin', Installer::DEFAULT_PASSWORD), 'must_change_password', true);

        $this->postJson('/api/admin/logout');
        $this->setPassword((int) $this->owner()['id'], self::PASSWORD);
        $this->assertJsonPath($this->login('admin', self::PASSWORD), 'must_change_password', false);
    }

    public function test_remember_me_sets_a_long_lived_cookie(): void
    {
        $owner = $this->installWithOwner();
        $res   = $this->login('admin', self::PASSWORD, ['remember' => true]);
        $this->assertStatusCode($res, 200);
        $cookie = $res->response()->getCookie('remember_admin');
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(time() + 86400 * 300, $cookie->getExpiresTimestamp());
        $token = $this->admin((int) $owner['id'])['remember_token'];
        $this->assertNotEmpty($token);

        // Logging out ends "remember me" everywhere.
        $this->postJson('/api/admin/logout');
        $this->assertNotSame($token, $this->admin((int) $owner['id'])['remember_token']);
        $this->assertArrayNotHasKey('remember_admin', $this->cookieJar);

        $without = $this->login('admin', self::PASSWORD, ['remember' => 'false']);
        $this->assertStatusCode($without, 200);
        $this->assertNull($without->response()->getCookie('remember_admin'));
    }

    public function test_login_renews_the_session_and_csrf_token(): void
    {
        $this->installWithOwner();
        $this->getJson('/api/admin/status');
        $before = $this->carriedSession['_token'];
        $res    = $this->login('admin', self::PASSWORD);
        $this->assertStatusCode($res, 200);
        $after = $this->carriedSession['_token'];
        $this->assertNotSame($before, $after);
        $this->assertSame($after, $res->response()->getCookie('XSRF-TOKEN')->getValue());
        $this->assertArrayHasKey('__ci_last_regenerate', $this->carriedSession);
        // The next change goes through with the new token.
        $this->assertStatusCode($this->postJson('/api/admin/logout'), 200);
    }

    public function test_previous_login_is_shown_after_logging_in(): void
    {
        $owner = $this->installWithOwner();
        $res   = $this->login('admin', self::PASSWORD);
        $this->assertJsonPath($res, 'previous_login', null);
        $row = $this->admin((int) $owner['id']);
        $this->assertSame('127.0.0.1', $row['last_login_ip']);
        $this->assertNotNull($row['last_login_at']);

        $this->postJson('/api/admin/logout');
        $res = $this->fromIp('10.1.2.3')->login('admin', self::PASSWORD);
        $this->assertSame(['at' => Text::isoDate($row['last_login_at']), 'ip' => '127.0.0.1'], $this->json($res, 'previous_login'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+05:30$/', $this->json($res, 'previous_login.at'));
        $this->assertSame('10.1.2.3', $this->admin((int) $owner['id'])['last_login_ip']);
        $this->assertSame(['at' => Text::isoDate($row['last_login_at']), 'ip' => '127.0.0.1'], $this->json($this->getJson('/api/admin/status'), 'previous_login'));
    }

    public function test_device_lockout_after_eight_tries(): void
    {
        $this->installWithOwner();
        for ($i = 0; $i < 8; $i++) {
            $this->assertStatusCode($this->login("user{$i}", 'guess'), 422);
        }
        $res = $this->login('admin', self::PASSWORD);
        $this->assertStatusCode($res, 429);
        $this->assertSame(['error' => 'Too many attempts. Please wait 15 minutes and try again.'], $this->json($res));
        // A forged X-Forwarded-For header doesn't reset it.
        $this->assertStatusCode($this->withHeader('X-Forwarded-For', '203.0.113.9')->login('admin', self::PASSWORD), 429);
        // Another device is fine.
        $this->extraHeaders = [];
        $this->assertStatusCode($this->fromIp('10.9.9.9')->login('admin', self::PASSWORD), 200);
    }

    public function test_account_lockout_also_applies_per_account(): void
    {
        $this->installWithOwner();
        for ($i = 0; $i < 8; $i++) {
            $this->fromIp("10.0.0.{$i}")->login('admin', "guess{$i}");
        }
        $this->assertStatusCode($this->fromIp('10.0.0.99')->login('admin', self::PASSWORD), 429);
        // The account key follows the account (admin 1), so other spellings are locked too.
        $this->assertStatusCode($this->fromIp('10.0.0.98')->login('ADMIN', self::PASSWORD), 429);
        $this->assertSame(8, service('rateLimiter')->attempts('login-account:id:1'));
    }

    public function test_one_minute_left_is_singular(): void
    {
        $this->installWithOwner();
        for ($i = 0; $i < 8; $i++) {
            service('rateLimiter')->hit('login:127.0.0.1', 30);
        }
        $this->assertSame(['error' => 'Too many attempts. Please wait 1 minute and try again.'], $this->json($this->login('admin', self::PASSWORD)));
    }

    public function test_a_successful_login_clears_the_counters(): void
    {
        $this->installWithOwner();
        for ($i = 0; $i < 7; $i++) {
            $this->login('admin', 'guess');
        }
        $this->assertSame(7, service('rateLimiter')->attempts('login:127.0.0.1'));
        $this->assertStatusCode($this->login('Admin', self::PASSWORD), 200);
        $this->assertSame(0, service('rateLimiter')->attempts('login:127.0.0.1'));
        $this->assertSame(0, service('rateLimiter')->attempts('login-account:admin'));
    }

    public function test_pending_and_switched_off_staff_cannot_log_in(): void
    {
        $this->install();
        $this->staff('ravi', 'pending');
        $this->staff('meena', 'disabled');

        // Wrong password: the usual answer, nothing about the account.
        $this->assertSame(['error' => self::WRONG_LOGIN], $this->json($this->login('ravi', 'wrong')));

        $res = $this->login('ravi', self::PASSWORD);
        $this->assertStatusCode($res, 403);
        $this->assertSame(['error' => self::PENDING_LOGIN], $this->json($res));
        // The counters were cleared before the account check (the password was right).
        $this->assertSame(0, service('rateLimiter')->attempts('login:127.0.0.1'));

        $res = $this->login('meena@example.com', self::PASSWORD);
        $this->assertStatusCode($res, 403);
        $this->assertSame(['error' => self::SWITCHED_OFF], $this->json($res));
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
    }

    public function test_logout(): void
    {
        $owner = $this->installWithOwner();
        $token = bin2hex(random_bytes(20));
        $this->actingAsAdmin($owner);
        $this->carriedSession['_token']               = $token;
        $this->carriedSession['must_change_password'] = false;
        $this->carriedSession['previous_login']       = ['at' => '2026-11-16T10:00:00+05:30', 'ip' => '10.0.0.7'];

        $res = $this->postJson('/api/admin/logout');
        $this->assertStatusCode($res, 200);
        $this->assertSame(['ok' => true], $this->json($res));
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
        $this->assertArrayNotHasKey('previous_login', $this->carriedSession);
        $this->assertNotSame($token, $this->carriedSession['_token']);
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'admin', null);

        // Logging out twice is harmless.
        $this->assertSame(['ok' => true], $this->json($this->postJson('/api/admin/logout')));
    }

    // ------------------------------------------------------------------------------------------------------
    // Two-step login
    // ------------------------------------------------------------------------------------------------------

    public function test_login_asks_for_the_code_and_nothing_opens_before_it(): void
    {
        $owner    = $this->installWithOwner();
        [$secret] = $this->enableTwoFactor((int) $owner['id']);

        $this->getJson('/api/admin/status');
        $token = $this->carriedSession['_token'];
        $res   = $this->login('admin', self::PASSWORD);
        $this->assertStatusCode($res, 200);
        $this->assertSame(self::STATUS_KEYS, array_keys($this->json($res)));
        $this->assertJsonPath($res, 'admin', null);
        $this->assertJsonPath($res, 'two_factor_required', true);
        $this->assertJsonPath($res, 'database', null);
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
        $this->assertNotSame($token, $this->carriedSession['_token']);
        $pending = $this->carriedSession['two_factor_pending'];
        $this->assertSame(['id', 'remember', 'starting', 'until'], array_keys($pending));
        $this->assertSame([(int) $owner['id'], false, false], [$pending['id'], $pending['remember'], $pending['starting']]);
        $this->assertEqualsWithDelta(time() + 300, $pending['until'], 5);
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'two_factor_required', true);

        $res = $this->postJson('/api/admin/login/two-factor', ['code' => '000000']);
        $this->assertStatusCode($res, 422);
        $this->assertSame(['error' => self::WRONG_CODE], $this->json($res));

        $step = intdiv(time(), 30);
        $res  = $this->postJson('/api/admin/login/two-factor', ['code' => ' ' . substr(Totp::code($secret, $step), 0, 3) . ' ' . substr(Totp::code($secret, $step), 3)]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.username', 'admin');
        $this->assertJsonPath($res, 'admin.two_factor', true);
        $this->assertJsonPath($res, 'admin.backup_codes_left', 10);
        $this->assertJsonPath($res, 'two_factor_required', false);
        $this->assertJsonPath($res, 'previous_login', null);
        $this->assertArrayNotHasKey('two_factor_pending', $this->carriedSession);
        $this->assertSame((int) $owner['id'], $this->carriedSession['login_admin']);
        $this->assertSame(0, service('rateLimiter')->attempts('two-factor:' . $owner['id']));
        $this->assertSame('127.0.0.1', $this->admin((int) $owner['id'])['last_login_ip']);

        // The same code can't be used again.
        $this->postJson('/api/admin/logout');
        $this->login('admin', self::PASSWORD);
        $this->assertStatusCode($this->postJson('/api/admin/login/two-factor', ['code' => Totp::code($secret, $step)]), 422);
    }

    public function test_backup_code_works_once(): void
    {
        $owner       = $this->installWithOwner();
        [, $codes]   = $this->enableTwoFactor((int) $owner['id']);
        $this->login('admin', self::PASSWORD);
        $res = $this->postJson('/api/admin/login/two-factor', ['code' => strtolower($codes[0])]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.backup_codes_left', 9);

        $this->postJson('/api/admin/logout');
        $this->login('admin', self::PASSWORD);
        $this->assertStatusCode($this->postJson('/api/admin/login/two-factor', ['code' => $codes[0]]), 422);
    }

    public function test_code_guessing_is_limited(): void
    {
        $owner = $this->installWithOwner();
        $this->enableTwoFactor((int) $owner['id']);
        $this->login('admin', self::PASSWORD);
        for ($i = 0; $i < 6; $i++) {
            $this->assertStatusCode($this->postJson('/api/admin/login/two-factor', ['code' => sprintf('%06d', $i)]), 422);
        }
        $res = $this->postJson('/api/admin/login/two-factor', ['code' => '999999']);
        $this->assertStatusCode($res, 429);
        $this->assertSame(['error' => 'Too many wrong codes. Please wait 15 minutes and try again.'], $this->json($res));
    }

    public function test_second_step_without_a_password_first_is_refused(): void
    {
        $this->installWithOwner();
        $res = $this->postJson('/api/admin/login/two-factor', ['code' => '123456']);
        $this->assertStatusCode($res, 419);
        $this->assertSame(['error' => 'Your login timed out. Please enter your password again.'], $this->json($res));
    }

    public function test_the_second_step_times_out_after_five_minutes(): void
    {
        $owner    = $this->installWithOwner();
        [$secret] = $this->enableTwoFactor((int) $owner['id']);
        $this->login('admin', self::PASSWORD);
        $this->carriedSession['two_factor_pending']['until'] = time() - 1;

        $this->assertJsonPath($this->getJson('/api/admin/status'), 'two_factor_required', false);
        $res = $this->postJson('/api/admin/login/two-factor', ['code' => Totp::code($secret, intdiv(time(), 30))]);
        $this->assertStatusCode($res, 419);
        $this->assertJsonPath($res, 'error', 'Your login timed out. Please enter your password again.');
    }

    public function test_two_step_login_keeps_remember_me_and_the_starting_password_lock(): void
    {
        $this->install();
        $owner    = $this->owner();
        [$secret] = $this->enableTwoFactor((int) $owner['id']);
        $res      = $this->login('admin', config(Florist::class)->adminPassword, ['remember' => '1']);
        $this->assertJsonPath($res, 'two_factor_required', true);
        $this->assertNull($res->response()->getCookie('remember_admin'));
        $this->assertSame([true, true], [$this->carriedSession['two_factor_pending']['remember'], $this->carriedSession['two_factor_pending']['starting']]);

        $res = $this->postJson('/api/admin/login/two-factor', ['code' => Totp::code($secret, intdiv(time(), 30))]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'must_change_password', true);
        $this->assertNotNull($res->response()->getCookie('remember_admin'));
    }

    // ------------------------------------------------------------------------------------------------------
    // Forgot / reset password
    // ------------------------------------------------------------------------------------------------------

    public function test_forgot_and_reset_password(): void
    {
        $owner = $this->installWithOwner();

        $res = $this->postJson('/api/admin/password/forgot', ['email' => 'stranger@example.com']);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => self::FORGOT_SENT], $this->json($res));
        $this->assertSame([], $this->emails());

        $res = $this->postJson('/api/admin/password/forgot', ['email' => ' Owner@Example.COM ']);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => self::FORGOT_SENT], $this->json($res));
        $this->assertCount(1, $this->emails());
        $mail = $this->emails()[0];
        $this->assertStringContainsString('owner@example.com', json_encode($mail['recipients'] ?? []));
        $this->assertMatchesRegularExpression('~/admin/reset-password\?token=([0-9a-f]{64})&email=owner%40example\.com~', $mail['altMessage']);
        preg_match('~token=([0-9a-f]{64})~', $mail['altMessage'], $m);
        $token = $m[1];

        $reset = fn (array $body) => $this->postJson('/api/admin/password/reset', $body + ['token' => $token, 'email' => 'owner@example.com']);

        $res = $reset(['token' => 'wrong', 'password' => 'Fresh@2026', 'password_confirmation' => 'Fresh@2026']);
        $this->assertStatusCode($res, 422);
        $this->assertSame(['error' => 'This reset link is invalid or has expired. Please ask for a new one.'], $this->json($res));

        $res = $reset(['password' => 'short', 'password_confirmation' => 'short']);
        $this->assertStatusCode($res, 422);
        $this->assertSame(['error' => 'The new password must be at least 8 characters.'], $this->json($res));

        $res = $reset(['password' => 'Fresh@2026', 'password_confirmation' => 'Fresh@2027']);
        $this->assertStatusCode($res, 422);
        $this->assertSame(['error' => 'The two passwords do not match.'], $this->json($res));

        $res = $reset(['email' => 'stranger@example.com', 'password' => 'Fresh@2026', 'password_confirmation' => 'Fresh@2026']);
        $this->assertStatusCode($res, 422);

        // Spaces around a password are part of it (never trimmed); the email is matched case-insensitively.
        $res = $reset(['email' => ' OWNER@example.com', 'password' => ' Fresh@2026 ', 'password_confirmation' => ' Fresh@2026 ']);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'Password changed. You can log in with it now.'], $this->json($res));

        $this->assertStatusCode($this->login('admin', self::PASSWORD), 422);
        $this->assertStatusCode($this->login('admin', 'Fresh@2026'), 422);
        $this->assertStatusCode($this->login('owner@example.com', ' Fresh@2026 '), 200);

        // A token works once.
        $res = $reset(['password' => 'Again@2026', 'password_confirmation' => 'Again@2026']);
        $this->assertStatusCode($res, 422);
        $this->assertNotNull($this->admin((int) $owner['id'])['remember_token']);
    }

    public function test_forgot_password_checks_the_email_and_is_limited(): void
    {
        $this->installWithOwner();
        foreach (['nope', '', null] as $email) {
            $res = $this->postJson('/api/admin/password/forgot', ['email' => $email]);
            $this->assertStatusCode($res, 422);
            $this->assertSame(['error' => 'Please enter the email address on your account.'], $this->json($res));
        }
        $this->assertStatusCode($this->postJson('/api/admin/password/forgot', ['email' => 'a@example.com']), 200);
        $this->assertStatusCode($this->postJson('/api/admin/password/forgot', ['email' => 'b@example.com']), 200);

        $res = $this->postJson('/api/admin/password/forgot', ['email' => 'owner@example.com']);
        $this->assertStatusCode($res, 429);
        $this->assertSame(['error' => 'Too many requests. Please wait a few minutes and try again.'], $this->json($res));
        $this->assertSame([], $this->emails());
        // Per device.
        $this->assertStatusCode($this->fromIp('10.0.0.2')->postJson('/api/admin/password/forgot', ['email' => 'owner@example.com']), 200);
    }

    public function test_forgot_password_reports_a_mail_failure(): void
    {
        $this->installWithOwner();
        Services::injectMock('mailer', new class (config(Florist::class)) extends Mailer {
            public function send(string $to, string $subject, string $html, string $text): void
            {
                throw new RuntimeException('SMTP server is down');
            }
        });
        $res = $this->postJson('/api/admin/password/forgot', ['email' => 'owner@example.com']);
        $this->assertStatusCode($res, 500);
        $this->assertSame(['error' => 'The reset email could not be sent. Please check the mail settings on the server.'], $this->json($res));
    }

    // ------------------------------------------------------------------------------------------------------
    // Staff sign-up
    // ------------------------------------------------------------------------------------------------------

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'username' => 'ravi', 'email' => 'Ravi@Example.com', 'phone' => '98765 43210',
            'password' => 'Roses2026', 'password_confirmation' => 'Roses2026', 'website' => '',
        ];
    }

    public function test_staff_sign_up_needs_an_installed_site(): void
    {
        $res = $this->postJson('/api/admin/register', $this->form());
        $this->assertStatusCode($res, 403);
        $this->assertSame(['error' => 'The dashboard is not installed yet.'], $this->json($res));
    }

    public function test_staff_sign_up_stays_locked_until_the_owner_approves(): void
    {
        $this->install();
        $res = $this->postJson('/api/admin/register', $this->form());
        $this->assertStatusCode($res, 201);
        $this->assertSame(['message' => 'Account created. The shop owner needs to approve it before you can log in.'], $this->json($res));

        $staff = (new AdminModel())->where('username', 'ravi')->first();
        $this->assertSame(['staff', 'pending', 'ravi@example.com', '919876543210'], [$staff['role'], $staff['status'], $staff['email'], $staff['phone']]);
        $this->assertTrue(Passwords::check('Roses2026', $staff['password_hash']));
        $this->assertNotNull($staff['created_at']);
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);

        $res = $this->login('ravi', 'Roses2026');
        $this->assertStatusCode($res, 403);
        $this->assertJsonPath($res, 'error', self::PENDING_LOGIN);

        $this->assertJsonPath($this->actingAsAdmin($this->owner())->getJson('/api/admin/status'), 'pending_staff', 1);
    }

    public function test_sign_up_form_is_checked(): void
    {
        $this->install();
        $cases = [
            [$this->form(['website' => 'bot']), 400, 'Invalid request'],
            [$this->form(['username' => 'a b']), 422, 'Choose a username of 3–40 letters, numbers, dots, dashes or underscores (no spaces).'],
            [$this->form(['email' => 'nope']), 422, 'Please enter a valid email address.'],
            [$this->form(['phone' => '123']), 422, 'Please enter a valid 10-digit mobile number.'],
            [$this->form(['password' => 'onlyletters', 'password_confirmation' => 'onlyletters']), 422, 'Your password needs at least 8 characters, with letters and at least one number.'],
            [$this->form(['password_confirmation' => 'Different99']), 422, 'The two passwords don’t match.'],
            [$this->form(['username' => 'admin']), 422, 'That username is taken. Please choose another one.'],
            [$this->form(['email' => config(Florist::class)->adminEmail]), 422, 'An account with this email already exists. Try logging in instead.'],
        ];
        foreach ($cases as [$body, $status, $error]) {
            $res = $this->postJson('/api/admin/register', $body);
            $this->assertStatusCode($res, $status);
            $this->assertSame(['error' => $error], $this->json($res));
        }
        $this->assertSame(1, db_connect()->table('admins')->countAllResults());
    }

    public function test_staff_sign_ups_are_limited_per_device(): void
    {
        $this->install();
        $this->assertStatusCode($this->postJson('/api/admin/register', $this->form(['email' => 'nope'])), 422);
        for ($i = 0; $i < 3; $i++) {
            $this->assertStatusCode($this->postJson('/api/admin/register', $this->form(['username' => "staff{$i}", 'email' => "staff{$i}@example.com", 'phone' => '98765432' . (10 + $i)])), 201);
        }
        $res = $this->postJson('/api/admin/register', $this->form(['username' => 'staff9', 'email' => 'staff9@example.com', 'phone' => '9876543299']));
        $this->assertStatusCode($res, 429);
        $this->assertSame(['error' => 'Too many sign-ups from this device. Please try again in an hour.'], $this->json($res));
        $this->assertSame(4, db_connect()->table('admins')->countAllResults());
    }

    // ------------------------------------------------------------------------------------------------------
    // Google
    // ------------------------------------------------------------------------------------------------------

    public function test_google_sign_in_is_off_until_configured(): void
    {
        $this->install();
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'login_options.google', false);

        $res = $this->getJson('/api/admin/oauth/google');
        $this->assertStatusCode($res, 302);
        $this->assertSame('/admin/login?error=Google%20sign-in%20is%20not%20set%20up%20on%20this%20server%20yet.', $this->location($res));
        $this->assertArrayNotHasKey('admin_oauth_intent', $this->carriedSession);

        $res = $this->getJson('/api/admin/oauth/google?intent=register');
        $this->assertSame('/admin/register?error=Google%20sign-in%20is%20not%20set%20up%20on%20this%20server%20yet.', $this->location($res));

        $res = $this->getJson('/api/admin/oauth/google/callback?code=x&state=y');
        $this->assertStatusCode($res, 302);
        $this->assertSame('/admin/login', $this->location($res));
    }

    public function test_google_redirect_when_configured(): void
    {
        $this->install();
        $config                     = config(Florist::class);
        $config->googleClientId     = 'client-123';
        $config->googleClientSecret = 'secret';
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'login_options.google', true);

        $res = $this->getJson('/api/admin/oauth/google');
        $this->assertStatusCode($res, 302);
        $location = $res->response()->getHeaderLine('Location'); // absolute: Google's own URL
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('client-123', $query['client_id']);
        $this->assertStringEndsWith('/api/admin/oauth/google/callback', $query['redirect_uri']);
        $this->assertSame($this->carriedSession['google_oauth_state'], $query['state']);
        $this->assertSame('login', $this->carriedSession['admin_oauth_intent']);

        $this->getJson('/api/admin/oauth/google?intent=register');
        $this->assertSame('register', $this->carriedSession['admin_oauth_intent']);
        $this->getJson('/api/admin/oauth/google?intent=other');
        $this->assertSame('login', $this->carriedSession['admin_oauth_intent']);
    }

    public function test_google_logs_in_the_admin_with_that_email(): void
    {
        $owner = $this->installWithOwner();
        (new AdminModel())->builder()->where('id', $owner['id'])->update(['email' => 'x@example.com']);
        $this->fakeGoogle($this->profile('X@Example.com'));
        $this->carriedSession['admin_oauth_intent'] = 'login';

        $res = $this->getJson('/api/admin/oauth/google/callback?code=abc&state=def');
        $this->assertStatusCode($res, 302);
        $this->assertSame('/admin', $this->location($res));
        $this->assertSame((int) $owner['id'], $this->carriedSession['login_admin']);
        $this->assertArrayNotHasKey('admin_oauth_intent', $this->carriedSession);
        $this->assertFalse($this->carriedSession['must_change_password']);
        $this->assertNull($this->carriedSession['previous_login']);
        $this->assertNotNull($res->response()->getCookie('remember_admin'));
        $this->assertSame('127.0.0.1', $this->admin((int) $owner['id'])['last_login_ip']);

        $status = $this->getJson('/api/admin/status');
        $this->assertJsonPath($status, 'admin.email', 'x@example.com');
        $this->assertJsonPath($status, 'login_options.google', true);
    }

    public function test_google_failure_goes_back_to_the_login_page(): void
    {
        $this->install();
        $this->fakeGoogle(new RuntimeException('state mismatch'));
        $this->carriedSession['admin_oauth_intent'] = 'register';
        $res = $this->getJson('/api/admin/oauth/google/callback?error=access_denied');
        $this->assertStatusCode($res, 302);
        $this->assertSame('/admin/login?error=' . rawurlencode('Google sign-in was cancelled or failed. Please try again.'), $this->location($res));
        $this->assertSame('register', $this->carriedSession['admin_oauth_intent']);
    }

    public function test_an_unknown_google_account_gets_nothing_without_the_sign_up_intent(): void
    {
        $this->install();
        $this->fakeGoogle($this->profile('stranger@example.com'));
        $res = $this->getJson('/api/admin/oauth/google/callback');
        $this->assertSame('/admin/login?error=' . rawurlencode('That Google account has no dashboard access. Use “Create a staff account”, or the email saved in Settings → Login details.'), $this->location($res));
        $this->assertNull((new AdminModel())->where('email', 'stranger@example.com')->first());
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
    }

    public function test_an_unverified_google_email_gets_nothing(): void
    {
        $owner = $this->installWithOwner();
        $this->fakeGoogle($this->profile('owner@example.com', false));
        $this->carriedSession['admin_oauth_intent'] = 'register';
        $res = $this->getJson('/api/admin/oauth/google/callback');
        $this->assertStringStartsWith('/admin/login?error=That%20Google%20account%20has%20no%20dashboard%20access.', $this->location($res));
        $this->assertSame(1, db_connect()->table('admins')->countAllResults());
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
        $this->assertNull($this->admin((int) $owner['id'])['last_login_at']);
    }

    public function test_google_staff_sign_up_is_pending_too(): void
    {
        $this->install();
        $this->fakeGoogle($this->profile('X@example.com'));
        $this->carriedSession['admin_oauth_intent'] = 'register';
        $res = $this->getJson('/api/admin/oauth/google/callback');
        $this->assertSame('/admin/login?notice=' . rawurlencode('Account created. The shop owner needs to approve it before you can log in with Google.'), $this->location($res));
        $this->assertArrayNotHasKey('admin_oauth_intent', $this->carriedSession);
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
        $staff = (new AdminModel())->where('email', 'x@example.com')->first();
        $this->assertSame(['x', 'staff', 'pending', null], [$staff['username'], $staff['role'], $staff['status'], $staff['phone']]);
        $this->assertStringStartsWith('$2y$', $staff['password_hash']);

        // Back again before the owner approved it.
        $this->carriedSession['admin_oauth_intent'] = 'register';
        $res = $this->getJson('/api/admin/oauth/google/callback');
        $this->assertSame('/admin/login?error=' . rawurlencode('Your staff account is waiting for the shop owner’s approval.'), $this->location($res));
        $this->assertSame(2, db_connect()->table('admins')->countAllResults());
    }

    public function test_google_sign_up_picks_a_free_username(): void
    {
        $this->install();
        $this->staff('priya', 'active', 'priya.old@example.com');
        $this->staff('priya2', 'active', 'priya.older@example.com');
        $cases = [
            'priya@example.com'                                                 => 'priya3',
            'Ravi.K+shop@example.com'                                           => 'ravi.kshop',
            '++@example.com'                                                    => 'staff',
            '0@example.com'                                                     => 'staff2',
            str_repeat('abcdefghij', 5) . '@example.com'                        => substr(str_repeat('abcdefghij', 5), 0, 36),
        ];
        foreach ($cases as $email => $username) {
            $this->fakeGoogle($this->profile($email));
            $this->carriedSession['admin_oauth_intent'] = 'register';
            $this->assertStringContainsString('notice=', $this->location($this->getJson('/api/admin/oauth/google/callback')));
            $this->assertSame($username, (new AdminModel())->where('email', strtolower($email))->first()['username'], $email);
        }
    }

    public function test_google_sign_up_needs_an_installed_site(): void
    {
        $this->fakeGoogle($this->profile('x@example.com'));
        $this->carriedSession['admin_oauth_intent'] = 'register';
        $res = $this->getJson('/api/admin/oauth/google/callback');
        $this->assertStringStartsWith('/admin/login?error=That%20Google%20account', $this->location($res));
        $this->assertSame(0, db_connect()->table('admins')->countAllResults());
    }

    public function test_google_refuses_a_switched_off_account(): void
    {
        $this->install();
        $this->staff('meena', 'disabled', 'x@example.com');
        $this->fakeGoogle($this->profile('x@example.com'));
        $res = $this->getJson('/api/admin/oauth/google/callback');
        $this->assertSame('/admin/login?error=' . rawurlencode(self::SWITCHED_OFF), $this->location($res));
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
    }

    public function test_google_then_the_authenticator_code(): void
    {
        $owner    = $this->installWithOwner();
        [$secret] = $this->enableTwoFactor((int) $owner['id']);
        $this->fakeGoogle($this->profile('owner@example.com'));
        $this->getJson('/api/admin/status');
        $token = $this->carriedSession['_token'];

        $res = $this->getJson('/api/admin/oauth/google/callback');
        $this->assertSame('/admin/login', $this->location($res));
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
        $this->assertNotSame($token, $this->carriedSession['_token']);
        $pending = $this->carriedSession['two_factor_pending'];
        $this->assertSame([(int) $owner['id'], true, false], [$pending['id'], $pending['remember'], $pending['starting']]);
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'two_factor_required', true);

        $res = $this->postJson('/api/admin/login/two-factor', ['code' => Totp::code($secret, intdiv(time(), 30))]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.email', 'owner@example.com');
        $this->assertJsonPath($res, 'must_change_password', false);
        $this->assertNotNull($res->response()->getCookie('remember_admin'));
    }
}
