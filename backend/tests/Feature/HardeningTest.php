<?php

namespace Tests\Feature;

use App\Libraries\ClientIp;
use App\Libraries\GoogleOAuth;
use App\Models\AdminModel;
use App\Models\CustomerModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Config\Florist;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Protections added on top of the Laravel version, found by the security review of the CodeIgniter port.
 *
 * @internal
 */
final class HardeningTest extends FeatureTestCase
{
    private function requestFrom(string $remote, ?string $forwardedFor = null): IncomingRequest
    {
        service('superglobals')->setServer('REMOTE_ADDR', $remote);
        $config  = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'api/site'), null, new UserAgent());
        if ($forwardedFor !== null) {
            $request->setHeader('X-Forwarded-For', $forwardedFor);
        }

        return $request;
    }

    public function test_without_trusted_proxies_x_forwarded_for_is_ignored(): void
    {
        config(App::class)->proxyIPs = [];
        $this->assertSame('203.0.113.7', ClientIp::of($this->requestFrom('203.0.113.7', '1.2.3.4')));
    }

    public function test_behind_a_trusted_proxy_the_visitor_cannot_pick_their_ip(): void
    {
        // A CDN at 10.0.0.0/8 appends the real client (198.51.100.20) after whatever the visitor sent.
        config(App::class)->proxyIPs = ['10.0.0.0/8' => 'X-Forwarded-For'];
        $this->assertSame('198.51.100.20', ClientIp::of($this->requestFrom('10.1.2.3', '1.2.3.4, 198.51.100.20')));
        $this->assertSame('198.51.100.20', ClientIp::of($this->requestFrom('10.1.2.3', '198.51.100.20')));
        // Several trusted hops: still the first address no proxy of ours vouches for.
        $this->assertSame('198.51.100.20', ClientIp::of($this->requestFrom('10.1.2.3', 'evil, 198.51.100.20, 10.9.9.9')));
        // IPv6 ranges work too.
        config(App::class)->proxyIPs = ['2001:db8::/32' => 'X-Forwarded-For'];
        $this->assertSame('2001:4860::8888', ClientIp::of($this->requestFrom('2001:db8::1', '1.2.3.4, 2001:4860::8888')));
        // A request that doesn't come from a trusted proxy keeps its own address.
        $this->assertSame('192.0.2.50', ClientIp::of($this->requestFrom('192.0.2.50', '1.2.3.4')));
    }

    public function test_retyping_the_login_another_way_does_not_reset_the_account_lock(): void
    {
        $this->install();
        $admin = (new AdminModel())->orderBy('id')->first();
        (new AdminModel())->update($admin['id'], ['email' => 'owner@example.com', 'phone' => '919876543210']);

        $spellings = ['admin', 'ADMIN', 'Admin', 'owner@example.com', 'OWNER@example.com', '98765 43210', '+91 98765 43210', '09876543210'];
        foreach ($spellings as $i => $login) {
            $this->fromIp("10.0.0.{$i}");
            $this->assertStatusCode($this->postJson('/api/admin/login', ['login' => $login, 'password' => "guess{$i}"]), 422);
        }
        $this->fromIp('10.0.0.99');
        $res = $this->postJson('/api/admin/login', ['login' => '9876543210', 'password' => config(Florist::class)->adminPassword]);
        $this->assertStatusCode($res, 429);
    }

    public function test_customer_lock_follows_the_account_too(): void
    {
        $this->install();
        $this->postJson('/api/account/register', [
            'username' => 'priya_p', 'email' => 'priya@example.com', 'phone' => '9876543210',
            'password' => 'Roses2026', 'password_confirmation' => 'Roses2026',
        ]);
        $this->newBrowser();
        foreach (['priya_p', 'PRIYA_P', 'priya@example.com', '98765 43210', '+91 98765 43210', '09876543210', 'Priya_P', 'PRIYA@EXAMPLE.COM'] as $i => $login) {
            $this->fromIp("10.0.1.{$i}");
            $this->assertStatusCode($this->postJson('/api/account/login', ['login' => $login, 'password' => "Wrong{$i}x"]), 422);
        }
        $this->fromIp('10.0.1.99');
        $this->assertStatusCode($this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => 'Roses2026']), 429);
    }

    public function test_google_linking_an_account_made_with_a_password_ends_that_password(): void
    {
        $this->install();
        // Someone registers with an email that isn't theirs and a password they know.
        $this->postJson('/api/account/register', [
            'username' => 'squatter', 'email' => 'victim@example.com', 'phone' => '9876543210',
            'password' => 'Roses2026', 'password_confirmation' => 'Roses2026', 'remember' => true,
        ]);
        $customer = (new CustomerModel())->where('email', 'victim@example.com')->first();
        $this->assertNotEmpty($customer['remember_token']);

        // The real owner of victim@example.com uses "Continue with Google".
        $florist                     = config(Florist::class);
        $florist->googleClientId     = 'id';
        $florist->googleClientSecret = 'secret';
        Services::injectMock('googleOAuth', new class ($florist) extends GoogleOAuth {
            public function user(string $callbackUrl, $request): array
            {
                return ['id' => 'g-victim', 'email' => 'victim@example.com', 'email_verified' => true, 'name' => 'Victim'];
            }
        });
        $this->newBrowser();
        $res = $this->getJson('/api/account/oauth/google/callback');
        $this->assertSame('/account', $this->location($res));

        $linked = (new CustomerModel())->find($customer['id']);
        $this->assertSame('g-victim', $linked['google_id']);
        $this->assertNull($linked['password_hash']);
        $this->assertNotSame($customer['remember_token'], $linked['remember_token']);

        // The squatter's password no longer opens the account.
        $this->newBrowser();
        $res = $this->postJson('/api/account/login', ['login' => 'squatter', 'password' => 'Roses2026']);
        $this->assertStatusCode($res, 422);
        $this->assertJsonPath($res, 'error', static fn ($e) => str_contains($e, 'Google'));
    }
}
