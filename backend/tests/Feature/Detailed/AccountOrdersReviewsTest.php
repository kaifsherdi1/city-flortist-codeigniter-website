<?php

namespace Tests\Feature\Detailed;

use App\Libraries\Settings;
use App\Models\OfferModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\ReviewModel;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * The public website's write endpoints (POST /api/orders, POST /api/reviews), checked against the Laravel
 * version's behaviour (ShopTest, CatalogAdminTest, ReviewTest) plus the exact messages, JSON shapes, check order
 * and rate limits.
 *
 * @internal
 */
final class AccountOrdersReviewsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The shared rate limiter keeps the cache of the test that first used it; start every test with clean counters
        // (Laravel's tests get a fresh array cache each time).
        Services::resetSingle('rateLimiter');
        $this->install();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('rateLimiter');
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------

    /** The product link in order messages (Share::url): this backend's address + /p/<slug>. */
    private function share(string $slug): string
    {
        return rtrim(config('App')->baseURL, '/') . '/p/' . $slug;
    }

    private function categoryId(string $slug): int
    {
        return (int) db_connect()->table('categories')->where('slug', $slug)->get()->getRowArray()['id'];
    }

    private function product(array $attrs = []): int
    {
        $model = new ProductModel();

        return (int) $model->insert($attrs + [
            'category_id' => $this->categoryId('cakes'),
            'name'        => 'Truffle Cake',
            'slug'        => $model->uniqueSlug($attrs['name'] ?? 'Truffle Cake'),
            'price'       => 1000,
            'is_active'   => 1,
        ]);
    }

    private function orderRows(): array
    {
        return db_connect()->table('orders')->orderBy('id')->get()->getResultArray();
    }

    private function reviewRows(): array
    {
        return db_connect()->table('reviews')->orderBy('id')->get()->getResultArray();
    }

    private function review(array $fields = []): TestResponse
    {
        return $this->postJson('/api/reviews', $fields + ['name' => 'Priya Patil', 'rating' => 5, 'text' => 'Beautiful roses, delivered on time!']);
    }

    private function assertError(TestResponse $res, int $status, string $message): void
    {
        $this->assertStatusCode($res, $status);
        $this->assertSame(['error' => $message], $this->json($res));
    }

    // ------------------------------------------------------------------------------------------------------
    // POST /api/orders
    // ------------------------------------------------------------------------------------------------------

    public function test_order_takes_price_from_database_and_builds_whatsapp_link(): void
    {
        $id  = $this->product();
        $res = $this->postJson('/api/orders', ['product_id' => $id, 'name' => ' Asha  K ', 'phone' => '098765 43210', 'price' => 1, 'delivery_date' => '2026-12-01']);
        $this->assertStatusCode($res, 201);

        $order = $this->orderRows()[0];
        $this->assertSame('1000.00', $order['price_at_order']);
        $this->assertSame('Truffle Cake', $order['product_name']);
        $this->assertSame($id, $order['product_id']);
        $this->assertSame('Asha K', $order['customer_name']);
        $this->assertSame('919876543210', $order['customer_phone']);
        $this->assertSame('2026-12-01', $order['delivery_date']);
        $this->assertNull($order['note']);
        $this->assertNull($order['customer_id']);
        $this->assertSame('new', $order['status']);
        $this->assertNotEmpty($order['created_at']);

        $message = "🌸 *New Order #1 – City Florist*\n\n*Product:* Truffle Cake\n*Category:* Cakes\n*Price:* ₹1,000\n\n"
            . "*Name:* Asha K\n*Phone:* +919876543210\n*Needed on:* 01 Dec 2026\n\n*Photo:* " . $this->share('truffle-cake');
        $this->assertSame(['order_id', 'message', 'whatsapp'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'order_id', 1);
        $this->assertJsonPath($res, 'message', $message);
        $this->assertJsonPath($res, 'whatsapp', 'https://wa.me/919900094843?text=' . rawurlencode($message));
        // Readable JSON like Laravel's ApiJson middleware: ₹ and the emoji are not \u-escaped, nor the slashes.
        $this->assertStringContainsString('₹1,000', (string) $res->response()->getBody());
        $this->assertStringContainsString('https://wa.me/', (string) $res->response()->getBody());
        $this->assertSame('no-store, private', $res->response()->getHeaderLine('Cache-Control'));
        $this->assertFalse($res->response()->hasCookie('XSRF-TOKEN'), 'the public API does not use a session');
    }

    public function test_order_message_shows_discounts_notes_and_the_shops_whatsapp_number(): void
    {
        $cakes = $this->categoryId('cakes');
        $id    = $this->product(['name' => 'Rose Cake', 'sale_price' => 900]);
        (new OfferModel())->insert([
            'title' => 'Cake week', 'discount_pct' => 10, 'category_id' => $cakes, 'is_active' => 1,
            'starts_at' => date('Y-m-d H:i:s', time() - 3600), 'ends_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        Settings::put('whatsapp_number', '+91 91234-56789');

        $res = $this->postJson('/api/orders', ['product_id' => (string) $id, 'name' => "Ravi\t\nKumar", 'phone' => '+91 98765 43210', 'note' => "  Write “Happy birthday” on it  ", 'delivery_date' => '01/12/2026']);
        $this->assertStatusCode($res, 201);
        $message = "🌸 *New Order #1 – City Florist*\n\n*Product:* Rose Cake\n*Category:* Cakes\n*Price:* ₹810 (MRP ₹1,000, 19% off)\n\n"
            . "*Name:* Ravi Kumar\n*Phone:* +919876543210\n*Note:* Write “Happy birthday” on it\n\n*Photo:* " . $this->share('rose-cake');
        $this->assertJsonPath($res, 'message', $message);
        $this->assertJsonPath($res, 'whatsapp', 'https://wa.me/919123456789?text=' . rawurlencode($message));
        $order = $this->orderRows()[0];
        $this->assertSame('810.00', $order['price_at_order']);
        $this->assertNull($order['delivery_date'], 'a date not in YYYY-MM-DD form is dropped');
        $this->assertSame('Write “Happy birthday” on it', $order['note']);

        // Paise are shown; a note of "0" counts as empty (PHP's ?: like Laravel), a long note is cut to 500.
        $odd = $this->product(['name' => 'Lily Box', 'price' => 1499.5, 'category_id' => $this->categoryId('flower-bouquets')]);
        $res = $this->postJson('/api/orders', ['product_id' => $odd, 'name' => 'Asha', 'phone' => '9876543210', 'note' => '0']);
        $this->assertJsonPath($res, 'order_id', 2);
        $this->assertJsonPath($res, 'message', "🌸 *New Order #2 – City Florist*\n\n*Product:* Lily Box\n*Category:* Flower Bouquets\n*Price:* ₹1,499.50\n\n*Name:* Asha\n*Phone:* +919876543210\n\n*Photo:* " . $this->share('lily-box'));
        $this->assertNull($this->orderRows()[1]['note']);

        $res = $this->postJson('/api/orders', ['product_id' => $odd, 'name' => str_repeat('N', 100), 'phone' => '9876543210', 'note' => str_repeat('é', 600)]);
        $this->assertStatusCode($res, 201);
        $this->assertSame(500, mb_strlen($this->orderRows()[2]['note']));
        $this->assertSame(str_repeat('N', 80), $this->orderRows()[2]['customer_name']);
    }

    public function test_wholesale_and_unpriced_products_ask_for_the_price(): void
    {
        $wholesale = $this->product(['category_id' => $this->categoryId('wholesale-flowers'), 'name' => 'Roses', 'price' => 399]);
        $res       = $this->postJson('/api/orders', ['product_id' => $wholesale, 'name' => 'Asha', 'phone' => '9876543210']);
        $this->assertStatusCode($res, 201);
        $this->assertStringContainsString('*Price:* Please share the price', $this->json($res, 'message'));
        $this->assertStringNotContainsString('399', $this->json($res, 'message'));
        $this->assertStringContainsString('*Category:* Wholesale Flowers', $this->json($res, 'message'));
        $this->assertSame('0.00', $this->orderRows()[0]['price_at_order']);

        $unpriced = $this->product(['name' => 'Custom Cake', 'price' => 0]);
        $res      = $this->postJson('/api/orders', ['product_id' => $unpriced, 'name' => 'Asha', 'phone' => '9876543210']);
        $this->assertStringContainsString('*Price:* Please share the price', $this->json($res, 'message'));
    }

    public function test_order_validation_and_honeypot(): void
    {
        $id = $this->product();
        $this->assertError($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'A', 'phone' => '9876543210']), 422, 'Please enter your name.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => $id, 'name' => '   ', 'phone' => '9876543210']), 422, 'Please enter your name.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => $id, 'phone' => '9876543210']), 422, 'Please enter your name.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'Asha', 'phone' => '12345']), 422, 'Please enter a valid 10-digit mobile number.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'Asha', 'phone' => '5876543210']), 422, 'Please enter a valid 10-digit mobile number.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'Asha']), 422, 'Please enter a valid 10-digit mobile number.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => 999, 'name' => 'Asha', 'phone' => '9876543210']), 404, 'This product is no longer available.');
        $this->assertError($this->postJson('/api/orders', ['name' => 'Asha', 'phone' => '9876543210']), 404, 'This product is no longer available.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'Asha', 'phone' => '9876543210', 'website' => 'x']), 400, 'Invalid request');

        // Checked in Laravel's order: honeypot, name, phone, product.
        $this->assertError($this->postJson('/api/orders', ['product_id' => 999, 'name' => 'A', 'phone' => '1', 'website' => 'x']), 400, 'Invalid request');
        $this->assertError($this->postJson('/api/orders', ['product_id' => 999, 'name' => 'A', 'phone' => '1']), 422, 'Please enter your name.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => 999, 'name' => 'Asha', 'phone' => '1']), 422, 'Please enter a valid 10-digit mobile number.');
        // A honeypot of only spaces is "not filled" (Laravel's filled()).
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'Asha', 'phone' => '9876543210', 'website' => '  ']), 201);
        $this->assertCount(1, $this->orderRows());
    }

    public function test_orders_for_hidden_or_missing_products_are_refused(): void
    {
        $hidden = $this->product(['name' => 'Hidden Cake', 'is_active' => 0]);
        $this->assertError($this->postJson('/api/orders', ['product_id' => $hidden, 'name' => 'Asha', 'phone' => '9876543210']), 404, 'This product is no longer available.');
        $this->assertError($this->postJson('/api/orders', ['product_id' => 99999, 'name' => 'Asha', 'phone' => '9876543210']), 404, 'This product is no longer available.');
        $this->assertSame([], $this->orderRows());
    }

    public function test_order_spam_from_one_device_is_limited_after_the_form_checks(): void
    {
        $id = $this->product();
        // Mistakes and unknown products don't count towards the limit (checked before it, as in Laravel).
        for ($i = 0; $i < 12; $i++) {
            $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'A', 'phone' => '9876543210']), 422);
            $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => 999, 'name' => 'Asha', 'phone' => '9876543210']), 404);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'Asha', 'phone' => '9876543210']), 201);
        }
        $this->assertError($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'Asha', 'phone' => '9876543210']), 429, 'Too many orders from this device. Please message us directly on WhatsApp.');
        // Still the form errors first, even at the limit.
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $id, 'name' => 'A', 'phone' => '9876543210']), 422);
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => 999, 'name' => 'Asha', 'phone' => '9876543210']), 404);
        $this->assertCount(10, $this->orderRows());

        // The limit is per device ("order:<ip>"), lasting 30 minutes.
        $this->assertSame(10, service('rateLimiter')->attempts('order:127.0.0.1'));
        $this->assertGreaterThan(29 * 60, service('rateLimiter')->availableIn('order:127.0.0.1'));
        $this->assertLessThanOrEqual(30 * 60, service('rateLimiter')->availableIn('order:127.0.0.1'));
        $this->assertStatusCode($this->fromIp('10.0.0.5')->postJson('/api/orders', ['product_id' => $id, 'name' => 'Asha', 'phone' => '9876543210']), 201);
    }

    // ------------------------------------------------------------------------------------------------------
    // POST /api/reviews
    // ------------------------------------------------------------------------------------------------------

    public function test_review_waits_for_the_owner_before_it_shows(): void
    {
        $res = $this->review();
        $this->assertStatusCode($res, 201);
        $this->assertSame(['message' => 'Thank you! Your review will appear on the website once the shop has seen it.'], $this->json($res));

        $row = $this->reviewRows()[0];
        $this->assertSame(['Priya Patil', 5, 'Beautiful roses, delivered on time!', null, 0, 'pending'], [$row['name'], $row['rating'], $row['text'], $row['phone'], $row['verified'], $row['status']]);
        $this->assertNotEmpty($row['created_at']);

        $site = $this->getJson('/api/site');
        $this->assertJsonPath($site, 'reviews.summary.count', 0);
        $this->assertJsonCount($site, 0, 'reviews.items');
    }

    public function test_verified_badge_only_for_numbers_that_ordered(): void
    {
        (new OrderModel())->insert(['product_name' => 'Roses', 'price_at_order' => 500, 'customer_name' => 'Asha', 'customer_phone' => '919876543210', 'status' => 'done']);
        $this->assertStatusCode($this->review(['phone' => '98765 43210']), 201);
        $this->assertStatusCode($this->review(['phone' => '9123456789', 'name' => 'Ravi K']), 201);
        $this->assertStatusCode($this->review(['name' => 'No Phone']), 201);

        $rows = $this->reviewRows();
        $this->assertSame([1, '919876543210'], [$rows[0]['verified'], $rows[0]['phone']]);
        $this->assertSame([0, '919123456789'], [$rows[1]['verified'], $rows[1]['phone']]);
        $this->assertSame([0, null], [$rows[2]['verified'], $rows[2]['phone']]);
        $this->assertTrue((new ReviewModel())->find(1)['verified']);
    }

    public function test_bad_and_spammy_reviews_are_refused(): void
    {
        $this->assertError($this->review(['rating' => 0]), 422, 'Please choose 1 to 5 stars.');
        $this->assertError($this->review(['rating' => 6]), 422, 'Please choose 1 to 5 stars.');
        $this->assertError($this->review(['rating' => 'five']), 422, 'Please choose 1 to 5 stars.');
        $this->assertError($this->review(['name' => 'A']), 422, 'Please enter your name.');
        $this->assertError($this->review(['name' => '<b></b>X']), 422, 'Please enter your name.');
        $this->assertError($this->review(['text' => 'ok']), 422, 'Please write a few words about your order (at least 10 letters).');
        $this->assertError($this->review(['text' => '<p>short</p>']), 422, 'Please write a few words about your order (at least 10 letters).');
        $this->assertError($this->review(['text' => 'Great! Visit https://spam.example for cheap stuff']), 422, 'Please leave out web links.');
        $this->assertError($this->review(['text' => 'Great! Visit WWW.spam.example for cheap stuff']), 422, 'Please leave out web links.');
        $this->assertError($this->review(['phone' => '123']), 422, 'Please enter a valid 10-digit mobile number, or leave it empty.');
        $this->assertError($this->review(['website' => 'bot']), 400, 'Invalid request');

        // Laravel's order: honeypot, name, rating, text, phone, links.
        $this->assertError($this->review(['name' => 'A', 'rating' => 0, 'website' => 'bot']), 400, 'Invalid request');
        $this->assertError($this->review(['name' => 'A', 'rating' => 0]), 422, 'Please enter your name.');
        $this->assertError($this->review(['rating' => 0, 'text' => 'ok']), 422, 'Please choose 1 to 5 stars.');
        $this->assertError($this->review(['text' => 'ok', 'phone' => '123']), 422, 'Please write a few words about your order (at least 10 letters).');
        $this->assertError($this->review(['text' => 'see www.x.example now', 'phone' => '123']), 422, 'Please enter a valid 10-digit mobile number, or leave it empty.');
        $this->assertSame([], $this->reviewRows());
    }

    public function test_html_is_stripped_and_long_reviews_are_cut(): void
    {
        $this->assertStatusCode($this->review(['name' => '<b>Priya</b>', 'text' => '<script>alert(1)</script>Lovely flowers for my mother']), 201);
        $row = $this->reviewRows()[0];
        $this->assertSame('Priya', $row['name']);
        $this->assertSame('alert(1)Lovely flowers for my mother', $row['text']);
        $this->assertStringNotContainsString('<script>', $row['text']);

        $this->assertStatusCode($this->review(['name' => "  Priya \n  Patil  " . str_repeat('x', 80), 'text' => str_repeat('ಹೂ', 700), 'rating' => '4']), 201);
        $row = $this->reviewRows()[1];
        $this->assertSame(60, mb_strlen($row['name']));
        $this->assertStringStartsWith('Priya Patil xxx', $row['name']);
        $this->assertSame(1000, mb_strlen($row['text']));
        $this->assertSame(4, $row['rating']);
    }

    public function test_three_reviews_a_day_per_device(): void
    {
        // Refused reviews don't count.
        for ($i = 0; $i < 5; $i++) {
            $this->assertStatusCode($this->review(['rating' => 0]), 422);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->assertStatusCode($this->review(), 201);
        }
        $this->assertError($this->review(), 429, 'Thank you! You have already sent reviews today.');
        // Even at the limit, a form mistake is reported first.
        $this->assertError($this->review(['rating' => 9]), 422, 'Please choose 1 to 5 stars.');
        $this->assertCount(3, $this->reviewRows());
        $this->assertGreaterThan(23 * 3600, service('rateLimiter')->availableIn('review:127.0.0.1'));
        $this->assertStatusCode($this->fromIp('10.0.0.7')->review(), 201);
    }
}
