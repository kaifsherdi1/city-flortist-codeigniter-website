<?php

namespace Tests\Feature;

use App\Libraries\Passwords;
use App\Libraries\Totp;
use App\Models\AdminModel;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Two-step login (authenticator codes and backup codes): turning it on and off in Settings, the second login step,
 * its limits, the encrypted secret, florist:admin-recover and the "previous login" notice.
 *
 * @internal
 */
final class TwoFactorTest extends FeatureTestCase
{
    use StreamFilterTrait;

    private const PASSWORD = 'Petals#Hubballi9';

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Laravel's test app starts every test with an empty rate limiter; the shared one here would keep the
        // previous test's cache (and its attempt counts).
        Services::resetSingle('rateLimiter');
        $this->install();
        $admins = new AdminModel();
        $id     = (int) $admins->orderBy('id')->first()['id'];
        $admins->update($id, ['password_hash' => Passwords::hash(self::PASSWORD)]);
        $this->admin = $admins->find($id);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('rateLimiter');
        parent::tearDown();
    }

    /** Turns two-step login on through the API, the way the Settings screen does. Returns [secret, backup codes]. */
    private function enable(): array
    {
        $this->actingAsAdmin($this->admin);
        $res = $this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $secret = str_replace(' ', '', $this->json($res, 'secret'));
        $res    = $this->postJson('/api/admin/settings/two-factor/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.two_factor', true);
        $codes = $this->json($res, 'recovery_codes');
        $this->postJson('/api/admin/logout');

        return [$secret, $codes];
    }

    /** The admin's row as it is in the database now (Laravel's $admin->fresh()). */
    private function fresh(): array
    {
        return (new AdminModel())->find($this->admin['id']);
    }

    /** Runs a command the way `php spark` does (Laravel's $this->artisan()); returns the exit code. */
    private function spark(string $command, array $params = []): int
    {
        $this->resetStreamFilterBuffer();

        return service('commands')->run($command, $params);
    }

    public function test_codes_match_the_rfc_6238_test_vector(): void
    {
        // RFC 6238 appendix B: ASCII secret "12345678901234567890", T = 59 s → 94287082 (last 6 digits: 287082).
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, 1));
        $this->assertSame(1, Totp::verify($secret, '287 082', null, 59));
        $this->assertNull(Totp::verify($secret, '287082', 1, 59), 'the same step must not be accepted twice');
        $this->assertNull(Totp::verify($secret, '000000', null, 59));
    }

    public function test_setup_needs_the_password_and_a_working_code(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->assertStatusCode($this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => 'wrong']), 422);
        $res = $this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertStringStartsWith('otpauth://totp/City%20Florist', $this->json($res, 'uri'));
        $this->assertStatusCode($this->postJson('/api/admin/settings/two-factor/confirm', ['code' => '123456']), 422);
        $this->assertFalse(AdminModel::hasTwoFactor($this->fresh()));
    }

    public function test_login_asks_for_the_code_and_nothing_opens_before_it(): void
    {
        [$secret] = $this->enable();

        $res = $this->postJson('/api/admin/login', ['login' => 'admin', 'password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin', null);
        $this->assertJsonPath($res, 'two_factor_required', true);
        $this->assertStatusCode($this->getJson('/api/admin/dashboard'), 401);

        $this->assertStatusCode($this->postJson('/api/admin/login/two-factor', ['code' => '000000']), 422);
        // The code used to turn it on was already spent; wait for the next step.
        $next = Totp::code($secret, intdiv(time(), 30) + 1);
        $res  = $this->postJson('/api/admin/login/two-factor', ['code' => $next]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.username', 'admin');
        $this->assertStatusCode($this->getJson('/api/admin/dashboard'), 200);

        // The same code can't be used again.
        $this->postJson('/api/admin/logout');
        $this->postJson('/api/admin/login', ['login' => 'admin', 'password' => self::PASSWORD]);
        $this->assertStatusCode($this->postJson('/api/admin/login/two-factor', ['code' => $next]), 422);
    }

    public function test_backup_code_works_once(): void
    {
        [, $codes] = $this->enable();
        $this->assertCount(10, $codes);

        $this->postJson('/api/admin/login', ['login' => 'admin', 'password' => self::PASSWORD]);
        $res = $this->postJson('/api/admin/login/two-factor', ['code' => strtolower($codes[0])]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.backup_codes_left', 9);

        $this->postJson('/api/admin/logout');
        $this->postJson('/api/admin/login', ['login' => 'admin', 'password' => self::PASSWORD]);
        $this->assertStatusCode($this->postJson('/api/admin/login/two-factor', ['code' => $codes[0]]), 422);
    }

    public function test_code_guessing_is_limited(): void
    {
        $this->enable();
        $this->postJson('/api/admin/login', ['login' => 'admin', 'password' => self::PASSWORD]);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/admin/login/two-factor', ['code' => sprintf('%06d', $i)]);
        }
        $this->assertStatusCode($this->postJson('/api/admin/login/two-factor', ['code' => '999999']), 429);
    }

    public function test_second_step_without_a_password_first_is_refused(): void
    {
        $this->assertStatusCode($this->postJson('/api/admin/login/two-factor', ['code' => '123456']), 419);
    }

    public function test_secret_is_stored_encrypted(): void
    {
        [$secret] = $this->enable();
        $raw = db_connect()->table('admins')->select('two_factor_secret')->get()->getRow()->two_factor_secret;
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, AdminModel::twoFactorSecret($this->fresh()));
    }

    public function test_turning_off_needs_password_and_code(): void
    {
        [, $codes] = $this->enable();
        $this->actingAsAdmin($this->fresh());
        $this->assertStatusCode($this->postJson('/api/admin/settings/two-factor/disable', ['current_password' => self::PASSWORD, 'code' => '000000']), 422);
        $res = $this->postJson('/api/admin/settings/two-factor/disable', ['current_password' => self::PASSWORD, 'code' => $codes[1]]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.two_factor', false);
    }

    public function test_developer_can_recover_a_locked_out_owner(): void
    {
        $this->enable();
        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:admin-recover', ['admin', 'two-factor-off' => null]));
        $this->assertFalse(AdminModel::hasTwoFactor($this->fresh()));

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:admin-recover', ['admin', 'reset-password' => null]));
        $this->assertFalse(Passwords::check(self::PASSWORD, $this->fresh()['password_hash']));
        $this->assertSame(EXIT_ERROR, $this->spark('florist:admin-recover', ['nobody', 'two-factor-off' => null]));
    }

    public function test_previous_login_is_shown_after_logging_in(): void
    {
        $this->assertJsonPath($this->postJson('/api/admin/login', ['login' => 'admin', 'password' => self::PASSWORD]), 'previous_login', null);
        $this->postJson('/api/admin/logout');
        $res = $this->fromIp('10.1.2.3')->postJson('/api/admin/login', ['login' => 'admin', 'password' => self::PASSWORD]);
        $this->assertJsonPath($res, 'previous_login.ip', '127.0.0.1');
    }
}
