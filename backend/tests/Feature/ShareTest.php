<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use Config\Florist;
use Tests\Support\FeatureTestCase;

/**
 * GET /p/<slug> and /p/<slug>/photo.jpg: the product link in WhatsApp order messages, whose preview shows the
 * product's photo.
 *
 * @internal
 */
final class ShareTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        config(Florist::class)->siteUrl = 'https://shop.test';
    }

    /** A live product, with a 1200×1600 WebP photo in the test web root unless $photo is false. */
    /** The product link in order messages (Share::url): this backend's address + /p/<slug>. */
    private function share(string $slug): string
    {
        return rtrim(config('App')->baseURL, '/') . '/p/' . $slug;
    }

    private function product(string $name, bool $photo = true, bool $active = true): array
    {
        $cat   = (new CategoryModel())->where('slug', 'flower-bouquets')->first();
        $model = new ProductModel();
        $slug  = $model->uniqueSlug($name);
        $id    = (int) $model->insert(['category_id' => $cat['id'], 'name' => $name, 'slug' => $slug, 'price' => 1200, 'is_active' => $active]);
        if ($photo) {
            $path = "uploads/products/{$slug}.webp";
            $file = $this->publicFile('/' . $path);
            @mkdir(dirname($file), 0777, true);
            $img = imagecreatetruecolor(1200, 1600);
            imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 60));
            imagewebp($img, $file);
            (new ProductImageModel())->insert(['product_id' => $id, 'path' => $path, 'thumb_path' => $path, 'sort' => 0]);
        }

        return ['id' => $id, 'slug' => $slug];
    }

    public function test_share_page_carries_the_products_photo_and_sends_people_to_the_product_page(): void
    {
        $p   = $this->product('Red Rose Dome');
        $res = $this->get('/p/' . $p['slug']);
        $this->assertStatusCode($res, 200);
        $html = (string) $res->response()->getBody();

        $this->assertStringContainsString('<meta property="og:title" content="Red Rose Dome · City Florist Hubballi">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Flower Bouquets · ₹1,200">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="' . $this->share('red-rose-dome') . '/photo.jpg">', $html);
        $this->assertStringContainsString('<meta http-equiv="refresh" content="0; url=https://shop.test/product/red-rose-dome">', $html);
        $this->assertStringContainsString('location.replace("https://shop.test/product/red-rose-dome")', $html);
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_photo_is_a_small_jpeg(): void
    {
        $p   = $this->product('Red Rose Dome');
        $res = $this->get('/p/' . $p['slug'] . '/photo.jpg');
        $this->assertStatusCode($res, 200);
        $this->assertSame('image/jpeg', $res->response()->getHeaderLine('Content-Type'));
        $size = getimagesizefromstring((string) $res->response()->getBody());
        $this->assertSame([600, 800, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);

        // The second request is served from the cache with the same bytes.
        $again = $this->get('/p/' . $p['slug'] . '/photo.jpg');
        $this->assertSame((string) $res->response()->getBody(), (string) $again->response()->getBody());
    }

    public function test_products_without_a_photo_get_no_preview_image(): void
    {
        $p    = $this->product('Custom Garland', false);
        $html = (string) $this->get('/p/' . $p['slug'])->response()->getBody();
        $this->assertStringContainsString('og:title', $html);
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStatusCode($this->get('/p/' . $p['slug'] . '/photo.jpg'), 404);
    }

    public function test_unknown_and_hidden_products_go_to_the_shop(): void
    {
        $hidden = $this->product('Old Bouquet', true, false);
        foreach (['no-such-thing', $hidden['slug']] as $slug) {
            $res = $this->get('/p/' . $slug);
            $this->assertStatusCode($res, 302);
            $this->assertSame('https://shop.test/shop', $res->response()->getHeaderLine('Location'));
            $this->assertStatusCode($this->get('/p/' . $slug . '/photo.jpg'), 404);
        }
    }

    public function test_order_message_ends_with_the_share_link(): void
    {
        $p   = $this->product('Red Rose Dome');
        $res = $this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => 'Asha', 'phone' => '9876543210']);
        $this->assertStatusCode($res, 201);
        $this->assertStringEndsWith("*Phone:* +919876543210\n\n*Photo:* " . $this->share('red-rose-dome'), $this->json($res, 'message'));
    }
}
