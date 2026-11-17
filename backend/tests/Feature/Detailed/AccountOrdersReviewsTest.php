<?php

namespace Tests\Feature\Detailed;

use App\Libraries\Auth\SessionState;
use App\Libraries\GoogleOAuth;
use App\Libraries\Passwords;
use App\Libraries\Settings;
use App\Models\CustomerModel;
use App\Models\OfferModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\ReviewModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\TestResponse;
use Config\Florist;
use Config\Services;
use RuntimeException;
use Tests\Support\FeatureTestCase;

/**
 * The public website's write endpoints (POST /api/orders, POST /api/reviews) and customer accounts
 * (/api/account/*), checked against the Laravel version's behaviour (ShopTest, CatalogAdminTest, ReviewTest,
 * AccountTest) plus the exact messages, JSON shapes, check order and rate limits.
 *
 * @internal
 */
final class AccountOrdersReviewsTest extends FeatureTestCase
{
    private const PASSWORD = 'Roses2026';

    private const ORDER_KEYS = ['id', 'product_id', 'product_name', 'price_at_order', 'delivery_date', 'status', 'created_at'];

    protected function setUp(): void
    {
        parent::setUp();
        // The shared rate limiter keeps the cache of the test that first used it; start every test with clean counters
        // (Laravel's tests get a fresh array cache each time).
        Services::resetSingle('rateLimiter');
        Services::resetSingle('googleOAuth');
        $this->install();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('googleOAuth');
        Services::resetSingle('rateLimiter');
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------

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

    private function customerRows(): array
    {
        return db_connect()->table('customers')->orderBy('id')->get()->getResultArray();
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'username' => 'priya_p', 'email' => 'Priya@Example.com', 'phone' => '98765 43210',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ];
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

    private function assertRedirect(TestResponse $res, string $location): void
    {
        $this->assertSame(302, $res->response()->getStatusCode(), 'Body: ' . $res->response()->getBody());
        $this->assertSame($location, $this->location($res));
    }

    /**
     * "Continue with Google" without Google: configured() and user() are replaced; user() returns $profile, or throws
     * (cancelled / failed) when it is null.
     */
    private function mockGoogle(?array $profile, bool $configured = true): GoogleOAuth
    {
        $mock = new class (config(Florist::class), $profile, $configured) extends GoogleOAuth {
            public ?string $callback = null;

            public function __construct(Florist $config, private readonly ?array $profile, private readonly bool $on)
            {
                parent::__construct($config);
            }

            public function configured(): bool
            {
                return $this->on;
            }

            public function user(string $callbackUrl, IncomingRequest $request): array
            {
                $this->callback = $callbackUrl;
                if ($this->profile === null) {
                    throw new RuntimeException('Google sign-in was cancelled: access_denied');
                }

                return $this->profile;
            }
        };
        Services::injectMock('googleOAuth', $mock);

        return $mock;
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
            . "*Name:* Asha K\n*Phone:* +919876543210\n*Needed on:* 01 Dec 2026";
        $this->assertSame(['order_id', 'message', 'whatsapp'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'order_id', 1);
        $this->assertJsonPath($res, 'message', $message);
        $this->assertJsonPath($res, 'whatsapp', 'https://wa.me/919900094843?text=' . rawurlencode($message));
        $this->assertStringNotContainsString('http', $this->json($res, 'message'));
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
            . "*Name:* Ravi Kumar\n*Phone:* +919876543210\n*Note:* Write “Happy birthday” on it";
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
        $this->assertJsonPath($res, 'message', "🌸 *New Order #2 – City Florist*\n\n*Product:* Lily Box\n*Category:* Flower Bouquets\n*Price:* ₹1,499.50\n\n*Name:* Asha\n*Phone:* +919876543210");
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

    // ------------------------------------------------------------------------------------------------------
    // Customer accounts
    // ------------------------------------------------------------------------------------------------------

    public function test_customer_registers_and_is_logged_in(): void
    {
        $res = $this->postJson('/api/account/register', $this->form());
        $this->assertStatusCode($res, 201);
        $this->assertSame([
            'message'  => 'Welcome to City Florist, priya_p!',
            'customer' => ['id' => 1, 'username' => 'priya_p', 'email' => 'priya@example.com', 'phone' => '919876543210', 'has_password' => true, 'google' => false],
        ], $this->json($res));
        $this->assertFalse($res->response()->hasCookie('remember_customer'), 'no "remember me" unless asked');

        $status = $this->getJson('/api/account/status');
        $this->assertSame(['customer', 'google'], array_keys($this->json($status)));
        $this->assertJsonPath($status, 'customer.username', 'priya_p');
        $this->assertJsonPath($status, 'google', false);

        $row = $this->customerRows()[0];
        $this->assertTrue(Passwords::check(self::PASSWORD, $row['password_hash']));
        $this->assertNotNull($row['last_login_at']);
        $this->assertNotNull($row['created_at']);
        $this->assertNotNull($row['updated_at']);
        $this->assertNull($row['google_id']);
        $this->assertNull($row['remember_token']);

        // The website's form asks to be remembered.
        $this->postJson('/api/account/logout');
        $before = $this->carriedSession['_token'];
        $res    = $this->postJson('/api/account/register', $this->form(['username' => 'ravi', 'email' => 'ravi@example.com', 'phone' => '9123456789', 'remember' => true]));
        $this->assertStatusCode($res, 201);
        $this->assertJsonPath($res, 'message', 'Welcome to City Florist, ravi!');
        $this->assertJsonPath($res, 'customer.id', 2);
        $this->assertNotNull($res->response()->getCookie('remember_customer'));
        $this->assertNotEmpty($this->customerRows()[1]['remember_token']);
        $this->assertNotSame($before, $this->carriedSession['_token']);
        $this->assertSame(2, $this->carriedSession['login_customer']);
    }

    public function test_register_form_is_checked(): void
    {
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['username' => 'a b'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['email' => 'nope'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['phone' => '123'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['password' => 'short1', 'password_confirmation' => 'short1'])), 422);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'])), 422);
        $this->assertError($this->postJson('/api/account/register', $this->form(['password_confirmation' => 'Different99'])), 422, 'The two passwords don’t match.');
        $this->assertError($this->postJson('/api/account/register', $this->form(['website' => 'bot'])), 400, 'Invalid request');
        $this->assertError($this->postJson('/api/account/register', $this->form(['username' => 'a b', 'website' => 'bot'])), 400, 'Invalid request');
        $this->assertSame([], $this->customerRows());
    }

    public function test_username_email_and_phone_must_be_unique(): void
    {
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form()), 201);
        $this->postJson('/api/account/logout');
        $this->assertError($this->postJson('/api/account/register', $this->form(['email' => 'other@example.com', 'phone' => '9123456789'])), 422, 'That username is taken. Please choose another one.');
        $this->assertError($this->postJson('/api/account/register', $this->form(['username' => 'other', 'phone' => '9123456789'])), 422, 'An account with this email already exists. Try logging in instead.');
        $this->assertError($this->postJson('/api/account/register', $this->form(['username' => 'other', 'email' => 'other@example.com'])), 422, 'An account with this mobile number already exists. Try logging in instead.');
        $this->assertCount(1, $this->customerRows());
    }

    public function test_login_with_username_email_or_phone_and_logout(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $res = $this->postJson('/api/account/logout');
        $this->assertStatusCode($res, 200);
        $this->assertSame(['ok' => true], $this->json($res));

        foreach (['priya_p', 'PRIYA@example.com', '98765 43210', '+91 98765 43210'] as $login) {
            $res = $this->postJson('/api/account/login', ['login' => $login, 'password' => self::PASSWORD]);
            $this->assertStatusCode($res, 200);
            $this->assertSame(['customer' => ['id' => 1, 'username' => 'priya_p', 'email' => 'priya@example.com', 'phone' => '919876543210', 'has_password' => true, 'google' => false]], $this->json($res));
            $this->postJson('/api/account/logout');
        }
        $this->assertError($this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => 'Wrong12345']), 422, 'Wrong login details. Check your username, email or mobile number and password.');
        $this->assertError($this->postJson('/api/account/login', ['login' => 'nobody', 'password' => self::PASSWORD]), 422, 'Wrong login details. Check your username, email or mobile number and password.');
        $this->assertError($this->postJson('/api/account/login', []), 422, 'Wrong login details. Check your username, email or mobile number and password.');
        // Passwords are not trimmed.
        $this->assertStatusCode($this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => ' ' . self::PASSWORD]), 422);
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer', null);
    }

    public function test_login_sets_last_login_and_remember_me_and_renews_the_csrf_token(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $this->postJson('/api/account/logout');
        db_connect()->table('customers')->where('id', 1)->update(['last_login_at' => '2020-01-01 10:00:00', 'updated_at' => '2020-01-01 10:00:00']);

        $before = $this->carriedSession['_token'];
        $res    = $this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => self::PASSWORD, 'remember' => true]);
        $this->assertStatusCode($res, 200);
        $this->assertNotSame($before, $this->carriedSession['_token'], 'Laravel\'s session()->regenerate() renews the CSRF token');
        $this->assertSame($this->carriedSession['_token'], $res->response()->getCookie(SessionState::COOKIE)->getValue());
        $this->assertNotNull($res->response()->getCookie('remember_customer'));
        $row = $this->customerRows()[0];
        $this->assertNotSame('2020-01-01 10:00:00', $row['last_login_at']);
        $this->assertNotSame('2020-01-01 10:00:00', $row['updated_at'], 'saving last_login_at touches updated_at, like Eloquent');
        $this->assertNotEmpty($row['remember_token']);

        // A new browser with only the "remember me" cookie is still logged in.
        $remember = $this->cookieJar['remember_customer'];
        $this->newBrowser();
        $this->cookieJar['remember_customer'] = $remember;
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer.username', 'priya_p');

        // Log out: new CSRF token, the remember cookie is removed and stops working everywhere.
        $before = $this->carriedSession['_token'];
        $this->postJson('/api/account/logout');
        $this->assertNotSame($before, $this->carriedSession['_token']);
        $this->assertArrayNotHasKey('remember_customer', $this->cookieJar);
        $this->newBrowser();
        $this->cookieJar['remember_customer'] = $remember;
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer', null);
    }

    public function test_customer_logout_keeps_a_dashboard_login_in_the_same_browser(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $this->actingAsAdmin(1);
        $this->postJson('/api/account/logout');
        $this->assertSame(1, $this->carriedSession['login_admin'] ?? null);
        $this->assertArrayNotHasKey('login_customer', $this->carriedSession);
    }

    public function test_login_attempts_are_limited_per_device_and_per_account(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $this->postJson('/api/account/logout');
        for ($i = 0; $i < 8; $i++) {
            $this->assertStatusCode($this->postJson('/api/account/login', ['login' => 'Priya_P', 'password' => "Guess{$i}xx"]), 422);
        }
        $this->assertError($this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => self::PASSWORD]), 429, 'Too many attempts. Please wait 15 minutes and try again.');
        // Another device, same account (any capitals): still locked.
        $this->assertError($this->fromIp('10.0.0.9')->postJson('/api/account/login', ['login' => 'PRIYA_P', 'password' => self::PASSWORD]), 429, 'Too many attempts. Please wait 15 minutes and try again.');
        // Same device, another account: locked by the per-device counter.
        $this->assertStatusCode($this->fromIp('127.0.0.1')->postJson('/api/account/login', ['login' => 'someone', 'password' => 'x']), 429);
        $this->assertSame(8, service('rateLimiter')->attempts('customer-login:127.0.0.1'));
        // The account counter follows the account (customer 1), whatever spelling was typed.
        $this->assertSame(8, service('rateLimiter')->attempts('customer-login-account:id:1'));
    }

    public function test_a_successful_login_clears_the_counters(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $this->postJson('/api/account/logout');
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => 'Wrong12345']);
        }
        $this->assertStatusCode($this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => self::PASSWORD]), 200);
        $this->assertSame(0, service('rateLimiter')->attempts('customer-login:127.0.0.1'));
        $this->assertSame(0, service('rateLimiter')->attempts('customer-login-account:priya_p'));
    }

    public function test_customer_login_never_opens_the_dashboard(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $this->assertError($this->getJson('/api/admin/dashboard'), 401, 'Please log in again.');
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
    }

    public function test_account_changes_need_the_csrf_token(): void
    {
        $this->assertError($this->withoutXsrfToken()->postJson('/api/account/register', $this->form()), 419, 'Your session expired. Please refresh the page and try again.');
        $this->assertSame([], $this->customerRows());
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
        $this->assertError(
            $this->postJson('/api/account/register', $this->form(['username' => 'user9', 'email' => 'user9@example.com', 'phone' => '9876543299'])),
            429,
            'Too many sign-ups from this device. Please try again in an hour.',
        );
        // Checked before the form, after the honeypot.
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['email' => 'nope'])), 429);
        $this->assertStatusCode($this->postJson('/api/account/register', $this->form(['website' => 'bot'])), 400);
        $this->assertCount(5, $this->customerRows());
    }

    public function test_order_history_shows_only_the_customers_own_orders(): void
    {
        $p = $this->product(['category_id' => $this->categoryId('flower-bouquets'), 'name' => 'Rose Box', 'price' => 900]);
        // Someone else ordered with the same phone number before this account existed: not theirs to see.
        (new OrderModel())->insert(['product_name' => 'Other', 'price_at_order' => 1, 'customer_name' => 'X', 'customer_phone' => '919876543210', 'status' => 'new']);

        $this->assertError($this->getJson('/api/account/orders'), 401, 'Please log in again.');
        $this->assertError($this->postJson('/api/account/orders', ['product_id' => $p, 'name' => 'Priya', 'phone' => '9876543210']), 401, 'Please log in again.');
        $this->assertCount(1, $this->orderRows());

        $this->postJson('/api/account/register', $this->form());
        $res = $this->postJson('/api/account/orders', ['product_id' => $p, 'name' => 'Priya', 'phone' => '9876543210', 'delivery_date' => '2026-12-24']);
        $this->assertStatusCode($res, 201);
        $this->assertSame(['order_id', 'message', 'whatsapp'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'order_id', 2);
        $this->assertStringStartsWith("🌸 *New Order #2 – City Florist*\n\n*Product:* Rose Box\n*Category:* Flower Bouquets\n*Price:* ₹900\n", $this->json($res, 'message'));
        $this->assertStringEndsWith('*Needed on:* 24 Dec 2026', $this->json($res, 'message'));
        $this->assertSame(1, $this->orderRows()[1]['customer_id']);

        $list = $this->getJson('/api/account/orders');
        $this->assertStatusCode($list, 200);
        $this->assertSame(['orders'], array_keys($this->json($list)));
        $this->assertJsonCount($list, 1, 'orders');
        $order = $this->json($list, 'orders.0');
        $this->assertSame(self::ORDER_KEYS, array_keys($order));
        $created = $this->orderRows()[1]['created_at'];
        $this->assertSame([
            'id' => 2, 'product_id' => $p, 'product_name' => 'Rose Box', 'price_at_order' => 900, 'delivery_date' => '2026-12-24',
            'status' => 'new', 'created_at' => gmdate('Y-m-d\TH:i:s.000000\Z', strtotime($created)),
        ], $order);

        // The account order counts towards the same "order:<ip>" limit as the public form.
        $this->assertSame(1, service('rateLimiter')->attempts('order:127.0.0.1'));

        // Another customer sees nothing of it.
        $other = (int) (new CustomerModel())->insert(['username' => 'ravi', 'email' => 'ravi@example.com', 'phone' => '919123456789', 'password_hash' => Passwords::hash(self::PASSWORD)]);
        $this->actingAsCustomer($other);
        $this->assertSame(['orders' => []], $this->json($this->getJson('/api/account/orders')));
    }

    public function test_order_history_is_newest_first_limited_to_100_with_laravel_types(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $p    = $this->product();
        $rows = [];
        for ($i = 1; $i <= 105; $i++) {
            $rows[] = [
                'customer_id' => 1, 'product_id' => $i === 105 ? null : $p, 'product_name' => "Cake {$i}", 'price_at_order' => $i === 105 ? '1499.50' : '0.00',
                'customer_name' => 'Priya', 'customer_phone' => '919876543210', 'note' => 'private note', 'delivery_date' => null,
                'status' => $i === 105 ? 'done' : 'new', 'created_at' => '2026-11-17 18:30:00',
            ];
        }
        db_connect()->table('orders')->insertBatch($rows);

        $res = $this->getJson('/api/account/orders');
        $this->assertJsonCount($res, 100, 'orders');
        $this->assertSame([
            'id' => 105, 'product_id' => null, 'product_name' => 'Cake 105', 'price_at_order' => 1499.5, 'delivery_date' => null,
            'status' => 'done', 'created_at' => '2026-11-17T13:00:00.000000Z',
        ], $this->json($res, 'orders.0'));
        $this->assertSame(0, $this->json($res, 'orders.1.price_at_order'));
        $this->assertSame(6, $this->json($res, 'orders.99.id'));
        $this->assertStringNotContainsString('private note', (string) $res->response()->getBody());
        $this->assertStringNotContainsString('919876543210', (string) $res->response()->getBody());
    }

    // ------------------------------------------------------------------------------------------------------
    // Continue with Google
    // ------------------------------------------------------------------------------------------------------

    public function test_google_is_off_until_configured(): void
    {
        $this->assertJsonPath($this->getJson('/api/account/status'), 'google', false);
        $this->assertRedirect($this->getJson('/api/account/oauth/google'), '/login?error=Google%20sign-in%20is%20not%20set%20up%20on%20this%20website%20yet.');
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/login');
        $this->assertSame([], $this->customerRows());
    }

    public function test_google_redirect_goes_to_google_with_the_customer_callback(): void
    {
        $florist                     = config(Florist::class);
        $florist->googleClientId     = 'client-123';
        $florist->googleClientSecret = 'secret';

        try {
            $this->assertJsonPath($this->getJson('/api/account/status'), 'google', true);
            $res      = $this->getJson('/api/account/oauth/google');
            $location = $res->response()->getHeaderLine('Location'); // absolute: Google's own URL
            $this->assertSame(302, $res->response()->getStatusCode());
            $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $location);
            $this->assertStringContainsString('client_id=client-123', $location);
            $this->assertStringContainsString('redirect_uri=' . rawurlencode(rtrim(config('App')->baseURL, '/') . '/api/account/oauth/google/callback'), $location);
            $this->assertStringContainsString('state=' . $this->carriedSession['google_oauth_state'], $location);
        } finally {
            $florist->googleClientId     = '';
            $florist->googleClientSecret = '';
        }
    }

    public function test_google_sign_in_creates_the_customer(): void
    {
        $google = $this->mockGoogle(['id' => 'g-123', 'email' => 'Priya@Example.com', 'email_verified' => true, 'name' => 'Priya Patil']);
        $res    = $this->getJson('/api/account/oauth/google/callback');
        $this->assertRedirect($res, '/account');
        $this->assertStringEndsWith('/api/account/oauth/google/callback', (string) $google->callback);
        $this->assertNotNull($res->response()->getCookie('remember_customer'), 'Google logins are remembered');
        $this->assertSame($this->carriedSession['_token'], $res->response()->getCookie(SessionState::COOKIE)->getValue(), 'the redirect hands out the new CSRF token');
        $this->assertSame(1, $this->carriedSession['login_customer']);

        $row = $this->customerRows()[0];
        $this->assertSame(['priya', 'priya@example.com', 'g-123', null, null], [$row['username'], $row['email'], $row['google_id'], $row['password_hash'], $row['phone']]);
        $this->assertNotNull($row['last_login_at']);

        $status = $this->getJson('/api/account/status');
        $this->assertSame([
            'customer' => ['id' => 1, 'username' => 'priya', 'email' => 'priya@example.com', 'phone' => null, 'has_password' => false, 'google' => true],
            'google'   => true,
        ], $this->json($status));

        // Signing in with Google again finds the same account.
        $this->postJson('/api/account/logout');
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/account');
        $this->assertCount(1, $this->customerRows());
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer.id', 1);

        // A Google account without a password can't use the password form.
        $this->postJson('/api/account/logout');
        $this->assertError(
            $this->postJson('/api/account/login', ['login' => 'priya@example.com', 'password' => 'Anything123']),
            422,
            'This account was made with Google. Please use “Continue with Google”.',
        );
    }

    public function test_google_sign_in_links_an_account_with_the_same_email(): void
    {
        $this->postJson('/api/account/register', $this->form());
        $this->postJson('/api/account/logout');
        db_connect()->table('customers')->where('id', 1)->update(['updated_at' => '2020-01-01 10:00:00']);

        $this->mockGoogle(['id' => 'g-9', 'email' => 'PRIYA@example.com', 'email_verified' => true, 'name' => '']);
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/account');
        $rows = $this->customerRows();
        $this->assertCount(1, $rows);
        $this->assertSame(['priya_p', 'g-9'], [$rows[0]['username'], $rows[0]['google_id']]);
        $this->assertNotSame('2020-01-01 10:00:00', $rows[0]['updated_at']);
        // The password set before the email was proven stops working (it may be a stranger's): Google only now.
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer', ['id' => 1, 'username' => 'priya_p', 'email' => 'priya@example.com', 'phone' => '919876543210', 'has_password' => false, 'google' => true]);

        // Linked once: another Google id with the same email logs in to it but doesn't replace the link.
        $this->postJson('/api/account/logout');
        $this->mockGoogle(['id' => 'g-10', 'email' => 'priya@example.com', 'email_verified' => true, 'name' => '']);
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/account');
        $this->assertSame('g-9', $this->customerRows()[0]['google_id']);
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer.id', 1);

        // The old password no longer opens the account (security review: it may have been a stranger's).
        $this->postJson('/api/account/logout');
        $res = $this->postJson('/api/account/login', ['login' => 'priya_p', 'password' => self::PASSWORD]);
        $this->assertStatusCode($res, 422);
        $this->assertJsonPath($res, 'error', 'This account was made with Google. Please use “Continue with Google”.');
    }

    public function test_google_sign_in_picks_a_free_username(): void
    {
        (new CustomerModel())->insert(['username' => 'priya', 'email' => 'priya@other.example', 'password_hash' => Passwords::hash(self::PASSWORD)]);
        $this->mockGoogle(['id' => 'g-1', 'email' => 'priya@gmail.com', 'email_verified' => true, 'name' => '']);
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/account');
        $this->assertSame('priya2', $this->customerRows()[1]['username']);
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer.username', 'priya2');
    }

    public function test_google_sign_in_needs_a_verified_email_and_reports_failures(): void
    {
        $this->mockGoogle(['id' => 'g-1', 'email' => 'priya@example.com', 'email_verified' => false, 'name' => '']);
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/login?error=Your%20Google%20account%20has%20no%20verified%20email%20address.');

        $this->mockGoogle(['id' => 'g-1', 'email' => '', 'email_verified' => true, 'name' => '']);
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/login?error=Your%20Google%20account%20has%20no%20verified%20email%20address.');

        $this->mockGoogle(null);
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/login?error=Google%20sign-in%20was%20cancelled%20or%20failed.%20Please%20try%20again.');

        $this->assertSame([], $this->customerRows());
        $this->assertJsonPath($this->getJson('/api/account/status'), 'customer', null);

        // Configured off: the callback just goes back to the login page.
        $this->mockGoogle(['id' => 'g-1', 'email' => 'priya@example.com', 'email_verified' => true, 'name' => ''], false);
        $this->assertRedirect($this->getJson('/api/account/oauth/google/callback'), '/login');
        $this->assertSame([], $this->customerRows());
    }
}
