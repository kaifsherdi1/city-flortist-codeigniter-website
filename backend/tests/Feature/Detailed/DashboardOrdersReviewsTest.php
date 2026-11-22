<?php

namespace Tests\Feature\Detailed;

use App\Libraries\Http;
use App\Libraries\Passwords;
use App\Libraries\Settings;
use App\Libraries\Text;
use App\Models\AdminModel;
use App\Models\OrderModel;
use App\Models\ReviewModel;
use CodeIgniter\Test\TestResponse;
use Tests\Support\FeatureTestCase;

/**
 * Dashboard home screen, orders and review moderation (/api/admin/dashboard*, /api/admin/orders*,
 * /api/admin/reviews*), checked against the Laravel version's Admin\DashboardController, Admin\OrderController and
 * Admin\ReviewController and its tests (ShopTest, ReviewTest, AccountTest): exact messages, JSON keys in order,
 * types, date windows, paging and the CSV bytes.
 *
 * The expected chart numbers are worked out here with plain strings and mktime(), separately from the
 * controller's DateTimeImmutable code, so the two check each other.
 *
 * @internal
 */
final class DashboardOrdersReviewsTest extends FeatureTestCase
{
    private const DASHBOARD_KEYS = ['stats', 'target', 'days', 'hours', 'months', 'statuses', 'goals', 'unpriced', 'categories', 'recent'];

    private const STATS_KEYS = [
        'orders_today', 'orders_week', 'orders_prev_week', 'value_week', 'value_prev_week', 'orders_month',
        'customers_month', 'customers_prev_month', 'new_orders', 'live_products', 'running_offers',
    ];

    private const GOALS_KEYS = ['priced', 'with_photos', 'featured', 'products', 'delivered_month', 'orders_month'];

    private const BUCKET_KEYS = ['customers', 'key', 'orders', 'delivered', 'value'];

    private const ORDER_KEYS = [
        'id', 'customer_id', 'product_id', 'product_name', 'price_at_order', 'customer_name', 'customer_phone', 'note',
        'delivery_date', 'status', 'created_at',
    ];

    private const ORDERS_INDEX_KEYS = ['orders', 'total', 'page', 'pages', 'statuses', 'review_link'];

    private const REVIEW_KEYS = ['id', 'name', 'rating', 'text', 'verified', 'date', 'full_name', 'phone', 'status'];

