<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Libraries\Catalog;
use App\Libraries\Settings;
use App\Models\OrderModel;
use CodeIgniter\Database\RawSql;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

/**
 * Dashboard → Home: the numbers and charts on the first screen, and the monthly order target.
 * Same answers as the Laravel version's Admin\DashboardController. Dates are in the shop's time zone (the app
 * time zone, Asia/Kolkata), like Laravel's today() and the created_at values the models store.
 */
class Dashboard extends ApiController
{
    /** GET /api/admin/dashboard: numbers and chart data for the home screen. */
    public function index(): ResponseInterface
    {
        $db         = db_connect();
        $today      = new DateTimeImmutable('today');                 // today() — 00:00:00
        $monthStart = $today->modify('first day of this month');     // today()->startOfMonth()
        $firstMonth = $monthStart->modify('-11 months');             // the oldest of the 12 month buckets

        // Every order of the last 12 months: when, how much, status and phone (Laravel: get([...]) on created_at >= …).
        $orders = $db->table('orders')
            ->select('created_at, price_at_order, status, customer_phone')
            ->where('created_at >=', $firstMonth->format('Y-m-d H:i:s'))
            ->get()->getResultArray();
        foreach ($orders as $i => $order) {
            $orders[$i]['at'] = new DateTimeImmutable($order['created_at']);
        }

        $todayKey = $today->format('Y-m-d');
        $days     = $this->buckets($orders, $today->modify('-29 days'), $today, '+1 day', 'Y-m-d');
        $hours    = $this->buckets(
            array_filter($orders, static fn (array $o): bool => $o['at']->format('Y-m-d') === $todayKey),
            $today,
            $today->setTime(23, 0),
            '+1 hour',
            'Y-m-d H',
        );
        $months = $this->buckets($orders, $firstMonth, $monthStart, '+1 month', 'Y-m');

        $week = array_slice($days, -7);
        // The 7 days before those: from 13 days ago 00:00:00 to 7 days ago 23:59:59 (whereBetween, both ends included).
        $prevWeek = $db->table('orders')
            ->select('price_at_order, status')
            ->where('created_at >=', $today->modify('-13 days')->format('Y-m-d H:i:s'))
            ->where('created_at <=', $today->modify('-7 days')->setTime(23, 59, 59)->format('Y-m-d H:i:s'))
            ->get()->getResultArray();
        $sum = static fn (array $list, string $key) => array_sum(array_column($list, $key));

        // This month (no end: like Laravel's gte(startOfMonth)) and last month, both ends included.
        $lastMonthStart = $monthStart->modify('-1 month');
        $lastMonthEnd   = $monthStart->modify('-1 second');
        $thisMonth      = array_values(array_filter($orders, static fn (array $o): bool => $o['at'] >= $monthStart));
        $lastMonth      = array_values(array_filter(
            $orders,
            static fn (array $o): bool => $o['at'] >= $lastMonthStart && $o['at'] <= $lastMonthEnd,
        ));
        // Distinct phone numbers, as Laravel's pluck('customer_phone')->unique()->count() (array_unique, SORT_REGULAR).
        $customers = static fn (array $list): int => count(array_unique(
            array_map(static fn (array $o) => $o['customer_phone'], $list),
            SORT_REGULAR,
        ));

        // All-time counts per status (Laravel: pluck('n', 'status')).
        $statuses = [];
        foreach ($db->table('orders')->select('status, COUNT(*) AS n', false)->groupBy('status')->get()->getResultArray() as $row) {
            $statuses[$row['status']] = $row['n'];
        }
        $total = $db->table('products')->countAllResults();

        // Collection where() compares loosely (== / !=); the bucket loop below compares strictly, as in Laravel.
        $notCancelled = static fn (array $o): bool => $o['status'] != 'cancelled';
        $statusKeys   = array_keys(OrderModel::STATUSES);

        return $this->json([
            'stats' => [
                'orders_today'         => end($days)['orders'],
                'orders_week'          => $sum($week, 'orders'),
                'orders_prev_week'     => count($prevWeek),
                'value_week'           => $sum($week, 'value'),
                'value_prev_week'      => (float) array_sum(array_map(
                    static fn (array $o): float => (float) $o['price_at_order'],
                    array_filter($prevWeek, $notCancelled),
                )),
                'orders_month'         => count($thisMonth),
                'customers_month'      => $customers($thisMonth),
                'customers_prev_month' => $customers($lastMonth),
                'new_orders'           => (int) ($statuses['new'] ?? 0),
                'live_products'        => $db->table('products')->where('is_active', 1)->countAllResults(),
                'running_offers'       => count(Catalog::activeOffers()),
            ],
            'target'   => (int) Settings::get('monthly_order_target', '50'),
            'days'     => $days,
            'hours'    => $hours,
            'months'   => $months,
            'statuses' => array_map(static fn ($key): int => (int) ($statuses[$key] ?? 0), array_combine($statusKeys, $statusKeys)),
            'goals'    => [
                'priced'          => $db->table('products')->where('price >', 0)->countAllResults(),
                // Products with at least one photo (Laravel: whereHas('images')).
                'with_photos'     => $db->table('products p')
                    ->where(new RawSql('EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)'))
                    ->countAllResults(),
                'featured'        => $db->table('products')->where('is_featured', 1)->where('is_active', 1)->countAllResults(),
                'products'        => $total,
                'delivered_month' => count(array_filter($thisMonth, static fn (array $o): bool => $o['status'] == 'done')),
                'orders_month'    => count(array_filter($thisMonth, $notCancelled)),
            ],
            'unpriced'   => $db->table('products')->where('price <=', 0)->where('is_active', 1)->countAllResults(),
            'categories' => array_map(
                static fn (array $c): array => ['name' => $c['name'], 'slug' => $c['slug'], 'count' => (int) $c['count']],
                $db->table('categories c')
                    ->select('c.name, c.slug, COUNT(p.id) AS count', false)
                    ->join('products p', new RawSql('p.category_id = c.id AND p.is_active = 1'), 'left')
                    ->groupBy(['c.id', 'c.name', 'c.slug', 'c.sort'])
                    ->orderBy('c.sort')->orderBy('c.id')
                    ->get()->getResultArray(),
            ),
            'recent' => array_map(
                static fn (array $o): array => OrderModel::serialize($o),
                $db->table('orders')->orderBy('id', 'DESC')->limit(6)->get()->getResultArray(),
            ),
        ]);
    }

