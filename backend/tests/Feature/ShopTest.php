<?php

namespace Tests\Feature;

use App\Libraries\Installer;
use App\Libraries\Passwords;
use App\Models\AdminModel;
use App\Models\CategoryModel;
use App\Models\OfferModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use Config\Florist;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Port of the Laravel backend's tests/Feature/ShopTest.php: the install, pricing (sale price, offers, prices on
 * request, wholesale), orders and their WhatsApp message, the dashboard login, bulk edit, the product form,
 * order status, shop settings, unsafe links, the CSV export and the login limit.
 *
 * @internal
 */
final class ShopTest extends FeatureTestCase
{
    private array $cakes;

    protected function setUp(): void
    {
        parent::setUp();
        // Laravel boots a fresh application (and an empty array cache) for every test. Here the shared services
        // outlive a test: the rate limiter would keep an earlier test's attempt counts.
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }

        // Installs without the starter photos, so tests don't write to public/uploads.
        $this->install();
        $cakes = (new CategoryModel())->where('slug', 'cakes')->first();
        $this->assertNotNull($cakes);
        $this->cakes = $cakes;
    }

    protected function tearDown(): void
    {
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }
        parent::tearDown();
    }

    private function product(array $attrs = []): array
    {
        $products = new ProductModel();
        $id       = $products->insert($attrs + [
            'category_id' => $this->cakes['id'],
            'name'        => 'Truffle Cake',
            'slug'        => $products->uniqueSlug($attrs['name'] ?? 'Truffle Cake'),
            'price'       => 1000,
            'is_active'   => true,
        ]);

        return (new ProductModel())->find($id);
    }

    private function login(): static
    {
        $admin = (new AdminModel())->orderBy('id')->first();
        $this->assertNotNull($admin);

        return $this->actingAsAdmin($admin);
    }

    public function test_install_creates_admin_categories_and_settings_once(): void
    {
        $this->assertTrue(Installer::installed());
        $this->assertSame(8, (new CategoryModel())->countAllResults());
        $res = $this->getJson('/api/site');
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'settings.shop_name', 'City Florist');
        $this->assertStatusCode($this->postJson('/api/admin/install', ['seed' => false]), 403);
    }

    public function test_pricing_uses_sale_price_and_best_running_offer(): void
    {
        $p   = $this->product(['sale_price' => 900]);
        $res = $this->getJson("/api/products?slug={$p['slug']}");
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'product.pricing', ['price' => 1000, 'final' => 900, 'discount_pct' => 10, 'offer' => null, 'on_request' => false]);

        (new OfferModel())->insert(['title' => 'Cake week', 'discount_pct' => 10, 'category_id' => $this->cakes['id'], 'starts_at' => date('Y-m-d H:i:s', time() - 3600), 'ends_at' => date('Y-m-d H:i:s', time() + 86400), 'is_active' => true]);
        (new OfferModel())->insert(['title' => 'Ended', 'discount_pct' => 50, 'starts_at' => date('Y-m-d H:i:s', time() - 3 * 86400), 'ends_at' => date('Y-m-d H:i:s', time() - 86400), 'is_active' => true]);

        $res = $this->getJson("/api/products?slug={$p['slug']}");
        $this->assertJsonPath($res, 'product.pricing.final', 810);
        $this->assertJsonPath($res, 'product.pricing.offer', 'Cake week');
        $this->assertJsonCount($this->getJson('/api/site'), 1, 'offers');
    }

    public function test_unpriced_product_is_on_request(): void
    {
        $p = $this->product(['price' => 0]);
        $this->assertJsonPath($this->getJson("/api/products?slug={$p['slug']}"), 'product.pricing.on_request', true);
    }

    public function test_wholesale_flowers_never_show_a_price(): void
    {
        $wholesale = (new CategoryModel())->where('slug', 'wholesale-flowers')->first();
        $this->assertNotNull($wholesale);
        $p = $this->product(['category_id' => $wholesale['id'], 'name' => 'Roses', 'slug' => 'roses', 'price' => 399]);

        $res = $this->getJson("/api/products?slug={$p['slug']}");
        $this->assertJsonPath($res, 'product.pricing.on_request', true);
        $this->assertJsonPath($res, 'product.pricing.final', 0);
        $this->assertJsonPath($this->getJson('/api/site'), 'wholesale.0.pricing.on_request', true);

        $res = $this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => 'Asha', 'phone' => '9876543210']);
        $this->assertStatusCode($res, 201);
        $this->assertStringContainsString('Please share the price', $this->json($res, 'message'));
        $this->assertStringNotContainsString('399', $this->json($res, 'message'));
    }

    public function test_order_takes_price_from_database_and_builds_whatsapp_link(): void
    {
        $p   = $this->product();
        $res = $this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => ' Asha  K ', 'phone' => '098765 43210', 'price' => 1, 'delivery_date' => '2026-12-01']);
        $this->assertStatusCode($res, 201);

        $order = (new OrderModel())->orderBy('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(1000.0, $order['price_at_order']);
        $this->assertSame('Asha K', $order['customer_name']);
        $this->assertSame('919876543210', $order['customer_phone']);
        $this->assertStringStartsWith('https://wa.me/919900094843?text=', $this->json($res, 'whatsapp'));
        $this->assertStringContainsString('*Price:* ₹1,000', $this->json($res, 'message'));
        // Just the order details: no photo link and no product-page link.
        $this->assertStringNotContainsString('*Photo:*', $this->json($res, 'message'));
        $this->assertStringNotContainsString('http', $this->json($res, 'message'));
    }

    public function test_order_validation_and_honeypot(): void
    {
        $p   = $this->product();
        $res = $this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => 'A', 'phone' => '9876543210']);
        $this->assertStatusCode($res, 422);
        $this->assertJsonPath($res, 'error', 'Please enter your name.');
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => 'Asha', 'phone' => '12345']), 422);
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => 999, 'name' => 'Asha', 'phone' => '9876543210']), 404);
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => 'Asha', 'phone' => '9876543210', 'website' => 'x']), 400);
        $this->assertSame(0, (new OrderModel())->countAllResults());
    }

    public function test_dashboard_api_requires_login(): void
    {
        $res = $this->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 401);
        $this->assertJsonPath($res, 'error', 'Please log in again.');
        $this->assertStatusCode($this->postJson('/api/admin/login', ['username' => 'admin', 'password' => 'wrong']), 422);
        // The starting password only opens "choose a new password" (see SecurityTest), so use the owner's own one.
        $admin = (new AdminModel())->orderBy('id')->first();
        $this->assertNotNull($admin);
        (new AdminModel())->update($admin['id'], ['password_hash' => Passwords::hash('Petals#Hubballi9')]);
        $res = $this->postJson('/api/admin/login', ['username' => 'admin', 'password' => 'Petals#Hubballi9']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.username', 'admin');
        $res = $this->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'stats.new_orders', 0);
        $this->assertJsonCount($res, 30, 'days');
        $this->assertJsonPath($res, 'goals.products', 0);
        $this->assertJsonCount($res, 24, 'hours');
        $this->assertJsonCount($res, 12, 'months');
        $this->assertStatusCode($this->putJson('/api/admin/dashboard/target', ['target' => 0]), 422);
        $this->assertStatusCode($this->putJson('/api/admin/dashboard/target', ['target' => 80]), 200);
        $this->assertJsonPath($this->getJson('/api/admin/dashboard'), 'target', 80);
    }

    public function test_bulk_edit_saves_prices_and_ready_to_go(): void
    {
        $a = $this->product(['name' => 'Cake A']);
        $b = $this->product(['name' => 'Cake B']);

        $res = $this->login()->postJson('/api/admin/products/bulk', ['rows' => [
            $a['id'] => ['name' => 'Cake A+', 'price' => '1200', 'sale' => '999', 'active' => true, 'featured' => true, 'ready' => true],
            $b['id'] => ['name' => 'Cake B', 'price' => '0', 'sale' => '', 'active' => false, 'featured' => false, 'ready' => false],
        ]]);
        $this->assertStatusCode($res, 200);

        $a = (new ProductModel())->find($a['id']);
        $this->assertSame(['Cake A+', 'cake-a', 1200.0, 999.0, true], [$a['name'], $a['slug'], $a['price'], $a['sale_price'], $a['is_featured']]);
        $this->assertFalse((new ProductModel())->find($b['id'])['is_active']);
        $res = $this->getJson('/api/site');
        $this->assertJsonPath($res, 'ready.0.id', $a['id']);
        $this->assertJsonCount($res, 1, 'ready');
    }

    public function test_product_form_validation(): void
    {
        $this->assertStatusCode($this->login()->postJson('/api/admin/products', ['name' => '', 'category_id' => $this->cakes['id']]), 422);
        $this->assertJsonPath($this->postJson('/api/admin/products', ['name' => 'X', 'category_id' => 999]), 'error', 'Please choose a category.');
        $this->assertStatusCode($this->postJson('/api/admin/products', ['name' => 'X', 'category_id' => $this->cakes['id'], 'price' => 500, 'sale_price' => 600]), 422);
        $res = $this->postJson('/api/admin/products', ['name' => 'Rose Box', 'category_id' => $this->cakes['id'], 'price' => 500, 'is_active' => '1']);
        $this->assertStatusCode($res, 201);
        $this->assertJsonPath($res, 'product.slug', 'rose-box');
    }

    public function test_order_status_and_settings(): void
    {
        $orderId = (new OrderModel())->insert(['product_name' => 'Cake', 'customer_name' => 'Asha', 'customer_phone' => '919876543210', 'status' => 'new']);

        $res = $this->login()->patchJson("/api/admin/orders/{$orderId}", ['status' => 'done']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'new_orders', 0);
        $this->assertStatusCode($this->patchJson("/api/admin/orders/{$orderId}", ['status' => 'nope']), 422);

        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', ['whatsapp_number' => '98765 43210', 'map_embed' => '<iframe src="https://www.google.com/maps/embed?pb=e"></iframe>']), 200);
        $res = $this->getJson('/api/site');
        $this->assertJsonPath($res, 'settings.whatsapp_number', '919876543210');
        $this->assertJsonPath($res, 'settings.map_embed', 'https://www.google.com/maps/embed?pb=e');

        $this->assertStatusCode($this->putJson('/api/admin/settings/password', ['current_password' => 'wrong', 'new_password' => 'abcdefgh', 'confirm_password' => 'abcdefgh']), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/password', ['current_password' => config(Florist::class)->adminPassword, 'new_password' => 'abcdefgh', 'confirm_password' => 'abcdefgh']), 200);
    }

    public function test_unsafe_links_are_refused(): void
    {
        $this->login();
        foreach ([
            ['instagram' => 'javascript:alert(1)'],
            ['facebook' => 'data:text/html,<script>alert(1)</script>'],
            ['map_embed' => 'javascript:alert(1)'],
            ['map_embed' => 'http://maps.example/e'], // the map must be https
            ['email' => 'not-an-email'],
        ] as $bad) {
            $this->assertStatusCode($this->putJson('/api/admin/settings/shop', ['whatsapp_number' => '919876543210'] + $bad), 422);
        }
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', ['whatsapp_number' => '919876543210', 'instagram' => 'https://instagram.com/cityflorist', 'email' => 'shop@example.com']), 200);
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.instagram', 'https://instagram.com/cityflorist');
    }

    public function test_csv_export_neutralises_formulas(): void
    {
        (new OrderModel())->insert(['product_name' => 'Cake', 'customer_name' => '=HYPERLINK("http://evil")', 'customer_phone' => '919876543210', 'note' => '+1+1', 'status' => 'new']);
        // Laravel's plain get(): the base class's GET carries the logged-in session (and an Accept: application/json
        // header, which the export ignores).
        $res = $this->login()->getJson('/api/admin/orders/export');
        $this->assertStatusCode($res, 200);
        $csv = (string) $res->response()->getBody();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+1+1", $csv);
    }

    public function test_forged_forwarded_for_header_does_not_reset_login_limit(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/admin/login', ['username' => 'admin', 'password' => "guess{$i}"]);
        }
        $this->assertStatusCode($this->postJson('/api/admin/login', ['username' => 'admin', 'password' => 'x']), 429);
        $this->assertStatusCode($this->withHeader('X-Forwarded-For', '203.0.113.9')
            ->postJson('/api/admin/login', ['username' => 'admin', 'password' => 'x']), 429);
    }
}
