<?php

namespace App\Controllers\Api;

use App\Controllers\ApiController;
use App\Controllers\Share;
use App\Libraries\Catalog as Shop;
use App\Libraries\Settings;
use App\Libraries\Text;
use App\Models\OrderModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;

/** Orders placed on the public website: POST /api/orders. */
class Orders extends ApiController
{
    /**
     * POST /api/orders  {product_id, name, phone, note?, delivery_date?}
     * Saves the order and returns the WhatsApp link the customer is sent to.
     * Price and product name always come from the database, never from the browser.
     */
    public function store(): ResponseInterface
    {
        // Honeypot: real visitors never fill this hidden field.
        if ($this->filled('website')) {
            $this->fail('Invalid request', 400);
        }

        $name  = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $this->input('name', ''))), 0, 80);
        $phone = Text::normalizePhone((string) $this->input('phone', ''));
        $note  = mb_substr(trim((string) $this->input('note', '')), 0, 500);
        $date  = (string) $this->input('delivery_date', '');
        $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;

        if (mb_strlen($name) < 2) {
            $this->fail('Please enter your name.', 422);
        }
        if (! $phone) {
            $this->fail('Please enter a valid 10-digit mobile number.', 422);
        }

        $productId = (int) $this->input('product_id', 0);
        $found     = Shop::products(static fn (BaseBuilder $q) => $q->where('p.id', $productId), ['p.id'], 1);
        if ($found === []) {
            $this->fail('This product is no longer available.', 404);
        }
        $product = $found[0];
        $pricing = $product['pricing'];

        $limiter = service('rateLimiter');
        $key     = 'order:' . $this->ip();
        if ($limiter->tooManyAttempts($key, 10)) {
            $this->fail('Too many orders from this device. Please message us directly on WhatsApp.', 429);
        }
        $limiter->hit($key, 30 * 60);

        $orderId = (int) (new OrderModel())->insert([
            'product_id'     => $product['id'],
            'product_name'   => $product['name'],
            'price_at_order' => $pricing['final'],
            'customer_name'  => $name,
            'customer_phone' => $phone,
            'note'           => $note ?: null,
            'delivery_date'  => $date,
            'status'         => 'new',
        ]);

        $message = $this->message($orderId, $product, $name, $phone, $date, $note);

        return $this->json([
            'order_id' => $orderId,
            'message'  => $message,
            'whatsapp' => 'https://wa.me/' . Text::digits(Settings::get('whatsapp_number')) . '?text=' . rawurlencode($message),
        ], 201);
    }

    private function message(int $orderId, array $product, string $name, string $phone, ?string $date, string $note): string
    {
        $p = $product['pricing'];
        if ($p['on_request']) {
            $price = 'Please share the price';
        } elseif ($p['final'] < $p['price']) {
            $price = Text::rupees($p['final']) . ' (MRP ' . Text::rupees($p['price']) . ', ' . $p['discount_pct'] . '% off)';
        } else {
            $price = Text::rupees($p['final']);
        }

        $lines = [
            "🌸 *New Order #{$orderId} – City Florist*",
            '',
            '*Product:* ' . $product['name'],
            '*Category:* ' . $product['category']['name'],
            '*Price:* ' . $price,
            '',
            '*Name:* ' . $name,
            '*Phone:* +' . $phone,
        ];
        if ($date) {
            $lines[] = '*Needed on:* ' . date('d M Y', strtotime($date));
        }
        if ($note) {
            $lines[] = '*Note:* ' . $note;
        }
        // The product link goes last: WhatsApp turns it into a preview with the product's photo (see Share), so
        // the owner sees which bouquet the customer is asking about.
        $lines[] = '';
        $lines[] = '*Photo:* ' . Share::url($product['slug']);

        return implode("\n", $lines);
    }
}
