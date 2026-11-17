<?php

namespace Tests\Feature;

use App\Models\AdminModel;
use Config\Florist;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Port of the Laravel backend's tests/Feature/LoginTest.php: dashboard login (username, email or phone), the
 * single "wrong details" message, remember me, the per-account lockout, forgot/reset password, the account
 * settings that need the current password, and Google sign-in staying off until it is configured.
 *
 * @internal
 */
final class LoginTest extends FeatureTestCase
{
    private array $admin;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        // Laravel boots a fresh application for every test. Here the shared services outlive a test: the rate
        // limiter would keep the first test's cache (and its attempt counts), Google/mail an older Config\Florist.
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }

        $this->install();
        $admins = new AdminModel();
        $first  = $admins->orderBy('id')->first();
        $this->assertNotNull($first);
        (new AdminModel())->update($first['id'], ['email' => 'owner@example.com', 'phone' => '919876543210']);
        $this->admin    = (new AdminModel())->find($first['id']);
        $this->password = config(Florist::class)->adminPassword;
    }

    protected function tearDown(): void
    {
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }
        parent::tearDown();
    }

    public function test_login_with_username_email_or_phone(): void
    {
        foreach (['admin', 'owner@example.com', 'OWNER@example.com', '98765 43210', '+91 98765 43210', '09876543210'] as $login) {
            $res = $this->postJson('/api/admin/login', ['login' => $login, 'password' => $this->password]);
            $this->assertStatusCode($res, 200);
            $this->assertJsonPath($res, 'admin.username', 'admin');
            $this->assertStatusCode($this->postJson('/api/admin/logout'), 200);
        }
    }

    public function test_wrong_details_give_one_message_for_unknown_and_known_accounts(): void
    {
        $res = $this->postJson('/api/admin/login', ['login' => 'nobody@example.com', 'password' => 'x']);
        $this->assertStatusCode($res, 422);
        $a = $this->json($res, 'error');

        $res = $this->postJson('/api/admin/login', ['login' => 'owner@example.com', 'password' => 'x']);
        $this->assertStatusCode($res, 422);
        $b = $this->json($res, 'error');

        $this->assertSame($a, $b);
    }

    public function test_remember_me_sets_a_long_lived_cookie(): void
    {
        $with = $this->postJson('/api/admin/login', ['login' => 'admin', 'password' => $this->password, 'remember' => true]);
        $this->assertNotNull($with->response()->getCookie('remember_admin'));
        $this->assertNotNull((new AdminModel())->find($this->admin['id'])['remember_token']);

        $this->postJson('/api/admin/logout');
        $without = $this->postJson('/api/admin/login', ['login' => 'admin', 'password' => $this->password]);
        $this->assertNull($without->response()->getCookie('remember_admin'));
    }

    public function test_account_lockout_also_applies_per_account(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->fromIp("10.0.0.{$i}")->postJson('/api/admin/login', ['login' => 'admin', 'password' => "guess{$i}"]);
        }
        $res = $this->fromIp('10.0.0.99')->postJson('/api/admin/login', ['login' => 'admin', 'password' => $this->password]);
        $this->assertStatusCode($res, 429);
    }

    public function test_forgot_and_reset_password(): void
    {
        // Notification::fake(): emails go to CodeIgniter's MockEmail and are collected in $this->emails().
        $res = $this->postJson('/api/admin/password/forgot', ['email' => 'stranger@example.com']);
        $this->assertStatusCode($res, 200);
        // Notification::assertNothingSent()
        $this->assertSame([], $this->emails());

        $res = $this->postJson('/api/admin/password/forgot', ['email' => 'owner@example.com']);
        $this->assertStatusCode($res, 200);
        $msg = $this->json($res, 'message');
        $this->assertStringContainsString('If that email belongs', $msg);

        // Notification::assertSentTo($this->admin, ResetPassword::class, …): at least one reset email went to this
        // admin; the token is the one in its reset link.
        $token = null;
        $sent  = array_filter($this->emails(), fn (array $mail): bool => in_array($this->admin['email'], (array) ($mail['recipients'] ?? []), true));
        $this->assertNotEmpty($sent, 'No reset email was sent to the admin.');
        foreach ($sent as $mail) {
            $this->assertStringContainsString('/admin/reset-password?token=', $mail['body']);
            $this->assertSame(1, preg_match('~/admin/reset-password\?token=([^&"\'\s<]+)~', $mail['body'], $m));
            $token = $m[1];
        }

        $this->assertStatusCode($this->postJson('/api/admin/password/reset', ['token' => 'wrong', 'email' => 'owner@example.com', 'password' => 'Fresh@2026', 'password_confirmation' => 'Fresh@2026']), 422);
        $this->assertStatusCode($this->postJson('/api/admin/password/reset', ['token' => $token, 'email' => 'owner@example.com', 'password' => 'short', 'password_confirmation' => 'short']), 422);
        $this->assertStatusCode($this->postJson('/api/admin/password/reset', ['token' => $token, 'email' => 'owner@example.com', 'password' => 'Fresh@2026', 'password_confirmation' => 'Fresh@2026']), 200);

        $this->assertStatusCode($this->postJson('/api/admin/login', ['login' => 'admin', 'password' => $this->password]), 422);
        $this->assertStatusCode($this->postJson('/api/admin/login', ['login' => 'owner@example.com', 'password' => 'Fresh@2026']), 200);
        // A token works once.
        $this->assertStatusCode($this->postJson('/api/admin/password/reset', ['token' => $token, 'email' => 'owner@example.com', 'password' => 'Again@2026', 'password_confirmation' => 'Again@2026']), 422);
    }

    public function test_account_details_need_the_current_password(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->assertStatusCode($this->putJson('/api/admin/settings/account', ['username' => 'owner', 'email' => 'new@example.com', 'phone' => '9123456789', 'current_password' => 'wrong']), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/account', ['username' => 'a b', 'current_password' => $this->password]), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/account', ['username' => 'owner', 'email' => 'bad', 'current_password' => $this->password]), 422);

        $res = $this->putJson('/api/admin/settings/account', ['username' => 'owner', 'email' => 'New@Example.com', 'phone' => '91234 56789', 'current_password' => $this->password]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.email', 'new@example.com');
        $this->assertJsonPath($res, 'admin.phone', '919123456789');
    }

    public function test_google_sign_in_is_off_until_configured(): void
    {
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'login_options.google', false);
        $res = $this->getJson('/api/admin/oauth/google');
        // assertRedirect(): a redirect status; assertRedirectContains(): the Location header contains it.
        $this->assertStatusCode($res, 302);
        $this->assertStringContainsString('/admin/login?error=', $res->response()->getHeaderLine('Location'));

        $config                     = config(Florist::class);
        $config->googleClientId     = 'id';
        $config->googleClientSecret = 'secret';
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'login_options.google', true);
        $res = $this->getJson('/api/admin/oauth/google');
        // assertRedirectContains() also requires a redirect status.
        $this->assertStatusCode($res, 302);
        $this->assertStringContainsString('accounts.google.com', $res->response()->getHeaderLine('Location'));
    }
}
