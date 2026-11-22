<?php

namespace Tests\Feature;

use App\Libraries\ClientIp;
use App\Models\AdminModel;
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


}
