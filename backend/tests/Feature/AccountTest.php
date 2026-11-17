<?php

namespace Tests\Feature;

use App\Libraries\GoogleOAuth;
use App\Libraries\Passwords;
use App\Models\AdminModel;
use App\Models\CategoryModel;
use App\Models\CustomerModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\TestResponse;
use Config\Florist;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Port of the Laravel backend's tests/Feature/AccountTest.php: customer accounts on the website, and staff
 * sign-ups for the dashboard.
 *
 * @internal
 */
final class AccountTest extends FeatureTestCase
{
    private const PASSWORD = 'Roses2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Laravel boots a fresh application for every test. Here the shared services outlive a test: the rate
        // limiter would keep the first test's cache (and its attempt counts), Google/mail an older Config\Florist
        // or a fake injected by an earlier test.
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

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'username' => 'priya_p', 'email' => 'Priya@Example.com', 'phone' => '98765 43210',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ];
    }

    // ---------------------------------------------------------------- customers

    public function test_customer_registers_and_is_logged_in(): void
    {
        $res = $this->postJson('/api/account/register', $this->form());
        $this->assertStatusCode($res, 201);
        $this->assertJsonPath($res, 'customer.username', 'priya_p');
        $this->assertJsonPath($res, 'customer.email', 'priya@example.com');
        $this->assertJsonPath($res, 'customer.phone', '919876543210');
        $this->assertJsonMissingPath($res, 'customer.password_hash');
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer.username', 'priya_p');
        $this->assertTrue(Passwords::check(self::PASSWORD, (string) $this->firstCustomer()['password_hash']));
    }

    public function test_register_form_is_checked(): void
    {
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['username' => 'a b'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['email' => 'nope'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['phone' => '123'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['password' => 'short1', 'password_confirmation' => 'short1'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'])), 422);
        $res = $this->postJson('/api/account/register', $this->form(['password_confirmation' => 'Different99']));
        $this->assertStatusCode($res, 422);
        $this->assertJsonPath($res, 'error', 'The two passwords don’t match.');
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['website' => 'bot'])), 400);
        $this->assertSame(0, (new CustomerModel())->countAllResults());
    }

    public function test_username_email_and_phone_must_be_unique(): void
    {
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form()), 201);
        $this->postJson('/api/account/logout');
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['email' => 'other@example.com', 'phone' => '9123456789'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['username' => 'other', 'phone' => '9123456789'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['username' => 'other', 'email' => 'other@example.com'])), 422);
        $this->assertSame(1, (new CustomerModel())->countAllResults());
    }

    public function test_login_with_username_email_or_phone_and_logout(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $this->assertStatusCode($this->postJson('/api/account/logout'), 200);
        // $this->app['auth']->forgetGuards(): nothing to do, every request reads the login from the session again.

        foreach (['priya_p', 'PRIYA@example.com', '98765 43210'] as $login) {
            $res = $this->postJson('/api/account/login', ['login' => $login, 'password' => self::PASSWORD]);
            $this->assertStatusCode($res, 200);
            $this->assertJsonPath($res, 'customer.username', 'priya_p');
            $this->postJson('/api/account/logout');
        }
        $this->assertStatusCode($this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => 'Wrong12345']), 422);
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer', null);
    }

    public function test_customer_login_never_opens_the_dashboard(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $this->assertStatusCode($this->getJson('/api/admin/dashboard'), 401);
        $this->assertJsonPath($this->getJson('/api/admin/status'), 'admin', null);
    }

    public function test_order_history_shows_only_the_customers_own_orders(): void
    {
        $cat = (new CategoryModel())->orderBy('id')->first();
        $this->assertNotNull($cat); // Category::firstOrFail()
        $productId = (int) (new ProductModel())->insert(['category_id' => $cat['id'], 'name' => 'Rose Box', 'slug' => 'rose-box', 'price' => 900, 'is_active' => true]);
        // Someone else ordered with the same phone number before this account existed: not theirs to see.
        (new OrderModel())->insert(['product_name' => 'Other', 'price_at_order' => 1, 'customer_name' => 'X', 'customer_phone' => '919876543210', 'status' => 'new']);

        $this->assertStatusCode($this->getJson('/api/account/orders'), 401);
        $this->postJson('/api/account/register', $this->form());
        $this->assertStatusCode($this->postJson('/api/account/orders', ['product_id' => $productId, 'name' => 'Priya', 'phone' => '9876543210']), 201);

        $res = $this->getJson('/api/account/orders');
        $this->assertStatusCode($res, 200);
        $this->assertJsonCount($res, 1, 'orders');
        $this->assertJsonPath($res, 'orders.0.product_name', 'Rose Box');
        $this->assertSame(
            $this->firstCustomer()['id'],
            db_connect()->table('orders')->select('customer_id')->where('product_name', 'Rose Box')->get()->getRow()?->customer_id,
        );
    }

    public function test_google_sign_in_creates_or_links_the_customer(): void
    {
        $this->configureGoogle();
        $this->mockGoogle('g-123', 'priya@example.com');
        $this->assertRedirectsTo($this->getJson('/api/account/oauth/google/callback'), '/account');
        $this->assertSame('priya', $this->firstCustomer()['username']);
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer.google', true);

        // A Google account without a password can't use the password form.
        $this->postJson('/api/account/logout');
        $res = $this->postJson('/api/account/login', ['login' => 'priya@example.com', 'password' => 'Anything123']);
        $this->assertStatusCode($res, 422);
        $this->assertJsonPath($res, 'error', static fn ($e) => str_contains($e, 'Google'));
    }

    public function test_google_is_off_until_configured(): void
    {
        $this->assertJsonPath($this->getJson('/api/account/status'), 'google', false);
        $this->assertRedirectsContaining($this->getJson('/api/account/oauth/google'), '/login?error=');
    }

    public function test_sign_ups_are_limited_per_device(): void
    {
        // Mistakes don't count: a few wrong tries first, then 5 real sign-ups are allowed.
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['password_confirmation' => 'Typo12345'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['email' => 'nope'])), 422);
        for ($i = 0; $i < 5; $i++) {
            $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['username' => "user{$i}", 'email' => "user{$i}@example.com", 'phone' => '98765432' . (10 + $i)])), 201);
            $this->postJson('/api/account/logout');
        }
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['username' => 'user9', 'email' => 'user9@example.com', 'phone' => '9876543299'])), 429);
    }

    // ---------------------------------------------------------------- staff

    public function test_staff_sign_up_stays_locked_until_the_owner_approves(): void
    {
        $this->assertStatusCode($this->postJson('/api/admin/register', $this->form(['username' => 'ravi', 'email' => 'ravi@example.com'])), 201);
        $staff = (new AdminModel())->where('username', 'ravi')->first();
        $this->assertNotNull($staff); // firstOrFail()
        $this->assertSame(['staff', 'pending'], [$staff['role'], $staff['status']]);

        // Nobody is logged in by signing up, and the correct password still can't get in.
        $this->assertStatusCode($this->getJson('/api/admin/dashboard'), 401);
        $res = $this->postJson('/api/admin/login', ['login' => 'ravi', 'password' => self::PASSWORD]);
        $this->assertStatusCode($res, 403);
        $this->assertJsonPath($res, 'error', static fn ($e) => str_contains($e, 'approval'));

        // Owner approves.
        $owner = $this->owner();
        $this->assertJsonPath($this->actingAsAdmin($owner)->getJson('/api/admin/status'), 'pending_staff', 1);
        $res = $this->patchJson("/api/admin/team/{$staff['id']}", ['status' => 'active']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'pending', 0);
        $this->postJson('/api/admin/logout');
        // $this->app['auth']->forgetGuards(): nothing to do, every request reads the login from the session again.

        $res = $this->postJson('/api/admin/login', ['login' => 'ravi', 'password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.role', 'staff');
        $this->assertStatusCode($this->getJson('/api/admin/products'), 200);
    }

    public function test_staff_cannot_change_shop_settings_or_the_team(): void
    {
        $staffId = (int) (new AdminModel())->insert(['username' => 'ravi', 'email' => 'ravi@example.com', 'password_hash' => Passwords::hash(self::PASSWORD), 'role' => 'staff', 'status' => 'active']);
        $this->actingAsAdmin($staffId);
        $this->assertStatusCode($this->getJson('/api/admin/orders'), 200);
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', ['whatsapp_number' => '919999999999']), 403);
        $this->assertStatusCode($this->postJson('/api/admin/settings/categories', []), 403);
        $this->assertStatusCode($this->getJson('/api/admin/team'), 403);
        $owner = $this->owner();
        $this->assertStatusCode($this->patchJson("/api/admin/team/{$owner['id']}", ['status' => 'disabled']), 403);
    }

    public function test_switching_off_a_staff_account_ends_their_access(): void
    {
        $staffId = (int) (new AdminModel())->insert(['username' => 'ravi', 'email' => 'ravi@example.com', 'password_hash' => Passwords::hash(self::PASSWORD), 'role' => 'staff', 'status' => 'active']);
        $owner   = $this->owner();

        $this->assertStatusCode($this->actingAsAdmin($owner)->patchJson("/api/admin/team/{$staffId}", ['status' => 'disabled']), 200);
        $this->assertStatusCode($this->patchJson("/api/admin/team/{$owner['id']}", ['status' => 'disabled']), 403); // can't lock out the owner

        $staff = (new AdminModel())->find($staffId); // $staff->fresh()
        $this->assertNotNull($staff);
        $this->assertStatusCode($this->actingAsAdmin($staff)->getJson('/api/admin/products'), 401);
        // $this->app['auth']->forgetGuards(): nothing to do, every request reads the login from the session again.
        $this->assertStatusCode($this->postJson('/api/admin/login', ['login' => 'ravi', 'password' => self::PASSWORD]), 403);
    }

    public function test_google_staff_sign_up_is_pending_too(): void
    {
        $this->configureGoogle();
        $this->mockGoogle('g-9', 'new.staff@example.com');
        $this->carriedSession['admin_oauth_intent'] = 'register'; // withSession([...])
        $this->assertRedirectsContaining($this->getJson('/api/admin/oauth/google/callback'), 'notice=');
        $this->assertSame('pending', db_connect()->table('admins')->select('status')->where('email', 'new.staff@example.com')->get()->getRow()?->status);

        // Without the sign-up intent, an unknown Google account gets nothing.
        $this->mockGoogle('g-10', 'stranger@example.com');
        $this->assertRedirectsContaining($this->getJson('/api/admin/oauth/google/callback'), 'error=');
        $this->assertNull((new AdminModel())->where('email', 'stranger@example.com')->first());
    }

    // ---------------------------------------------------------------- helpers

    /** Customer::first() */
    private function firstCustomer(): array
    {
        $customer = (new CustomerModel())->orderBy('id')->first();
        $this->assertNotNull($customer, 'No customer was saved.');

        return $customer;
    }

    /** Admin::where('role', 'owner')->firstOrFail() */
    private function owner(): array
    {
        $owner = (new AdminModel())->where('role', 'owner')->orderBy('id')->first();
        $this->assertNotNull($owner, 'No owner account.');

        return $owner;
    }

    /** config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']) */
    private function configureGoogle(): void
    {
        $config                     = config(Florist::class);
        $config->googleClientId     = 'id';
        $config->googleClientSecret = 'secret';
    }

    /**
     * The Socialite mock: Google answers with this account (verified email, no name). Only the call to Google is
     * replaced; whether Google is switched on still comes from the configuration, as in the Laravel test.
     */
    private function mockGoogle(string $id, string $email): void
    {
        Services::injectMock('googleOAuth', new class (config(Florist::class), ['id' => $id, 'email' => $email, 'email_verified' => true, 'name' => '']) extends GoogleOAuth {
            public function __construct(Florist $config, private readonly array $profile)
            {
                parent::__construct($config);
            }

            public function user(string $callbackUrl, IncomingRequest $request): array
            {
                return $this->profile;
            }
        });
    }

    /** Laravel's assertRedirect($uri): a redirect whose (absolute) Location is that address. */
    private function assertRedirectsTo(TestResponse $res, string $uri): void
    {
        $this->assertStatusCode($res, 302);
        $this->assertStringEndsWith($uri, $res->response()->getHeaderLine('Location'));
    }

    /** Laravel's assertRedirectContains($part): a redirect whose Location contains it. */
    private function assertRedirectsContaining(TestResponse $res, string $part): void
    {
        $this->assertStatusCode($res, 302);
        $this->assertStringContainsString($part, $res->response()->getHeaderLine('Location'));
    }
}
