<?php

namespace Tests\Feature;

use App\Models\AdminModel;
use App\Models\CategoryModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\ReviewModel;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Port of the Laravel backend's tests/Feature/ReviewTest.php: reviews from the website wait for the owner,
 * the "verified order" badge, spam and bad input refused, HTML stripped, three reviews a day per device,
 * moderation only for the dashboard, the Google review settings and the sitemap.
 *
 * @internal
 */
final class ReviewTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Laravel boots a fresh application (and an empty array cache) for every test. Here the shared services
        // outlive a test: the rate limiter would keep an earlier test's attempt counts.
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }
        $this->install();
    }

    protected function tearDown(): void
    {
        foreach (['rateLimiter', 'googleOAuth', 'mailer'] as $service) {
            Services::resetSingle($service);
        }
        parent::tearDown();
    }

    /** Laravel's private send(): renamed because the base class already has a send() for every request. */
    private function sendReview(array $fields = []): TestResponse
    {
        return $this->postJson('/api/reviews', $fields + ['name' => 'Priya Patil', 'rating' => 5, 'text' => 'Beautiful roses, delivered on time!']);
    }

    private function firstAdmin(): array
    {
        $admin = (new AdminModel())->orderBy('id')->first();
        $this->assertNotNull($admin);

        return $admin;
    }

    private function reviewCount(): int
    {
        return db_connect()->table('reviews')->countAllResults();
    }

    public function test_review_waits_for_the_owner_before_it_shows(): void
    {
        $this->assertStatusCode($this->sendReview(), 201);
        $res = $this->getJson('/api/site');
        $this->assertJsonPath($res, 'reviews.summary.count', 0);
        $this->assertJsonCount($res, 0, 'reviews.items');

        $this->actingAsAdmin($this->firstAdmin());
        $res = $this->getJson('/api/admin/reviews');
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'pending', 1);
        $id  = $this->json($res, 'reviews.0.id');
        $res = $this->patchJson("/api/admin/reviews/{$id}", ['status' => 'approved']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'pending', 0);

        $res = $this->getJson('/api/site');
        $this->assertJsonPath($res, 'reviews.summary', ['count' => 1, 'average' => 5]);
        $this->assertJsonPath($res, 'reviews.items.0.name', 'Priya P.');       // surname shortened
        $this->assertJsonMissingPath($res, 'reviews.items.0.phone');
    }

    public function test_verified_badge_only_for_numbers_that_ordered(): void
    {
        (new OrderModel())->insert(['product_name' => 'Roses', 'price_at_order' => 500, 'customer_name' => 'Asha', 'customer_phone' => '919876543210', 'status' => 'done']);
        $this->assertStatusCode($this->sendReview(['phone' => '98765 43210']), 201);
        $this->assertStatusCode($this->sendReview(['phone' => '9123456789', 'name' => 'Ravi K']), 201);

        $this->assertTrue((new ReviewModel())->where('name', 'Priya Patil')->first()['verified']);
        $this->assertFalse((new ReviewModel())->where('name', 'Ravi K')->first()['verified']);
    }

    public function test_bad_and_spammy_reviews_are_refused(): void
    {
        $this->assertStatusCode($this->sendReview(['rating' => 0]), 422);
        $this->assertStatusCode($this->sendReview(['rating' => 6]), 422);
        $this->assertStatusCode($this->sendReview(['name' => 'A']), 422);
        $this->assertStatusCode($this->sendReview(['text' => 'ok']), 422);
        $this->assertStatusCode($this->sendReview(['text' => 'Great! Visit https://spam.example for cheap stuff']), 422);
        $this->assertStatusCode($this->sendReview(['phone' => '123']), 422);
        $this->assertStatusCode($this->sendReview(['website' => 'bot']), 400);
        $this->assertSame(0, $this->reviewCount());
    }

    public function test_html_is_stripped(): void
    {
        $this->assertStatusCode($this->sendReview(['name' => '<b>Priya</b>', 'text' => '<script>alert(1)</script>Lovely flowers for my mother']), 201);
        $r = (new ReviewModel())->orderBy('id')->first();
        $this->assertNotNull($r);
        $this->assertSame('Priya', $r['name']);
        $this->assertStringNotContainsString('<script>', $r['text']);
    }

    public function test_three_reviews_a_day_per_device(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertStatusCode($this->sendReview(), 201);
        }
        $this->assertStatusCode($this->sendReview(), 429);
    }

    public function test_only_the_owner_can_moderate(): void
    {
        $this->sendReview();
        $review = (new ReviewModel())->orderBy('id')->first();
        $this->assertNotNull($review);
        $id = $review['id'];
        $this->assertStatusCode($this->getJson('/api/admin/reviews'), 401);
        $this->assertStatusCode($this->patchJson("/api/admin/reviews/{$id}", ['status' => 'approved']), 401);
        $this->assertStatusCode($this->deleteJson("/api/admin/reviews/{$id}"), 401);

        $this->actingAsAdmin($this->firstAdmin());
        $this->assertStatusCode($this->patchJson("/api/admin/reviews/{$id}", ['status' => 'bogus']), 422);
        $this->assertStatusCode($this->deleteJson("/api/admin/reviews/{$id}"), 200);
        $this->assertSame(0, $this->reviewCount());
    }

    public function test_google_review_settings(): void
    {
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.google_review_link', static fn ($l) => str_starts_with($l, 'https://search.google.com/local/writereview?placeid='));

        $this->actingAsAdmin($this->firstAdmin());
        $base = ['whatsapp_number' => '919900094843'];
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['google_review_link' => 'https://evil.example/review']), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['google_rating' => '7']), 422);
        $this->assertStatusCode($this->putJson('/api/admin/settings/shop', $base + ['google_review_link' => 'https://g.page/r/abc/review', 'google_rating' => '4,7', 'google_review_count' => '40 reviews']), 200);
        $res = $this->getJson('/api/site');
        $this->assertJsonPath($res, 'settings.google_rating', '4.7');
        $this->assertJsonPath($res, 'settings.google_review_count', '40');
    }

    public function test_sitemap_lists_live_products_only(): void
    {
        $cat = (new CategoryModel())->orderBy('id')->first();
        $this->assertNotNull($cat);
        (new ProductModel())->insert(['category_id' => $cat['id'], 'name' => 'Live Rose', 'slug' => 'live-rose', 'price' => 100, 'is_active' => true]);
        (new ProductModel())->insert(['category_id' => $cat['id'], 'name' => 'Hidden', 'slug' => 'hidden-one', 'price' => 100, 'is_active' => false]);

        $res = $this->get('/sitemap.xml');
        $this->assertStatusCode($res, 200);
        $this->assertSame('application/xml; charset=utf-8', $res->response()->getHeaderLine('Content-Type'));
        $xml = (string) $res->response()->getBody();
        $this->assertStringContainsString('/product/live-rose', $xml);
        $this->assertStringNotContainsString('hidden-one', $xml);
        $this->assertStringContainsString('/privacy', $xml);
        $this->assertStringContainsString('/terms', $xml);
    }
}