    private const TARGET_ERROR = 'Please enter a target between 1 and 100000 orders.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
    }

    // ------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------

    /** What the React app receives: the value after a JSON round trip (1000.0 arrives as 1000). */
    private static function wire(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    }

    private function assertError(TestResponse $res, int $status, string $message): void
    {
        $this->assertStatusCode($res, $status);
        $this->assertSame(['error' => $message], $this->json($res));
    }

    /** Route-model binding misses: Laravel answered before the controller (and its ApiJson middleware) ran. */
    private function assertNotFound(TestResponse $res): void
    {
        $this->assertError($res, 404, 'Not found.');
        $this->assertSame(Http::NO_CACHE, $res->response()->getHeaderLine('Cache-Control'));
    }

    /** A check inside the controller: the ApiJson middleware's "no-store". */
    private function assertInvalid(TestResponse $res, string $message): void
    {
        $this->assertError($res, 422, $message);
        $this->assertSame(Http::NO_STORE, $res->response()->getHeaderLine('Cache-Control'));
    }

    /** "Y-m-d" of today plus $offset days (mktime, not DateTime). */
    private static function day(int $offset): string
    {
        return date('Y-m-d', mktime(0, 0, 0, (int) date('n'), (int) date('j') + $offset, (int) date('Y')));
    }

    /** "Y-m-d H:i:s" of the 1st of this month plus $months months, plus $seconds seconds. */
    private static function monthStart(int $months = 0, int $seconds = 0): string
    {
        return date('Y-m-d H:i:s', mktime(0, 0, $seconds, (int) date('n') + $months, 1, (int) date('Y')));
    }

    private function order(array $attrs = []): int
    {
        $db = db_connect();
        $db->table('orders')->insert($attrs + [
            'product_name'   => 'Truffle Cake',
            'price_at_order' => 1000,
            'customer_name'  => 'Asha',
            'customer_phone' => '919876543210',
            'note'           => null,
            'delivery_date'  => null,
            'status'         => 'new',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function orderRow(int $id): ?array
    {
        return db_connect()->table('orders')->where('id', $id)->get()->getRowArray();
    }

    private function categoryId(string $slug): int
    {
        return (int) db_connect()->table('categories')->where('slug', $slug)->get()->getRowArray()['id'];
    }

    private function product(array $attrs): int
    {
        static $n = 0;
        $n++;
        $db = db_connect();
        $db->table('products')->insert($attrs + [
            'category_id' => $this->categoryId('cakes'),
            'name'        => "Product {$n}",
            'slug'        => "product-{$n}-" . bin2hex(random_bytes(3)),
            'price'       => 1000,
            'sale_price'  => null,
            'is_featured' => 0,
            'is_active'   => 1,
            'sort'        => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function image(int $productId): void
    {
        db_connect()->table('product_images')->insert([
            'product_id' => $productId, 'path' => "uploads/products/{$productId}.webp", 'thumb_path' => "uploads/products/{$productId}-thumb.webp", 'sort' => 0,
        ]);
    }

    private function offer(array $attrs): void
    {
        db_connect()->table('offers')->insert($attrs + [
            'title'        => 'Offer',
            'subtitle'     => null,
            'discount_pct' => 10,
            'product_id'   => null,
            'category_id'  => null,
            'image'        => null,
            'starts_at'    => date('Y-m-d H:i:s', time() - 86400),
            'ends_at'      => date('Y-m-d H:i:s', time() + 86400),
            'is_active'    => 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    private function review(array $attrs = []): int
    {
        $db = db_connect();
        $db->table('reviews')->insert($attrs + [
            'name'       => 'Priya Patil',
            'rating'     => 5,
            'text'       => 'Beautiful roses, delivered on time!',
            'phone'      => null,
            'verified'   => 0,
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function staff(string $status = 'active'): array
    {
        $id = (new AdminModel())->insert([
            'username' => 'ravi', 'email' => 'ravi@example.com', 'phone' => null,
            'password_hash' => Passwords::hash('Petals#Hubballi9'), 'role' => 'staff', 'status' => $status,
        ]);

        return (new AdminModel())->find((int) $id);
    }

    /**
     * Reference buckets: plain string prefixes over the fixture rows (the controller uses DateTimeImmutable).
     *
     * @param list<array<string, mixed>> $orders
     * @param list<string>               $keys
     */
    private static function expectedBuckets(array $orders, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $phones    = [];
            $count     = 0;
            $delivered = 0;
            $value     = 0;
            foreach ($orders as $o) {
                if (! str_starts_with($o['created_at'], $key)) {
                    continue;
                }
                $count++;
                $phones[$o['customer_phone']] = true;
                if ($o['status'] === 'done') {
                    $delivered++;
                }
                if ($o['status'] !== 'cancelled') {
                    $value += (float) $o['price_at_order'];
                }
            }
            $out[] = ['customers' => count($phones), 'key' => $key, 'orders' => $count, 'delivered' => $delivered, 'value' => $value];
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------------------------------------------

    public function test_every_route_needs_a_login_and_a_changed_starting_password(): void
    {
        $order  = $this->order();
        $review = $this->review();
        $calls  = [
            fn () => $this->getJson('/api/admin/dashboard'),
            fn () => $this->putJson('/api/admin/dashboard/target', ['target' => 80]),
            fn () => $this->getJson('/api/admin/orders'),
            fn () => $this->getJson('/api/admin/orders/export'),
            fn () => $this->patchJson("/api/admin/orders/{$order}", ['status' => 'done']),
            fn () => $this->deleteJson("/api/admin/orders/{$order}"),
            fn () => $this->getJson('/api/admin/reviews'),
            fn () => $this->patchJson("/api/admin/reviews/{$review}", ['status' => 'approved']),
            fn () => $this->deleteJson("/api/admin/reviews/{$review}"),
        ];
        foreach ($calls as $call) {
            $this->assertError($call(), 401, 'Please log in again.');
        }

        $this->actingAsAdmin(1);
        $this->carriedSession['must_change_password'] = true;
        foreach ($calls as $call) {
            $res = $call();
            $this->assertStatusCode($res, 403);
            $this->assertSame(['error' => 'Please choose a new password first. The starting password is not safe to keep.', 'must_change_password' => true], $this->json($res));
        }
        // Nothing changed.
        $this->assertSame('new', $this->orderRow($order)['status']);
        $this->assertSame(1, db_connect()->table('reviews')->countAllResults());
        $this->assertFalse(Settings::has('monthly_order_target'));
    }

    public function test_staff_can_use_the_dashboard_orders_and_reviews(): void
    {
        $order  = $this->order();
        $other  = $this->order(['status' => 'contacted']);
        $review = $this->review();
        $second = $this->review(['name' => 'Ravi K']);
        $this->actingAsAdmin($this->staff());

        $this->assertStatusCode($this->getJson('/api/admin/dashboard'), 200);
        $this->assertStatusCode($this->putJson('/api/admin/dashboard/target', ['target' => 75]), 200);
        $this->assertStatusCode($this->getJson('/api/admin/orders'), 200);
        $this->assertStatusCode($this->getJson('/api/admin/orders/export'), 200);
        $res = $this->patchJson("/api/admin/orders/{$order}", ['status' => 'done']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'new_orders', 0);
        $this->assertStatusCode($this->deleteJson("/api/admin/orders/{$other}"), 200);
        $this->assertStatusCode($this->getJson('/api/admin/reviews'), 200);
        $res = $this->patchJson("/api/admin/reviews/{$review}", ['status' => 'approved']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'pending', 1);
        $this->assertStatusCode($this->deleteJson("/api/admin/reviews/{$second}"), 200);
        $this->assertSame('75', Settings::get('monthly_order_target'));

        // A staff account the owner switched off is logged out on its next request.
        $this->newBrowser()->actingAsAdmin($this->staffDisabled());
        $this->assertError($this->getJson('/api/admin/orders'), 401, 'This account has been switched off by the shop owner.');
    }

    private function staffDisabled(): array
    {
        $id = (new AdminModel())->insert([
            'username' => 'meena', 'email' => 'meena@example.com', 'phone' => null,
            'password_hash' => Passwords::hash('Petals#Hubballi9'), 'role' => 'staff', 'status' => 'disabled',
        ]);

        return (new AdminModel())->find((int) $id);
    }

    // ------------------------------------------------------------------------------------------------------
    // GET /api/admin/dashboard, PUT /api/admin/dashboard/target
    // ------------------------------------------------------------------------------------------------------

    public function test_empty_dashboard_has_every_key_in_order_and_all_buckets(): void
    {
        $res = $this->actingAsAdmin(1)->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 200);
        $this->assertSame('application/json; charset=UTF-8', $res->response()->getHeaderLine('Content-Type'));
        $this->assertSame(Http::NO_STORE, $res->response()->getHeaderLine('Cache-Control'));
        $data = $this->json($res);

        $this->assertSame(self::DASHBOARD_KEYS, array_keys($data));
        $this->assertSame(array_fill_keys(self::STATS_KEYS, 0), $data['stats']);
        $this->assertSame(50, $data['target']);
        $this->assertSame(['new' => 0, 'contacted' => 0, 'done' => 0, 'cancelled' => 0], $data['statuses']);
        $this->assertSame(array_fill_keys(self::GOALS_KEYS, 0), $data['goals']);
        $this->assertSame(0, $data['unpriced']);
        $this->assertSame([], $data['recent']);

        // 30 days ending today, 24 hours of today, 12 months ending this month: oldest first, keys in Laravel's order.
        $dayKeys   = array_map(static fn (int $i): string => self::day(-$i), range(29, 0));
        $hourKeys  = array_map(static fn (int $h): string => sprintf('%s %02d', self::day(0), $h), range(0, 23));
        $monthKeys = array_map(static fn (int $i): string => substr(self::monthStart(-$i), 0, 7), range(11, 0));
        $empty     = static fn (array $keys): array => array_map(static fn (string $k): array => ['customers' => 0, 'key' => $k, 'orders' => 0, 'delivered' => 0, 'value' => 0], $keys);
        $this->assertSame($empty($dayKeys), $data['days']);
        $this->assertSame($empty($hourKeys), $data['hours']);
        $this->assertSame($empty($monthKeys), $data['months']);
        $this->assertCount(30, $data['days']);
        $this->assertCount(24, $data['hours']);
        $this->assertCount(12, $data['months']);
        $this->assertSame(self::BUCKET_KEYS, array_keys($data['days'][0]));

        // Every category, in display order, with its number of live products.
        $categories = db_connect()->table('categories')->orderBy('sort')->orderBy('id')->get()->getResultArray();
        $this->assertCount(8, $categories);
        $this->assertSame(
            array_map(static fn (array $c): array => ['name' => $c['name'], 'slug' => $c['slug'], 'count' => 0], $categories),
            $data['categories'],
        );
        // The raw JSON: value_prev_week is a float 0.0, which PHP (and Laravel) writes as 0.
        $this->assertStringContainsString('"value_week":0,"value_prev_week":0,', (string) $res->response()->getBody());
    }

    public function test_dashboard_counts_orders_by_day_hour_month_status_and_customer(): void
    {
        $today = self::day(0);
        // [created_at, status, price, phone]; the comments say why each one is there.
        $fixtures = [
            ["{$today} 10:15:00", 'new', '1000.00', '919800000001'],          // A today, 10 am
            ["{$today} 10:45:00", 'done', '500.50', '919800000001'],          // B today, same customer
            ["{$today} 23:30:00", 'cancelled', '700.00', '919800000002'],     // C today, cancelled: no value
            [self::day(-1) . ' 12:00:00', 'done', '200.00', '919800000003'],    // D yesterday
            [self::day(-6) . ' 00:00:00', 'contacted', '100.00', '919800000004'], // E first day of the 7-day week
            [self::day(-7) . ' 23:59:59', 'done', '300.00', '919800000005'],    // F last second of the week before
            [self::day(-13) . ' 00:00:00', 'new', '50.00', '919800000005'],     // G first second of the week before
            [self::day(-13) . ' 00:00:00', 'cancelled', '400.00', '919800000007'], // I week before, cancelled
            [self::day(-14) . ' 23:59:59', 'done', '999.00', '919800000006'],   // H just before the week before
            [self::monthStart(-1), 'done', '10.00', '919800000008'],          // J first second of last month
            [self::monthStart(0, -1), 'contacted', '20.00', '919800000009'],  // K last second of last month
            [self::monthStart(-1, -1), 'new', '30.00', '919800000008'],       // L just before last month
            [self::monthStart(-11), 'done', '40.00', '919800000010'],         // M first second of the 12-month window
            [self::monthStart(-11, -1), 'done', '60.00', '919800000011'],     // N just before the window
        ];
        $orders = [];
        foreach ($fixtures as $i => [$at, $status, $price, $phone]) {
            $id       = $this->order(['created_at' => $at, 'status' => $status, 'price_at_order' => $price, 'customer_phone' => $phone, 'customer_name' => 'Customer ' . ($i + 1)]);
            $orders[] = ['id' => $id, 'created_at' => $at, 'status' => $status, 'price_at_order' => $price, 'customer_phone' => $phone];
        }

        // Products: priced/unpriced, live/hidden, featured, with and without photos, in two categories.
        $a = $this->product(['price' => 1000, 'is_featured' => 1, 'is_active' => 1]);
        $this->image($a);
        $this->image($a);
        $this->product(['price' => 0, 'is_featured' => 0, 'is_active' => 1]);
        $c = $this->product(['price' => 500, 'is_featured' => 1, 'is_active' => 0]);
        $this->image($c);
        $this->product(['price' => 0, 'is_featured' => 0, 'is_active' => 0]);
        $this->product(['price' => 250, 'is_featured' => 1, 'is_active' => 1, 'category_id' => $this->categoryId('flower-bouquets')]);

        // One running offer; paused, finished and not-started-yet ones don't count.
        $this->offer(['title' => 'Running']);
        $this->offer(['title' => 'Paused', 'is_active' => 0]);
        $this->offer(['title' => 'Over', 'starts_at' => date('Y-m-d H:i:s', time() - 3 * 86400), 'ends_at' => date('Y-m-d H:i:s', time() - 86400)]);
        $this->offer(['title' => 'Later', 'starts_at' => date('Y-m-d H:i:s', time() + 86400), 'ends_at' => date('Y-m-d H:i:s', time() + 2 * 86400)]);

        Settings::put('monthly_order_target', '120');

        $res = $this->actingAsAdmin(1)->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 200);
        $data = $this->json($res);
        $this->assertSame(self::DASHBOARD_KEYS, array_keys($data));

        // --- Buckets, against the string-based reference ------------------------------------------------
        $window    = array_values(array_filter($orders, static fn (array $o): bool => $o['created_at'] >= self::monthStart(-11)));
        $dayKeys   = array_map(static fn (int $i): string => self::day(-$i), range(29, 0));
        $hourKeys  = array_map(static fn (int $h): string => sprintf('%s %02d', $today, $h), range(0, 23));
        $monthKeys = array_map(static fn (int $i): string => substr(self::monthStart(-$i), 0, 7), range(11, 0));
        $days      = self::expectedBuckets($window, $dayKeys);
        $this->assertSame(self::wire($days), $data['days']);
        $this->assertSame(self::wire(self::expectedBuckets($window, $hourKeys)), $data['hours']);
        $this->assertSame(self::wire(self::expectedBuckets($window, $monthKeys)), $data['months']);

        // ... and hand-checked values that don't depend on the date.
        $this->assertSame(['customers' => 2, 'key' => $today, 'orders' => 3, 'delivered' => 1, 'value' => 1500.5], $data['days'][29]);
        $this->assertSame(['customers' => 1, 'key' => "{$today} 10", 'orders' => 2, 'delivered' => 1, 'value' => 1500.5], $data['hours'][10]);
        $this->assertSame(['customers' => 1, 'key' => "{$today} 23", 'orders' => 1, 'delivered' => 0, 'value' => 0], $data['hours'][23]);
        $this->assertSame(['customers' => 0, 'key' => "{$today} 11", 'orders' => 0, 'delivered' => 0, 'value' => 0], $data['hours'][11]);
        $this->assertSame(['customers' => 1, 'key' => substr(self::monthStart(-11), 0, 7), 'orders' => 1, 'delivered' => 1, 'value' => 40], $data['months'][0]);
        $this->assertSame(13, array_sum(array_column($data['months'], 'orders')), 'the order just before the 12-month window is left out');
        // A cancelled-only bucket keeps the integer 0; the raw JSON has "value":0 for it.
        $this->assertStringContainsString('{"customers":1,"key":"' . $today . ' 23","orders":1,"delivered":0,"value":0}', (string) $res->response()->getBody());

        // --- Stats -----------------------------------------------------------------------------------------
        $week          = array_slice($days, -7);
        $prevFrom      = self::day(-13) . ' 00:00:00';
        $prevTo        = self::day(-7) . ' 23:59:59';
        $prevWeek      = array_filter($orders, static fn (array $o): bool => $o['created_at'] >= $prevFrom && $o['created_at'] <= $prevTo);
        $thisMonth     = array_filter($orders, static fn (array $o): bool => $o['created_at'] >= self::monthStart(0));
        $lastMonth     = array_filter($orders, static fn (array $o): bool => $o['created_at'] >= self::monthStart(-1) && $o['created_at'] <= self::monthStart(0, -1));
        $phones        = static fn (array $list): int => count(array_unique(array_column($list, 'customer_phone')));
        $notCancelled  = static fn (array $o): bool => $o['status'] !== 'cancelled';
        $expectedStats = [
            'orders_today'         => 3,
            'orders_week'          => array_sum(array_column($week, 'orders')),
            'orders_prev_week'     => count($prevWeek),
            'value_week'           => array_sum(array_column($week, 'value')),
            'value_prev_week'      => (float) array_sum(array_map(static fn (array $o): float => (float) $o['price_at_order'], array_filter($prevWeek, $notCancelled))),
            'orders_month'         => count($thisMonth),
            'customers_month'      => $phones($thisMonth),
            'customers_prev_month' => $phones($lastMonth),
            'new_orders'           => 3,
            'live_products'        => 3,
            'running_offers'       => 1,
        ];
        $this->assertSame(self::wire($expectedStats), $data['stats']);
        // Hand-checked: F, G and I are the week before (H is one second too early); I is cancelled.
        $this->assertGreaterThanOrEqual(3, $data['stats']['orders_prev_week']);
        $this->assertGreaterThanOrEqual(350, $data['stats']['value_prev_week']);
        // J and K are last month (L is one second too early).
        $this->assertGreaterThanOrEqual(2, $data['stats']['customers_prev_month']);
        $this->assertGreaterThanOrEqual(5, $data['stats']['orders_week']);

        $this->assertSame(120, $data['target']);
        $this->assertSame(['new' => 3, 'contacted' => 2, 'done' => 7, 'cancelled' => 2], $data['statuses']);
        $this->assertSame([
            'priced'          => 3,
            'with_photos'     => 2,
            'featured'        => 2,
            'products'        => 5,
            'delivered_month' => count(array_filter($thisMonth, static fn (array $o): bool => $o['status'] === 'done')),
            'orders_month'    => count(array_filter($thisMonth, $notCancelled)),
        ], $data['goals']);
        $this->assertSame(1, $data['unpriced']);

        $categories = db_connect()->table('categories')->orderBy('sort')->orderBy('id')->get()->getResultArray();
        $counts     = ['cakes' => 2, 'flower-bouquets' => 1];
        $this->assertSame(
            array_map(static fn (array $c): array => ['name' => $c['name'], 'slug' => $c['slug'], 'count' => $counts[$c['slug']] ?? 0], $categories),
            $data['categories'],
        );

        // The 6 newest orders, as Laravel turns an Order into JSON.
        $this->assertSame([14, 13, 12, 11, 10, 9], array_column($data['recent'], 'id'));
        $this->assertSame(self::ORDER_KEYS, array_keys($data['recent'][0]));
        $this->assertSame([
            'id' => 14, 'customer_id' => null, 'product_id' => null, 'product_name' => 'Truffle Cake', 'price_at_order' => 60,
            'customer_name' => 'Customer 14', 'customer_phone' => '919800000011', 'note' => null, 'delivery_date' => null,
            'status' => 'done', 'created_at' => Text::jsonDate(self::monthStart(-11, -1)),
        ], $data['recent'][0]);
        $this->assertSame(
            self::wire(array_map(fn (int $id): array => OrderModel::serialize($this->orderRow($id)), [14, 13, 12, 11, 10, 9])),
            $data['recent'],
        );
    }

    public function test_dashboard_value_sums_keep_decimals(): void
    {
        $today = self::day(0);
        $this->order(['created_at' => "{$today} 09:00:00", 'price_at_order' => '0.10', 'status' => 'new']);
        $this->order(['created_at' => "{$today} 09:30:00", 'price_at_order' => '0.20', 'status' => 'done']);
        $this->order(['created_at' => self::day(-10) . ' 08:00:00', 'price_at_order' => '1234.55', 'status' => 'contacted']);

        $res = $this->actingAsAdmin(1)->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 200);
        // 0.1 + 0.2 in floating point, exactly as PHP adds them in Laravel.
        $this->assertSame(0.1 + 0.2, $this->json($res, 'days.29.value'));
        $this->assertSame(0.1 + 0.2, $this->json($res, 'stats.value_week'));
        $this->assertSame(1234.55, $this->json($res, 'stats.value_prev_week'));
        $this->assertSame(['customers' => 0, 'key' => "{$today} 00", 'orders' => 0, 'delivered' => 0, 'value' => 0], $this->json($res, 'hours.0'));
        $this->assertSame(['customers' => 1, 'key' => "{$today} 09", 'orders' => 2, 'delivered' => 1, 'value' => 0.1 + 0.2], $this->json($res, 'hours.9'));
    }

    public function test_hour_buckets_cover_the_first_and_last_second_of_today(): void
    {
        $today = self::day(0);
        $this->order(['created_at' => "{$today} 00:00:00", 'customer_phone' => '919800000001']);
        $this->order(['created_at' => "{$today} 23:59:59", 'customer_phone' => '919800000002', 'status' => 'done', 'price_at_order' => '99.99']);
        $this->order(['created_at' => self::day(-1) . ' 23:59:59', 'customer_phone' => '919800000003']);

        $res = $this->actingAsAdmin(1)->getJson('/api/admin/dashboard');
        $this->assertSame(['customers' => 1, 'key' => "{$today} 00", 'orders' => 1, 'delivered' => 0, 'value' => 1000], $this->json($res, 'hours.0'));
        $this->assertSame(['customers' => 1, 'key' => "{$today} 23", 'orders' => 1, 'delivered' => 1, 'value' => 99.99], $this->json($res, 'hours.23'));
        $this->assertSame(2, array_sum(array_column($this->json($res, 'hours'), 'orders')), "yesterday's order is not in today's hours");
        $this->assertSame(2, $this->json($res, 'stats.orders_today'));
    }

    public function test_monthly_target_is_checked_and_saved(): void
    {
        $this->actingAsAdmin(1);
        foreach ([['target' => 0], ['target' => -5], ['target' => 100001], ['target' => 'abc'], ['target' => ''], ['target' => null], [], ['target' => []]] as $body) {
            $this->assertInvalid($this->putJson('/api/admin/dashboard/target', $body), self::TARGET_ERROR);
        }
        $this->assertFalse(Settings::has('monthly_order_target'));

        $res = $this->putJson('/api/admin/dashboard/target', ['target' => 80]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'Monthly target saved.', 'target' => 80], $this->json($res));
        $this->assertSame('80', Settings::get('monthly_order_target'));
        $this->assertSame(80, $this->json($this->getJson('/api/admin/dashboard'), 'target'));

        // Like Laravel's (int) $request->input('target'): text and decimals are cut to a whole number.
        foreach ([[1, 1], [100000, 100000], ['250', 250], [' 42 ', 42], [80.9, 80], ['12abc', 12], [true, 1], [[7], 1]] as [$sent, $saved]) {
            $res = $this->putJson('/api/admin/dashboard/target', ['target' => $sent]);
            $this->assertStatusCode($res, 200);
            $this->assertSame(['message' => 'Monthly target saved.', 'target' => $saved], $this->json($res), var_export($sent, true));
            $this->assertSame((string) $saved, Settings::get('monthly_order_target'));
        }

        // The CSRF header is required (session filter).
        $this->assertStatusCode($this->withoutXsrfToken()->putJson('/api/admin/dashboard/target', ['target' => 5]), 419);
        $this->assertSame('1', Settings::get('monthly_order_target'));
    }

    // ------------------------------------------------------------------------------------------------------
    // GET /api/admin/orders
    // ------------------------------------------------------------------------------------------------------

    public function test_orders_are_paged_10_at_a_time_newest_first(): void
    {
        $statuses = ['new', 'contacted', 'done', 'cancelled'];
        for ($i = 1; $i <= 65; $i++) {
            $this->order(['customer_name' => "Customer {$i}", 'status' => $statuses[$i % 4]]);
        }
        $this->actingAsAdmin(1);

        $res = $this->getJson('/api/admin/orders');
        $this->assertStatusCode($res, 200);
        $this->assertSame(self::ORDERS_INDEX_KEYS, array_keys($this->json($res)));
        $this->assertSame(65, $this->json($res, 'total'));
        $this->assertSame(1, $this->json($res, 'page'));
        $this->assertSame(7, $this->json($res, 'pages'));
        $this->assertSame(range(65, 56), array_column($this->json($res, 'orders'), 'id'));
        $this->assertSame(OrderModel::STATUSES, $this->json($res, 'statuses'));
        $this->assertSame(Settings::defaults()['google_review_link'], $this->json($res, 'review_link'));
        $this->assertSame(self::ORDER_KEYS, array_keys($this->json($res, 'orders.0')));
        $this->assertSame(self::wire(OrderModel::serialize($this->orderRow(65))), $this->json($res, 'orders.0'));

        $res = $this->getJson('/api/admin/orders?page=2');
        $this->assertSame(range(55, 46), array_column($this->json($res, 'orders'), 'id'));
        $this->assertSame(2, $this->json($res, 'page'));
        $res = $this->getJson('/api/admin/orders?page=7');
        $this->assertSame(range(5, 1), array_column($this->json($res, 'orders'), 'id'));

        // Past the last page: Laravel's paginator keeps the page number and returns no orders.
        $res = $this->getJson('/api/admin/orders?page=8');
        $this->assertSame([[], 65, 8, 7], [$this->json($res, 'orders'), $this->json($res, 'total'), $this->json($res, 'page'), $this->json($res, 'pages')]);

        // Anything that isn't a whole number >= 1 is page 1 (FILTER_VALIDATE_INT: no leading zeros, no decimals).
        foreach (['0', '-1', 'abc', '2.5', '02', '1e1', '', '%20', 'page[]=2'] as $page) {
            $path = str_contains($page, '=') ? "/api/admin/orders?{$page}" : "/api/admin/orders?page={$page}";
            $res  = $this->getJson($path);
            $this->assertSame(1, $this->json($res, 'page'), $page);
            $this->assertSame(65, $this->json($res, 'orders.0.id'), $page);
        }
        // "%2B2" is "+2" (FILTER_VALIDATE_INT accepts the sign); spaces around the number are trimmed.
        foreach (['%2B2', '%202%20', '+2'] as $page) {
            $this->assertSame(2, $this->json($this->getJson("/api/admin/orders?page={$page}"), 'page'), $page);
        }
    }

    public function test_orders_filter_by_known_statuses_only(): void
    {
        $statuses = ['new', 'contacted', 'done', 'cancelled'];
        for ($i = 1; $i <= 40; $i++) {
            $this->order(['status' => $statuses[$i % 4]]);
        }
        $this->actingAsAdmin(1);

        $res = $this->getJson('/api/admin/orders?status=done');
        $this->assertSame(10, $this->json($res, 'total'));
        $this->assertSame(1, $this->json($res, 'pages'));
        $this->assertSame(['done'], array_values(array_unique(array_column($this->json($res, 'orders'), 'status'))));
        $this->assertSame([38, 34, 30], array_slice(array_column($this->json($res, 'orders'), 'id'), 0, 3));

        foreach (['bogus', 'Done', '', 'Delivered'] as $status) {
            $res = $this->getJson("/api/admin/orders?status={$status}");
            $this->assertSame(40, $this->json($res, 'total'), $status);
            $this->assertSame(4, $this->json($res, 'pages'), $status);
            $this->assertCount(10, $this->json($res, 'orders'));
        }
        $this->assertSame(10, $this->json($this->getJson('/api/admin/orders?status=%20new%20'), 'total'), 'the status is trimmed');
    }

    public function test_no_orders_and_the_review_link_setting(): void
    {
        $this->actingAsAdmin(1);
        $res = $this->getJson('/api/admin/orders');
        $this->assertSame([
            'orders' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'statuses' => OrderModel::STATUSES,
            'review_link' => Settings::defaults()['google_review_link'],
        ], $this->json($res));
        $res = $this->getJson('/api/admin/orders?page=5');
        $this->assertSame([[], 0, 5, 1], [$this->json($res, 'orders'), $this->json($res, 'total'), $this->json($res, 'page'), $this->json($res, 'pages')]);

        Settings::put('google_review_link', 'https://g.page/r/CityFlorist/review');
        $this->assertSame('https://g.page/r/CityFlorist/review', $this->json($this->getJson('/api/admin/orders'), 'review_link'));
        Settings::put('google_review_link', null);
        $this->assertSame('', $this->json($this->getJson('/api/admin/orders'), 'review_link'));
    }

    public function test_order_json_has_laravel_types(): void
    {
        $db = db_connect();
        $db->table('customers')->insert(['username' => 'asha', 'email' => 'asha@example.com', 'created_at' => date('Y-m-d H:i:s')]);
        $customer = (int) $db->insertID();
        $product  = $this->product(['price' => 1499.5]);
        $id       = $this->order([
            'customer_id' => $customer, 'product_id' => $product, 'product_name' => 'Rose Box', 'price_at_order' => '1499.50',
            'customer_name' => 'Asha Rao', 'customer_phone' => '919811111111', 'note' => 'Ring the bell', 'delivery_date' => '2026-12-01',
            'status' => 'contacted', 'created_at' => '2026-11-17 18:30:00',
        ]);

        $res = $this->actingAsAdmin(1)->getJson('/api/admin/orders');
        $this->assertSame([
            'id' => $id, 'customer_id' => $customer, 'product_id' => $product, 'product_name' => 'Rose Box', 'price_at_order' => 1499.5,
            'customer_name' => 'Asha Rao', 'customer_phone' => '919811111111', 'note' => 'Ring the bell', 'delivery_date' => '2026-12-01',
            'status' => 'contacted', 'created_at' => '2026-11-17T13:00:00.000000Z',
        ], $this->json($res, 'orders.0'));
    }

    // ------------------------------------------------------------------------------------------------------
    // PATCH / DELETE /api/admin/orders/{order}
    // ------------------------------------------------------------------------------------------------------

    public function test_order_status_changes_and_deletes(): void
    {
        $id    = $this->order(['created_at' => '2026-11-10 09:00:00', 'note' => 'Call first']);
        $other = $this->order(['status' => 'new']);
        $this->actingAsAdmin(1);

        $res = $this->patchJson("/api/admin/orders/{$id}", ['status' => 'done']);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['order', 'new_orders'], array_keys($this->json($res)));
        $this->assertSame(1, $this->json($res, 'new_orders'));
        $row = $this->orderRow($id);
        $this->assertSame('done', $row['status']);
        $this->assertSame('2026-11-10 09:00:00', $row['created_at'], 'created_at is not touched');
        $this->assertSame(self::wire(OrderModel::serialize($row)), $this->json($res, 'order'));
        $this->assertSame('done', $this->json($res, 'order.status'));

        // Same status again is fine too.
        $this->assertSame(1, $this->json($this->patchJson("/api/admin/orders/{$id}", ['status' => 'done']), 'new_orders'));
        $this->assertSame(0, $this->json($this->patchJson("/api/admin/orders/{$other}", ['status' => 'cancelled']), 'new_orders'));
        $this->assertSame(1, $this->json($this->patchJson("/api/admin/orders/{$other}", ['status' => 'new']), 'new_orders'));

        foreach ([['status' => 'nope'], ['status' => 'Done'], ['status' => 'Delivered'], ['status' => ''], ['status' => null], [], ['status' => 1]] as $body) {
            $this->assertInvalid($this->patchJson("/api/admin/orders/{$id}", $body), 'Unknown status.');
        }
        $this->assertSame('done', $this->orderRow($id)['status']);
        // A status with spaces around it is trimmed, as Laravel's TrimStrings does.
        $this->assertSame('contacted', $this->json($this->patchJson("/api/admin/orders/{$id}", ['status' => ' contacted ']), 'order.status'));

        // A missing order is "Not found." before the status is even looked at (route-model binding).
        $this->assertNotFound($this->patchJson('/api/admin/orders/999', ['status' => 'done']));
        $this->assertNotFound($this->patchJson('/api/admin/orders/999', ['status' => 'bogus']));

        $res = $this->deleteJson("/api/admin/orders/{$other}");
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'Order deleted.', 'new_orders' => 0], $this->json($res));
        $this->assertNull($this->orderRow($other));
        $this->assertNotFound($this->deleteJson("/api/admin/orders/{$other}"));
        $this->assertNotNull($this->orderRow($id));
    }

    // ------------------------------------------------------------------------------------------------------
    // GET /api/admin/orders/export
    // ------------------------------------------------------------------------------------------------------

    public function test_csv_export_is_the_same_file_laravel_wrote(): void
    {
        $this->order(['created_at' => '2026-11-10 09:05:00', 'customer_name' => '=HYPERLINK("http://evil")', 'customer_phone' => '919876543210', 'product_name' => 'Cake', 'price_at_order' => '1000.00', 'note' => '+1+1', 'status' => 'new']);
        $this->order(['created_at' => '2026-11-11 10:00:00', 'customer_name' => 'Ravi Kumar', 'customer_phone' => '919811111111', 'product_name' => '-5% Roses, red', 'price_at_order' => '1234.50', 'note' => '@SUM(A1)', 'delivery_date' => '2026-11-20', 'status' => 'done']);
        $this->order(['created_at' => '2026-11-12 11:00:00', 'customer_name' => "\tTabby", 'customer_phone' => '919822222222', 'product_name' => 'Lily "Deluxe"', 'price_at_order' => '0.00', 'note' => "\rCarriage", 'delivery_date' => '', 'status' => 'contacted']);
        $this->order(['created_at' => '2026-11-13 12:00:00', 'customer_name' => 'Plain', 'customer_phone' => '919833333333', 'product_name' => 'Rose', 'price_at_order' => '499.99', 'note' => "Line1\nLine2", 'status' => 'cancelled']);

        $res = $this->actingAsAdmin(1)->getJson('/api/admin/orders/export');
        $this->assertStatusCode($res, 200);
        $response = $res->response();
        $this->assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename=city-florist-orders-' . date('Y-m-d') . '.csv', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('no-cache, private', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));

        $expected = "\xEF\xBB\xBF"
            . "\"Order #\",Date,Customer,Phone,Product,Price,\"Delivery date\",Note,Status\n"
            . "4,\"2026-11-13 12:00:00\",Plain,+919833333333,Rose,499.99,,\"Line1\nLine2\",cancelled\n"
            . "3,\"2026-11-12 11:00:00\",\"'\tTabby\",+919822222222,\"Lily \"\"Deluxe\"\"\",0,,\"'\rCarriage\",contacted\n"
            . "2,\"2026-11-11 10:00:00\",\"Ravi Kumar\",+919811111111,\"'-5% Roses, red\",1234.5,2026-11-20,'@SUM(A1),done\n"
            . "1,\"2026-11-10 09:05:00\",\"'=HYPERLINK(\"\"http://evil\"\")\",+919876543210,Cake,1000,,'+1+1,new\n";
        $this->assertSame($expected, (string) $response->getBody());

        // Laravel's own check (ShopTest::test_csv_export_neutralises_formulas).
        $this->assertStringContainsString("'=HYPERLINK", (string) $response->getBody());
        $this->assertStringContainsString("'+1+1", (string) $response->getBody());

        // ?status= filters like the list; an unknown status exports everything.
        $done = (string) $this->getJson('/api/admin/orders/export?status=done')->response()->getBody();
        $this->assertSame("\xEF\xBB\xBF\"Order #\",Date,Customer,Phone,Product,Price,\"Delivery date\",Note,Status\n"
            . "2,\"2026-11-11 10:00:00\",\"Ravi Kumar\",+919811111111,\"'-5% Roses, red\",1234.5,2026-11-20,'@SUM(A1),done\n", $done);
        $this->assertSame($expected, (string) $this->getJson('/api/admin/orders/export?status=bogus')->response()->getBody());
    }

    public function test_csv_export_with_no_orders_is_just_the_header(): void
    {
        $res = $this->actingAsAdmin(1)->getJson('/api/admin/orders/export?status=cancelled');
        $this->assertStatusCode($res, 200);
        $this->assertSame("\xEF\xBB\xBF\"Order #\",Date,Customer,Phone,Product,Price,\"Delivery date\",Note,Status\n", (string) $res->response()->getBody());
    }

    // ------------------------------------------------------------------------------------------------------
    // /api/admin/reviews
    // ------------------------------------------------------------------------------------------------------

    public function test_reviews_list_waiting_first_with_full_details(): void
    {
        $r1 = $this->review(['name' => 'Priya Patil', 'rating' => 5, 'text' => 'Lovely roses', 'phone' => '919876543210', 'verified' => 1, 'status' => 'approved', 'created_at' => '2026-11-01 10:00:00']);
        $r2 = $this->review(['name' => 'Ravi', 'rating' => 3, 'text' => 'Okay flowers', 'status' => 'pending', 'created_at' => '2026-11-02 23:30:00']);
        $r3 = $this->review(['name' => 'Asha  Rao ', 'rating' => 4, 'text' => 'Nice work', 'phone' => '919811111111', 'status' => 'hidden', 'created_at' => '2026-11-03 08:00:00']);
        $r4 = $this->review(['name' => 'Meena K', 'rating' => 4, 'text' => 'Good bouquet', 'status' => 'approved', 'created_at' => '2026-11-04 08:00:00']);
        $r5 = $this->review(['name' => 'Zed', 'rating' => 1, 'text' => 'Late delivery', 'status' => 'pending', 'created_at' => '2026-11-05 08:00:00']);

        $res = $this->actingAsAdmin(1)->getJson('/api/admin/reviews');
        $this->assertStatusCode($res, 200);
        $this->assertSame(['reviews', 'statuses', 'pending', 'summary'], array_keys($this->json($res)));
        $this->assertSame([$r5, $r2, $r4, $r3, $r1], array_column($this->json($res, 'reviews'), 'id'));
        $this->assertSame(self::REVIEW_KEYS, array_keys($this->json($res, 'reviews.0')));
        $this->assertSame(
            ['id' => $r5, 'name' => 'Zed', 'rating' => 1, 'text' => 'Late delivery', 'verified' => false, 'date' => '2026-11-05', 'full_name' => 'Zed', 'phone' => null, 'status' => 'pending'],
            $this->json($res, 'reviews.0'),
        );
        $this->assertSame(
            ['id' => $r3, 'name' => 'Asha R.', 'rating' => 4, 'text' => 'Nice work', 'verified' => false, 'date' => '2026-11-03', 'full_name' => 'Asha  Rao ', 'phone' => '919811111111', 'status' => 'hidden'],
            $this->json($res, 'reviews.3'),
        );
        $this->assertSame(
            ['id' => $r1, 'name' => 'Priya P.', 'rating' => 5, 'text' => 'Lovely roses', 'verified' => true, 'date' => '2026-11-01', 'full_name' => 'Priya Patil', 'phone' => '919876543210', 'status' => 'approved'],
            $this->json($res, 'reviews.4'),
        );
        $this->assertSame(ReviewModel::STATUSES, $this->json($res, 'statuses'));
        $this->assertSame(2, $this->json($res, 'pending'));
        $this->assertSame(['count' => 2, 'average' => 4.5], $this->json($res, 'summary'));

        $this->assertSame([$r4, $r1], array_column($this->json($this->getJson('/api/admin/reviews?status=approved'), 'reviews'), 'id'));
        $this->assertSame([$r5, $r2], array_column($this->json($this->getJson('/api/admin/reviews?status=pending'), 'reviews'), 'id'));
        $this->assertSame([$r3], array_column($this->json($this->getJson('/api/admin/reviews?status=hidden'), 'reviews'), 'id'));
        $res = $this->getJson('/api/admin/reviews?status=bogus');
        $this->assertSame([$r5, $r2, $r4, $r3, $r1], array_column($this->json($res, 'reviews'), 'id'));
        $this->assertSame(2, $this->json($res, 'pending'), 'pending counts every waiting review, whatever the filter');
    }

    public function test_review_moderation_flow(): void
    {
        $id    = $this->review(['name' => 'Priya Patil', 'rating' => 5]);
        $other = $this->review(['name' => 'Ravi K', 'rating' => 4]);
        $this->actingAsAdmin(1);

        // ReviewTest: the owner approves it and it shows on the website.
        $res = $this->getJson('/api/admin/reviews');
        $this->assertSame(2, $this->json($res, 'pending'));
        $this->assertSame(['count' => 0, 'average' => 0], $this->json($res, 'summary'));

        $res = $this->patchJson("/api/admin/reviews/{$id}", ['status' => 'approved']);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'Review is now on the website.', 'pending' => 1], $this->json($res));
        $this->assertSame('approved', (new ReviewModel())->find($id)['status']);
        $site = $this->getJson('/api/site');
        $this->assertSame(['count' => 1, 'average' => 5], $this->json($site, 'reviews.summary'));
        $this->assertSame('Priya P.', $this->json($site, 'reviews.items.0.name'));

        $res = $this->patchJson("/api/admin/reviews/{$id}", ['status' => 'hidden']);
        $this->assertSame(['message' => 'Review hidden.', 'pending' => 1], $this->json($res));
        // Back to "waiting": Laravel says "Review hidden." for anything but approved.
        $res = $this->patchJson("/api/admin/reviews/{$id}", ['status' => 'pending']);
        $this->assertSame(['message' => 'Review hidden.', 'pending' => 2], $this->json($res));

        foreach ([['status' => 'bogus'], ['status' => 'Approved'], ['status' => ''], [], ['status' => 0]] as $body) {
            $this->assertInvalid($this->patchJson("/api/admin/reviews/{$id}", $body), 'Unknown status.');
        }
        $this->assertNotFound($this->patchJson('/api/admin/reviews/999', ['status' => 'approved']));
        $this->assertNotFound($this->patchJson('/api/admin/reviews/999', ['status' => 'bogus']));

        $res = $this->deleteJson("/api/admin/reviews/{$other}");
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'Review deleted.', 'pending' => 1], $this->json($res));
        $this->assertNull((new ReviewModel())->find($other));
        $this->assertNotFound($this->deleteJson("/api/admin/reviews/{$other}"));
        $this->assertSame(1, db_connect()->table('reviews')->countAllResults());
    }

    public function test_reviews_list_stops_at_200_and_rounds_the_average(): void
    {
        $db   = db_connect();
        $rows = [];
        for ($i = 1; $i <= 205; $i++) {
            $rows[] = ['name' => "Customer {$i}", 'rating' => [5, 4, 4][$i % 3], 'text' => 'Fresh flowers, thank you', 'phone' => null, 'verified' => 0, 'status' => $i % 2 ? 'approved' : 'pending', 'created_at' => '2026-11-10 10:00:00'];
        }
        $db->table('reviews')->insertBatch($rows);

        $res = $this->actingAsAdmin(1)->getJson('/api/admin/reviews');
        $this->assertCount(200, $this->json($res, 'reviews'));
        $this->assertSame(204, $this->json($res, 'reviews.0.id'), 'the newest waiting review comes first');
        $this->assertSame(102, $this->json($res, 'pending'));
        $approved = array_filter($rows, static fn (array $r): bool => $r['status'] === 'approved');
        $average  = round(array_sum(array_column($approved, 'rating')) / count($approved), 1);
        $this->assertSame(['count' => 103, 'average' => $average], $this->json($res, 'summary'));
        $this->assertSame(4.3, $average);
    }
}
