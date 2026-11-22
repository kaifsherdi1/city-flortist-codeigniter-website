<?php

namespace Tests\Feature;

use App\Libraries\GoogleOAuth;
use App\Libraries\PasswordReset;
use App\Libraries\Text;
use Config\Florist;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * The live layout: website on example.com, dashboard on admin.example.com, this API on api.example.com
 * (florist.separateDomains). The other tests cover the one-domain layout.
 *
 * @internal
 */
final class SeparateDomainsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('googleOAuth');
        $this->install();

        config('App')->baseURL = 'https://api.example.com/';
        $florist                  = config(Florist::class);
        $florist->siteUrl         = 'https://example.com';
        $florist->adminUrl        = 'https://admin.example.com';
        $florist->separateDomains = true;
    }

    public function test_the_website_and_dashboard_may_call_the_api_with_cookies(): void
    {
        foreach (['https://example.com', 'https://www.example.com', 'https://admin.example.com'] as $origin) {
            $res = $this->withHeader('Origin', $origin)->getJson('/api/site');
            $this->assertStatusCode($res, 200);
            $this->assertSame($origin, $res->response()->getHeaderLine('Access-Control-Allow-Origin'));
            $this->assertSame('true', $res->response()->getHeaderLine('Access-Control-Allow-Credentials'));
            $this->assertStringContainsString('Origin', $res->response()->getHeaderLine('Vary'));
        }
    }

    public function test_other_sites_get_no_cors_access(): void
    {
        $res = $this->withHeader('Origin', 'https://evil.example')->getJson('/api/site');
        $this->assertStatusCode($res, 200);
        $this->assertFalse($res->response()->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_errors_decided_before_the_controller_are_readable_too(): void
    {
        // 419 from the session filter: the dashboard must be able to read it to fetch a new token and retry.
        $res = $this->withHeader('Origin', 'https://admin.example.com')->withoutXsrfToken()->postJson('/api/admin/login', ['login' => 'x', 'password' => 'y']);
        $this->assertStatusCode($res, 419);
        $this->assertSame('https://admin.example.com', $res->response()->getHeaderLine('Access-Control-Allow-Origin'));

        $res = $this->withHeader('Origin', 'https://admin.example.com')->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 401);
        $this->assertSame('https://admin.example.com', $res->response()->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function test_the_preflight_is_answered(): void
    {
        $this->withHeader('Origin', 'https://admin.example.com')->withHeader('Access-Control-Request-Method', 'PUT');
        $res = $this->send('OPTIONS', '/api/admin/settings/shop');

        $this->assertStatusCode($res, 204);
        $this->assertSame('https://admin.example.com', $res->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('PUT', $res->response()->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('X-XSRF-TOKEN', $res->response()->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function test_photo_urls_point_at_the_api_domain(): void
    {
        $this->assertSame('https://api.example.com/uploads/products/a.webp', Text::publicPath('uploads/products/a.webp'));
        $this->assertNull(Text::publicPath(null));
    }

    public function test_reset_links_and_google_redirects_go_to_the_right_domain(): void
    {
        $this->assertSame('https://admin.example.com/reset-password?token=t&email=a%40b.c', PasswordReset::url('t', 'a@b.c'));

        $this->assertSame('https://admin.example.com/login?error=x', config(Florist::class)->adminPage('login?error=x'));
        $this->assertSame('https://example.com/account', config(Florist::class)->websitePage('/account'));

        // Google not set up: straight back to the dashboard's login page on its own subdomain.
        $res = $this->getJson('/api/admin/oauth/google');
        $this->assertStatusCode($res, 302);
        $this->assertStringStartsWith('https://admin.example.com/login?error=', $res->response()->getHeaderLine('Location'));

    }

    public function test_google_callbacks_are_on_the_api_domain(): void
    {
        $google = new GoogleOAuth(config(Florist::class));
        $this->assertSame('https://api.example.com/api/admin/oauth/google/callback', $google->adminCallbackUrl());
    }

    public function test_page_urls_on_the_api_domain_redirect_to_the_sites(): void
    {
        $res = $this->send('GET', '/shop');
        $this->assertStatusCode($res, 302);
        $this->assertSame('https://example.com/shop', $res->response()->getHeaderLine('Location'));

        $res = $this->send('GET', '/admin/orders');
        $this->assertStatusCode($res, 302);
        $this->assertSame('https://admin.example.com/orders', $res->response()->getHeaderLine('Location'));
    }

    public function test_one_domain_layout_is_unchanged_when_switched_off(): void
    {
        config(Florist::class)->separateDomains = false;

        $res = $this->withHeader('Origin', 'https://example.com')->getJson('/api/site');
        $this->assertFalse($res->response()->hasHeader('Access-Control-Allow-Origin'));
        $this->assertSame('/uploads/a.webp', Text::publicPath('uploads/a.webp'));
        $this->assertSame('/admin/login', config(Florist::class)->adminPage('login'));
    }
}
