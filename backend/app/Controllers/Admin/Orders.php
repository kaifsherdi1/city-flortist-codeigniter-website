<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Libraries\Http;
use App\Libraries\Settings;
use App\Models\OrderModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Dashboard → Orders: every "Buy" from the website, newest first, with status changes and a CSV download.
 * Same answers as the Laravel version's Admin\OrderController.
 */
class Orders extends ApiController
{
    private const PER_PAGE = 30;

    /** GET /api/admin/orders?status=&page= */
    public function index(): ResponseInterface
    {
        $status = $this->statusParam();
        $page   = $this->currentPage();
        $total  = $this->filtered($status)->countAllResults();
        // Laravel's paginate(): the page is not limited to the last one (a page past the end is simply empty).
        $rows = $total
            ? $this->filtered($status)->orderBy('id', 'DESC')
                ->limit(self::PER_PAGE, max(0, (int) (($page - 1) * self::PER_PAGE)))
                ->get()->getResultArray()
            : [];

        return $this->json([
            'orders'   => array_map(static fn (array $o): array => OrderModel::serialize($o), $rows),
            'total'    => $total,
            'page'     => $page,
            'pages'    => max((int) ceil($total / self::PER_PAGE), 1),
            'statuses' => OrderModel::STATUSES,
            // For the "Ask for a review" button on delivered orders.
            'review_link' => Settings::get('google_review_link'),
        ]);
    }

    /** PATCH /api/admin/orders/{order} {status} */
    public function update(int|string $id): ResponseInterface
    {
        $order  = $this->order((int) $id);
        $status = (string) $this->input('status');
        if (! isset(OrderModel::STATUSES[$status])) {
            $this->fail('Unknown status.');
        }
        (new OrderModel())->builder()->where('id', $order['id'])->update(['status' => $status]);
        // Laravel answered with the bound model after update(): the row as loaded, with the new status.
        $order['status'] = $status;

        return $this->json(['order' => OrderModel::serialize($order), 'new_orders' => $this->newOrders()]);
    }

    /** DELETE /api/admin/orders/{order} */
    public function destroy(int|string $id): ResponseInterface
    {
        $order = $this->order((int) $id);
        (new OrderModel())->builder()->where('id', $order['id'])->delete();

        return $this->json(['message' => 'Order deleted.', 'new_orders' => $this->newOrders()]);
    }

    /**
     * GET /api/admin/orders/export?status=: CSV that opens cleanly in Excel. The same bytes as the Laravel
     * version's streamDownload(): UTF-8 BOM, fputcsv(',', '"', '') lines, created_at as "Y-m-d H:i:s", the price as
     * PHP prints a float ("1000", "1234.5"), and the same headers.
     */
    public function export(): ResponseInterface
    {
        $orders = $this->filtered($this->statusParam())->orderBy('id', 'DESC')->get()->getResultArray();

        $out = fopen('php://temp', 'w+b');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Order #', 'Date', 'Customer', 'Phone', 'Product', 'Price', 'Delivery date', 'Note', 'Status'], ',', '"', '');
        // Text typed by customers could start with = + - @ and run as a formula in Excel; a leading ' stops that.
        $text = static fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        foreach ($orders as $o) {
            fputcsv($out, [
                (int) $o['id'], $o['created_at'], $text($o['customer_name']), '+' . $o['customer_phone'], $text($o['product_name']),
                (float) $o['price_at_order'], $o['delivery_date'], $text($o['note']), $o['status'],
            ], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        // Laravel answered with a StreamedResponse: Symfony's default "no-cache, private" (its ApiJson middleware only
        // touches JSON), and Symfony leaves a file name made only of token characters unquoted.
        return Http::cacheControl($this->response, Http::NO_CACHE)
            ->setStatusCode(200)
            ->setContentType('text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename=city-florist-orders-' . date('Y-m-d') . '.csv')
            ->setBody($csv);
    }

    /** ?status= (query string only), as Laravel's (string) $request->query('status', ''). */
    private function statusParam(): string
    {
        return (string) $this->query('status', '');
    }

    /** Orders, filtered by status when it is a known one. */
    private function filtered(string $status): BaseBuilder
    {
        $q = db_connect()->table('orders');
        if (isset(OrderModel::STATUSES[$status])) {
            $q->where('status', $status);
        }

        return $q;
    }

    /** Laravel's paginator: ?page= when it is a whole number of at least 1, otherwise page 1. */
    private function currentPage(): int
    {
        $page = $this->input('page');

        return filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1 ? (int) $page : 1;
    }

    /** The order for {order} in the URL (Laravel's route-model binding: 404 "Not found." when it doesn't exist). */
    private function order(int $id): array
    {
        return (new OrderModel())->find($id) ?? $this->missing();
    }

    private function newOrders(): int
    {
        return db_connect()->table('orders')->where('status', 'new')->countAllResults();
    }
}
