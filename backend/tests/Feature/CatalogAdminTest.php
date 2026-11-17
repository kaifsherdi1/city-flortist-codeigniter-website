<?php

namespace Tests\Feature;

use App\Models\AdminModel;
use App\Models\CategoryModel;
use App\Models\OfferModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Port of the Laravel backend's tests/Feature/CatalogAdminTest.php:
 * products, photos, offers and orders as the dashboard and the website use them.
 *
 * @internal
 */
final class CatalogAdminTest extends FeatureTestCase
{
    private array $cat;

    /** @var list<string> Public paths of the photos this test made. */
    private array $made = [];

    /** @var list<string> Temporary folders holding the files this test uploaded. */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Laravel boots a fresh application (and an empty array cache) for every test. Here the shared rate limiter
        // outlives a test and would keep an earlier test's order counts.
        Services::resetSingle('rateLimiter');

        $this->install();
        $cat = (new CategoryModel())->where('slug', 'flower-bouquets')->first();
        $this->assertNotNull($cat);
        $this->cat = $cat;
        $admin     = (new AdminModel())->orderBy('id')->first();
        $this->assertNotNull($admin);
        $this->actingAsAdmin($admin);
    }

    protected function tearDown(): void
    {
        // Photos are written to the (temporary) web root's uploads folder; remove the ones this test made.
        foreach ($this->made as $path) {
            @unlink($this->publicFile($path));
        }
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        Services::resetSingle('rateLimiter');
        parent::tearDown();
    }

    /** A file with this name and content, as the browser uploads it (UploadedFile::fake()->createWithContent). */
    private function file(string $name, string $content): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cf-catalog-' . bin2hex(random_bytes(5));
        mkdir($dir);
        $this->tempDirs[] = $dir;
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $content);

        return $dir . DIRECTORY_SEPARATOR . $name;
    }

    /** A real 400×300 PNG uploaded under the name "rose.png" (the Laravel test's photo()). */
    private function rosePhoto(string $name = 'rose.png'): string
    {
        $png = $this->photo();
        $path = $this->file($name, (string) file_get_contents($png));
        @unlink($png);

        return $path;
    }

    private function addProduct(array $fields = []): array
    {
        $res = $this->postMultipart('/api/admin/products', $fields + [
            'name' => 'Rose Box', 'category_id' => $this->cat['id'], 'price' => 1200, 'is_active' => 1,
        ], ['images' => [$this->rosePhoto()]]);
        $this->assertStatusCode($res, 201);
        foreach ($this->json($res, 'product.images') as $i) {
            $this->made[] = ltrim($i['src'], '/');
            $this->made[] = ltrim($i['thumb'], '/');
        }

        return $this->json($res, 'product');
    }

    public function test_product_with_photo_is_saved_as_webp_and_shown_on_the_site(): void
    {
        $p = $this->addProduct();
        $this->assertCount(1, $p['images']);
        $this->assertStringEndsWith('.webp', $p['images'][0]['src']);
        $this->assertFileExists($this->publicFile(ltrim($p['images'][0]['src'], '/')));

        $res = $this->getJson("/api/products?slug={$p['slug']}");
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'product.name', 'Rose Box');
        $this->assertJsonPath($res, 'product.pricing.final', 1200);
    }

    public function test_a_file_that_is_not_a_photo_is_refused(): void
    {
        $fake = $this->file('shell.php.png', '<?php echo 1; ?>');
        $res  = $this->postMultipart('/api/admin/products', ['name' => 'X', 'category_id' => $this->cat['id']], ['images' => [$fake]]);
        $this->assertStatusCode($res, 201);
        $this->assertSame([], $this->json($res, 'product.images'));
        $this->assertStringContainsString('Not a valid image', $this->json($res, 'warnings.0'));
    }

    public function test_photos_of_one_product_cant_be_changed_through_another(): void
    {
        $a        = $this->addProduct(['name' => 'A']);
        $b        = $this->addProduct(['name' => 'B']);
        $imageOfB = $b['images'][0]['id'];

        $this->assertStatusCode($this->deleteJson("/api/admin/products/{$a['id']}/images/{$imageOfB}"), 404);
        $this->assertStatusCode($this->postJson("/api/admin/products/{$a['id']}/images/{$imageOfB}/main"), 404);
        $this->assertFileExists($this->publicFile(ltrim($b['images'][0]['src'], '/')));

        $res = $this->deleteJson("/api/admin/products/{$b['id']}/images/{$imageOfB}");
        $this->assertStatusCode($res, 200);
        $this->assertJsonCount($res, 0, 'product.images');
        $this->assertFileDoesNotExist($this->publicFile(ltrim($b['images'][0]['src'], '/')));
    }

    public function test_deleting_a_product_deletes_its_photos(): void
    {
        $p = $this->addProduct();
        $this->assertStatusCode($this->deleteJson("/api/admin/products/{$p['id']}"), 200);
        $this->assertFileDoesNotExist($this->publicFile(ltrim($p['images'][0]['src'], '/')));
        $this->assertStatusCode($this->getJson("/api/products?slug={$p['slug']}"), 404);
    }

    public function test_offer_rules(): void
    {
        $p    = $this->addProduct();
        $base = ['title' => 'Diwali', 'target' => "product:{$p['id']}", 'is_active' => 1, 'starts_at' => date('Y-m-d\TH:i', time() - 3600), 'ends_at' => date('Y-m-d\TH:i', time() + 86400)];

        $this->assertStatusCode($this->postJson('/api/admin/offers', ['title' => ''] + $base), 422);
        $this->assertStatusCode($this->postJson('/api/admin/offers', ['ends_at' => date('Y-m-d\TH:i', time() - 86400)] + $base), 422);
        // Discount is kept between 0 and 90 %, whatever is sent.
        $this->assertStatusCode($this->postJson('/api/admin/offers', ['discount_pct' => 500] + $base), 201);
        $this->assertSame(90, (new OfferModel())->orderBy('id', 'DESC')->first()['discount_pct']);
        // A product or category that doesn't exist is refused, not turned into a site-wide discount.
        $this->assertStatusCode($this->postJson('/api/admin/offers', ['discount_pct' => 10, 'target' => 'product:99999'] + $base), 422);
        $this->assertStatusCode($this->postJson('/api/admin/offers', ['discount_pct' => 10, 'target' => 'category:99999'] + $base), 422);
        $this->assertStatusCode($this->postJson('/api/admin/offers', ['discount_pct' => 10, 'target' => 'nonsense'] + $base), 422);
        $this->assertSame(1, (new OfferModel())->countAllResults());

        $this->assertJsonPath($this->getJson("/api/products?slug={$p['slug']}"), 'product.pricing.final', 120);
    }

    public function test_deleting_a_product_does_not_turn_its_offer_into_a_site_wide_discount(): void
    {
        $p     = $this->addProduct(['name' => 'On offer']);
        $other = $this->addProduct(['name' => 'Full price']);
        $this->assertStatusCode($this->postJson('/api/admin/offers', ['title' => 'Only this one', 'discount_pct' => 30, 'target' => "product:{$p['id']}", 'is_active' => 1,
            'starts_at' => date('Y-m-d\TH:i', time() - 3600), 'ends_at' => date('Y-m-d\TH:i', time() + 86400)]), 201);

        $this->assertStatusCode($this->deleteJson("/api/admin/products/{$p['id']}"), 200);

        $this->assertSame(0, (new OfferModel())->countAllResults());
        $this->assertJsonPath($this->getJson("/api/products?slug={$other['slug']}"), 'product.pricing.final', 1200);
        $this->assertJsonCount($this->getJson('/api/site'), 0, 'offers');
    }

    public function test_orders_for_hidden_or_missing_products_are_refused(): void
    {
        $p = $this->addProduct(['is_active' => 0]);
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => 'Asha', 'phone' => '9876543210']), 404);
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => 99999, 'name' => 'Asha', 'phone' => '9876543210']), 404);
        $this->assertSame(0, (new OrderModel())->countAllResults());
    }

    public function test_order_spam_from_one_device_is_limited(): void
    {
        $p = $this->addProduct();
        for ($i = 0; $i < 10; $i++) {
            $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => 'Asha', 'phone' => '9876543210']), 201);
        }
        $this->assertStatusCode($this->postJson('/api/orders', ['product_id' => $p['id'], 'name' => 'Asha', 'phone' => '9876543210']), 429);
        $this->assertSame(10, (new OrderModel())->countAllResults());
    }

    public function test_bulk_edit_ignores_products_that_dont_exist(): void
    {
        $id = (int) (new ProductModel())->insert(['category_id' => $this->cat['id'], 'name' => 'Lily', 'slug' => 'lily', 'price' => 500, 'is_active' => true]);
        $this->assertStatusCode($this->postJson('/api/admin/products/bulk', ['rows' => [
            $id   => ['name' => 'Lily Deluxe', 'price' => '650', 'active' => 1],
            99999 => ['name' => 'Ghost', 'price' => '1'],
        ]]), 200);
        $this->assertSame('Lily Deluxe', (new ProductModel())->find($id)['name']);
        $this->assertSame(1, (new ProductModel())->countAllResults());
    }
}