    /** PUT /api/admin/dashboard/target {target}: the monthly order goal shown on the home screen. */
    public function target(): ResponseInterface
    {
        $target = (int) $this->input('target', 0);
        if ($target < 1 || $target > 100000) {
            $this->fail('Please enter a target between 1 and 100000 orders.');
        }
        Settings::put('monthly_order_target', (string) $target);

        return $this->json(['message' => 'Monthly target saved.', 'target' => $target]);
    }

    /**
     * Orders per time bucket (day, hour or month), oldest first: received, delivered, value (not counting
     * cancelled orders) and distinct customers. Each bucket is {customers, key, orders, delivered, value} in that
     * order (Laravel: ['customers' => n] + $bucket). The value stays the integer 0 until an order adds to it.
     *
     * @param array<int, array<string, mixed>> $orders rows with 'at' (created_at as a DateTimeImmutable)
     */
    private function buckets(array $orders, DateTimeImmutable $from, DateTimeImmutable $to, string $step, string $format): array
    {
        $out = [];
        for ($t = $from; $t <= $to; $t = $t->modify($step)) {
            $key       = $t->format($format);
            $out[$key] = ['key' => $key, 'orders' => 0, 'delivered' => 0, 'value' => 0, 'customers' => []];
        }
        foreach ($orders as $o) {
            $key = $o['at']->format($format);
            if (! isset($out[$key])) {
                continue;
            }
            $out[$key]['orders']++;
            $out[$key]['customers'][$o['customer_phone']] = true;
            if ($o['status'] === 'done') {
                $out[$key]['delivered']++;
            }
            if ($o['status'] !== 'cancelled') {
                $out[$key]['value'] += (float) $o['price_at_order'];
            }
        }

        return array_values(array_map(static fn (array $b): array => ['customers' => count($b['customers'])] + $b, $out));
    }
}
