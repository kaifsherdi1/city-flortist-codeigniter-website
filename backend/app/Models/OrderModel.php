<?php

namespace App\Models;

use App\Libraries\Text;
use CodeIgniter\Model;

class OrderModel extends Model
{
    public const STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'done' => 'Delivered', 'cancelled' => 'Cancelled'];

    protected $table         = 'orders';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'customer_id', 'product_id', 'product_name', 'price_at_order', 'customer_name', 'customer_phone', 'note', 'delivery_date', 'status',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected array $casts   = ['id' => 'int', 'customer_id' => '?int', 'product_id' => '?int', 'price_at_order' => 'float'];

    /**
     * An order as the dashboard receives it: every column, with numbers as numbers and created_at as an ISO date
     * in UTC ("2026-11-17T07:04:56.000000Z"), exactly how Laravel turned the Order model into JSON.
     * Pass $columns to send only some of them (in that order).
     */
    public static function serialize(array $order, ?array $columns = null): array
    {
        $all = [
            'id'             => (int) $order['id'],
            'customer_id'    => isset($order['customer_id']) ? (int) $order['customer_id'] : null,
            'product_id'     => isset($order['product_id']) ? (int) $order['product_id'] : null,
            'product_name'   => $order['product_name'] ?? null,
            'price_at_order' => isset($order['price_at_order']) ? (float) $order['price_at_order'] : null,
            'customer_name'  => $order['customer_name'] ?? null,
            'customer_phone' => $order['customer_phone'] ?? null,
            'note'           => $order['note'] ?? null,
            'delivery_date'  => $order['delivery_date'] ?? null,
            'status'         => $order['status'] ?? null,
            'created_at'     => Text::jsonDate($order['created_at'] ?? null),
        ];
        if ($columns === null) {
            return $all;
        }
        $out = [];
        foreach ($columns as $column) {
            $out[$column] = $all[$column] ?? null;
        }

        return $out;
    }
}
