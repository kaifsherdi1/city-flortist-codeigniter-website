<?php

namespace App\Controllers\Api;

use App\Controllers\ApiController;
use App\Libraries\Text;
use App\Models\OrderModel;
use App\Models\ReviewModel;
use CodeIgniter\HTTP\ResponseInterface;

/** Reviews written on the public website: POST /api/reviews. */
class Reviews extends ApiController
{
    /**
     * POST /api/reviews {name, rating, text, phone?}
     * Saved as "pending": the owner approves it in the dashboard before it appears on the website.
     */
    public function store(): ResponseInterface
    {
        if ($this->filled('website')) { // honeypot
            $this->fail('Invalid request', 400);
        }
        $name     = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->input('name', '')))), 0, 60);
        $text     = mb_substr(trim(strip_tags((string) $this->input('text', ''))), 0, 1000);
        $rating   = (int) $this->input('rating', 0);
        $phoneRaw = trim((string) $this->input('phone', ''));
        $phone    = $phoneRaw === '' ? null : Text::normalizePhone($phoneRaw);

        if (mb_strlen($name) < 2) {
            $this->fail('Please enter your name.', 422);
        }
        if ($rating < 1 || $rating > 5) {
            $this->fail('Please choose 1 to 5 stars.', 422);
        }
        if (mb_strlen($text) < 10) {
            $this->fail('Please write a few words about your order (at least 10 letters).', 422);
        }
        if ($phoneRaw !== '' && ! $phone) {
            $this->fail('Please enter a valid 10-digit mobile number, or leave it empty.', 422);
        }
        // Links are the usual sign of review spam.
        if (preg_match('~https?://|www\.~i', $text)) {
            $this->fail('Please leave out web links.', 422);
        }

        $limiter = service('rateLimiter');
        $key     = 'review:' . $this->ip();
        if ($limiter->tooManyAttempts($key, 3)) {
            $this->fail('Thank you! You have already sent reviews today.', 429);
        }
        $limiter->hit($key, 24 * 3600);

        (new ReviewModel())->insert([
            'name'   => $name,
            'rating' => $rating,
            'text'   => $text,
            'phone'  => $phone,
            // "Verified order": the number placed an order on this website.
            'verified' => $phone !== null && (new OrderModel())->builder()->where('customer_phone', $phone)->countAllResults() > 0,
            'status'   => 'pending',
        ]);

        return $this->json(['message' => 'Thank you! Your review will appear on the website once the shop has seen it.'], 201);
    }
}
