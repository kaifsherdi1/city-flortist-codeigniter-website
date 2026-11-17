<?php

namespace Tests\Feature\Detailed;

use App\Libraries\Passwords;
use App\Models\AdminModel;
use App\Models\OfferModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use CodeIgniter\Test\TestResponse;
use Tests\Support\FeatureTestCase;

/**
 * Dashboard products, photos and offers (/api/admin/categories, /api/admin/products*, /api/admin/offers*),
 * checked against the Laravel version's Admin\ProductController and Admin\OfferController and its tests
 * (CatalogAdminTest, ShopTest): exact messages, JSON keys, order and types, check order, files on disk.
 *
 * @internal
 */
final class ProductsOffersTest extends FeatureTestCase
{
    private const PRODUCT_KEYS = ['id', 'name', 'slug', 'category_id', 'description', 'price', 'sale_price', 'is_active', 'is_featured', 'images'];

    private const ROW_KEYS = ['id', 'name', 'slug', 'category', 'price', 'sale_price', 'active', 'featured', 'ready', 'thumb'];

    private const OFFER_KEYS = ['id', 'title', 'subtitle', 'discount_pct', 'target', 'applies_to', 'starts_at', 'ends_at', 'is_active', 'image', 'state'];

    private const GONE = 'That product or category no longer exists. Please choose what the offer is for again.';

    private int $cat;

    private int $cakes;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->cat   = $this->categoryId('flower-bouquets');
        $this->cakes = $this->categoryId('cakes');
        $this->actingAsAdmin(1);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------

    private function categoryId(string $slug): int
    {
        return (int) db_connect()->table('categories')->where('slug', $slug)->get()->getRowArray()['id'];
    }

    /** A product added through the dashboard form, with a photo (CatalogAdminTest::addProduct). */
    private function addProduct(array $fields = [], ?array $photos = null): array
    {
        $res = $this->postMultipart('/api/admin/products', $fields + [
            'name' => 'Rose Box', 'category_id' => (string) $this->cat, 'price' => '1200', 'is_active' => '1',
        ], ['images' => $photos ?? [$this->photo()]]);
        $this->assertStatusCode($res, 201);

        return $this->json($res, 'product');
    }

    /** A product row straight in the database (Product::create in the Laravel tests). */
    private function makeProduct(array $attrs = []): int
    {
        $model = new ProductModel();

        return (int) $model->insert($attrs + [
            'category_id' => $this->cakes,
            'name'        => 'Truffle Cake',
            'slug'        => $model->uniqueSlug($attrs['name'] ?? 'Truffle Cake'),
            'price'       => 1000,
            'is_active'   => true,
        ]);
    }

    private function productRow(int $id): ?array
    {
        return db_connect()->table('products')->where('id', $id)->get()->getRowArray();
    }

    private function offerRow(int $id): ?array
    {
        return db_connect()->table('offers')->where('id', $id)->get()->getRowArray();
    }

    private function setting(string $name): ?string
    {
        $row = db_connect()->table('settings')->where('name', $name)->get()->getRowArray();

        return $row['value'] ?? null;
    }

    /** A file with a chosen name and content (e.g. a script dressed up as a photo). */
    private function fakeFile(string $name, string $content): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cf-scratch-' . bin2hex(random_bytes(5));
        mkdir($dir);
        $this->tempDirs[] = $dir;
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $content);

        return $dir . DIRECTORY_SEPARATOR . $name;
    }

    /** A time as <input type="datetime-local"> sends it. */
    private function local(int $timestamp): string
    {
        return date('Y-m-d\TH:i', $timestamp);
    }

    private function offerFields(array $fields = []): array
    {
        return $fields + [
            'title' => 'Diwali', 'target' => 'all', 'is_active' => 1,
            'starts_at' => $this->local(time() - 3600), 'ends_at' => $this->local(time() + 86400),
        ];
    }

    /** Form fields as a browser sends them: all text. */
    private function text(array $fields): array
    {
        return array_map('strval', $fields);
    }

    private function body(TestResponse $res): string
    {
        return (string) $res->response()->getBody();
    }

    private function error(string $message): string
    {
        return json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Files under <web root>/uploads/<dir>. */
    private function uploadsIn(string $dir): array
    {
        $path = $this->publicRoot . 'uploads' . DIRECTORY_SEPARATOR . $dir;

        return is_dir($path) ? array_values(array_diff(scandir($path), ['.', '..'])) : [];
    }

    /** @param list<int> $ids */
    private function sorts(array $ids): array
    {
        return array_map(static fn (int $id): int => (int) db_connect()->table('product_images')->where('id', $id)->get()->getRowArray()['sort'], $ids);
    }

    private function assertRefused(string $path, array $fields, string $message): void
    {
        $res = $this->postJson($path, $fields);
        $this->assertStatusCode($res, 422);
        $this->assertSame($this->error($message), $this->body($res));
    }

    // ------------------------------------------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------------------------------------------

    public function test_products_and_offers_need_a_dashboard_login_and_staff_may_use_them(): void
    {
        $this->newBrowser();
        foreach (['/api/admin/categories', '/api/admin/products', '/api/admin/products/1', '/api/admin/offers'] as $path) {
            $res = $this->getJson($path);
            $this->assertStatusCode($res, 401);
            $this->assertSame($this->error('Please log in again.'), $this->body($res));
        }
        $this->assertStatusCode($this->postJson('/api/admin/products', ['name' => 'X', 'category_id' => $this->cat]), 401);
        $this->assertStatusCode($this->postJson('/api/admin/offers', $this->offerFields()), 401);
        $this->assertSame(0, db_connect()->table('products')->countAllResults());
        $this->assertSame(0, db_connect()->table('offers')->countAllResults());

        // Staff manage products and offers (AccountTest).
        $staff = (int) (new AdminModel())->insert([
            'username' => 'ravi', 'email' => 'ravi@example.com', 'password_hash' => Passwords::hash('Roses2026'), 'role' => 'staff', 'status' => 'active',
        ]);
        $this->actingAsAdmin($staff);
        $this->assertStatusCode($this->getJson('/api/admin/products'), 200);
        $this->assertStatusCode($this->getJson('/api/admin/offers'), 200);
        $this->assertStatusCode($this->postJson('/api/admin/products', ['name' => 'Lily', 'category_id' => $this->cat]), 201);

        // A switched-off account loses access on its next request.
        (new AdminModel())->update($staff, ['status' => 'disabled']);
        $this->assertStatusCode($this->getJson('/api/admin/products'), 401);
    }

    // ------------------------------------------------------------------------------------------------------
    // Categories and the product list
    // ------------------------------------------------------------------------------------------------------

    public function test_categories_for_the_pickers(): void
    {
        $res = $this->getJson('/api/admin/categories');
        $this->assertStatusCode($res, 200);
        $expected = array_map(
            static fn (array $c): array => ['id' => (int) $c['id'], 'name' => $c['name'], 'slug' => $c['slug']],
            db_connect()->table('categories')->orderBy('sort')->orderBy('id')->get()->getResultArray(),
        );
        $this->assertCount(8, $expected);
        $this->assertSame(['categories' => $expected], $this->json($res));
        $this->assertStringStartsWith('{"categories":[{"id":1,"name":"Flower Bouquets","slug":"flower-bouquets"},', $this->body($res));
        $this->assertSame('no-store, private', $res->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('application/json', $res->response()->getHeaderLine('Content-Type'));
    }

    public function test_product_list_filters_thumbnails_ready_flags_and_types(): void
    {
        $garlands = $this->categoryId('garlands');
        $red      = $this->makeProduct(['category_id' => $this->cat, 'name' => 'Red Rose Bouquet', 'price' => 1200.5, 'sale_price' => 999, 'is_featured' => true]);
        $lily     = $this->makeProduct(['category_id' => $this->cat, 'name' => 'White Lily', 'price' => 0, 'is_active' => false]);
        $cake     = $this->makeProduct(['name' => 'Truffle Cake']);
        $garland  = $this->makeProduct(['category_id' => $garlands, 'name' => 'Rose Garland', 'price' => 0, 'is_featured' => true]);
        $pink     = $this->makeProduct(['category_id' => $this->cat, 'name' => 'Pink Roses', 'price' => 800, 'sort' => -1]);

        // The thumbnail is the first photo by sort, then id.
        $images = new ProductImageModel();
        $images->insert(['product_id' => $red, 'path' => 'uploads/products/r1.webp', 'thumb_path' => 'uploads/products/r1-thumb.webp', 'sort' => 1]);
        $images->insert(['product_id' => $red, 'path' => 'uploads/products/r2.webp', 'thumb_path' => 'uploads/products/r2-thumb.webp', 'sort' => 0]);
        $images->insert(['product_id' => $red, 'path' => 'uploads/products/r3.webp', 'thumb_path' => 'uploads/products/r3-thumb.webp', 'sort' => 0]);
        $images->insert(['product_id' => $cake, 'path' => 'uploads/products/c1.webp', 'thumb_path' => 'uploads/products/c1-thumb.webp', 'sort' => 0]);

        $res = $this->getJson('/api/admin/products');
        $this->assertStatusCode($res, 200);
        $this->assertSame(['products'], array_keys($this->json($res)));
        $rows = $this->json($res, 'products');
        // Category order, then product sort, then newest first.
        $this->assertSame([$pink, $lily, $red, $garland, $cake], array_column($rows, 'id'));
        foreach ($rows as $row) {
            $this->assertSame(self::ROW_KEYS, array_keys($row));
        }
        $byId = array_column($rows, null, 'id');
        // Until the owner chooses, "Ready to go" is the first live flower bouquets, featured first.
        $this->assertSame(
            ['id' => $red, 'name' => 'Red Rose Bouquet', 'slug' => 'red-rose-bouquet', 'category' => 'Flower Bouquets', 'price' => 1200.5, 'sale_price' => 999,
                'active' => true, 'featured' => true, 'ready' => true, 'thumb' => '/uploads/products/r2-thumb.webp'],
            $byId[$red],
        );
        $this->assertSame(
            ['id' => $lily, 'name' => 'White Lily', 'slug' => 'white-lily', 'category' => 'Flower Bouquets', 'price' => 0, 'sale_price' => null,
                'active' => false, 'featured' => false, 'ready' => false, 'thumb' => null],
            $byId[$lily],
        );
        $this->assertSame([true, false, false, true], [$byId[$pink]['ready'], $byId[$garland]['ready'], $byId[$cake]['ready'], $byId[$cake]['active']]);
        $this->assertSame('/uploads/products/c1-thumb.webp', $byId[$cake]['thumb']);
        $this->assertStringContainsString('"price":1200.5,"sale_price":999,"active":true,"featured":true,"ready":true,"thumb":"/uploads/products/r2-thumb.webp"}', $this->body($res));
        $this->assertStringContainsString('"price":0,"sale_price":null,"active":false', $this->body($res));

        $ids = fn (string $query): array => array_column($this->json($this->getJson('/api/admin/products?' . $query), 'products'), 'id');
        $this->assertSame([$pink, $red, $garland], $ids('q=rose'));
        $this->assertSame([$pink, $red, $garland], $ids('q=%20ROSE%20'));
        $this->assertSame([$cake], $ids('cat=cakes'));
        $this->assertSame([], $ids('cat=nothing'));
        $this->assertSame([$lily, $garland], $ids('unpriced=1'));
        $this->assertSame([$lily, $garland], $ids('unpriced=true'));
        $this->assertSame([$red, $garland], $ids('featured=1'));
        $this->assertSame([$pink, $lily, $red, $garland, $cake], $ids('featured=0'));
        $this->assertSame([$garland], $ids('unpriced=1&featured=1'));
        $this->assertSame([$pink, $red], $ids('q=ro&cat=flower-bouquets'));
        $this->assertSame([$pink, $lily, $red, $garland, $cake], $ids('q=&cat=&unpriced=&featured='));
        // Like Laravel's where('p.name', 'like', "%q%"), % and _ in the search are wildcards.
        $this->assertSame([$pink, $lily, $red, $garland, $cake], $ids('q=%25'));
        $this->assertSame([$lily], $ids('q=e_L'));
    }

    // ------------------------------------------------------------------------------------------------------
    // Adding, showing and editing products
    // ------------------------------------------------------------------------------------------------------

    public function test_product_with_photo_is_saved_as_webp_and_shown_on_the_site(): void
    {
        $res = $this->postMultipart('/api/admin/products', ['name' => 'Rose Box', 'category_id' => (string) $this->cat, 'price' => '1200', 'is_active' => '1'], ['images' => [$this->photo()]]);
        $this->assertStatusCode($res, 201);
        $this->assertSame(['message', 'warnings', 'product'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'message', 'Product added.');
        $this->assertJsonPath($res, 'warnings', []);

        $p  = $this->json($res, 'product');
        $id = (int) db_connect()->table('products')->get()->getRowArray()['id'];
        $this->assertSame(self::PRODUCT_KEYS, array_keys($p));
        $this->assertSame([$id, 'Rose Box', 'rose-box', $this->cat, '', 1200, null, true, false], [
            $p['id'], $p['name'], $p['slug'], $p['category_id'], $p['description'], $p['price'], $p['sale_price'], $p['is_active'], $p['is_featured'],
        ]);
        $this->assertStringContainsString('"description":"","price":1200,"sale_price":null,"is_active":true,"is_featured":false,"images":[{"id":', $this->body($res));

        $this->assertCount(1, $p['images']);
        $img = $p['images'][0];
        $this->assertSame(['id', 'src', 'thumb'], array_keys($img));
        $this->assertIsInt($img['id']);
        $this->assertMatchesRegularExpression('~^/uploads/products/\d{8}-[0-9a-f]{10}\.webp$~', $img['src']);
        $this->assertSame(substr($img['src'], 0, -5) . '-thumb.webp', $img['thumb']);
        // Written to the test web root as real WebP files, never to backend/public/uploads.
        $this->assertFileExists($this->publicFile($img['src']));
        $this->assertFileExists($this->publicFile($img['thumb']));
        $this->assertSame(IMAGETYPE_WEBP, getimagesize($this->publicFile($img['src']))[2]);
        $this->assertFileDoesNotExist(FCPATH . ltrim($img['src'], '/'));

        $row = db_connect()->table('product_images')->get()->getRowArray();
        $this->assertSame([$id, ltrim($img['src'], '/'), ltrim($img['thumb'], '/'), 0], [(int) $row['product_id'], $row['path'], $row['thumb_path'], (int) $row['sort']]);
        $product = $this->productRow($id);
        $this->assertSame(['', '1200.00', null, 1, 0, 0], [$product['description'], $product['price'], $product['sale_price'], (int) $product['is_active'], (int) $product['is_featured'], (int) $product['sort']]);
        $this->assertNotEmpty($product['created_at']);

        $site = $this->getJson("/api/products?slug={$p['slug']}");
        $this->assertStatusCode($site, 200);
        $this->assertJsonPath($site, 'product.name', 'Rose Box');
        $this->assertJsonPath($site, 'product.pricing.final', 1200);
        $this->assertJsonPath($site, 'product.images.0.src', $img['src']);
    }

    public function test_a_file_that_is_not_a_photo_is_refused(): void
    {
        $fake = $this->fakeFile('shell.php.png', '<?php echo 1; ?>');
        $res  = $this->postMultipart('/api/admin/products', ['name' => 'X', 'category_id' => (string) $this->cat], ['images' => [$fake]]);
        $this->assertStatusCode($res, 201);
        $this->assertJsonPath($res, 'product.images', []);
        $this->assertJsonPath($res, 'warnings', ['shell.php.png: Not a valid image file.']);
        $this->assertSame([], $this->uploadsIn('products'));
        $this->assertSame(0, db_connect()->table('product_images')->countAllResults());

        // With a good photo alongside: only the bad one is reported, and the good one is the main photo.
        $res = $this->postMultipart('/api/admin/products', ['name' => 'Y', 'category_id' => (string) $this->cat], ['images' => [$fake, $this->photo()]]);
        $this->assertStatusCode($res, 201);
        $this->assertJsonPath($res, 'warnings', ['shell.php.png: Not a valid image file.']);
        $this->assertJsonCount($res, 1, 'product.images');
        $this->assertSame([0], $this->sorts([$this->json($res, 'product.images.0.id')]));
    }

    public function test_product_form_validation(): void
    {
        // ShopTest::test_product_form_validation
        $this->assertRefused('/api/admin/products', ['name' => '', 'category_id' => $this->cakes], 'Please enter a product name.');
        $this->assertRefused('/api/admin/products', ['name' => 'X', 'category_id' => 999], 'Please choose a category.');
        $this->assertRefused('/api/admin/products', ['name' => 'X', 'category_id' => $this->cakes, 'price' => 500, 'sale_price' => 600], 'Sale price must be lower than the normal price (or leave it empty).');
        $res = $this->postJson('/api/admin/products', ['name' => 'Rose Box', 'category_id' => $this->cakes, 'price' => 500, 'is_active' => '1']);
        $this->assertStatusCode($res, 201);
        $this->assertJsonPath($res, 'product.slug', 'rose-box');
        $this->assertJsonPath($res, 'product.is_active', true);
        $this->assertJsonPath($res, 'warnings', []);

        // The checks come in the same order as Laravel's.
        $this->assertRefused('/api/admin/products', ['name' => '   ', 'category_id' => 999, 'price' => 1, 'sale_price' => 5], 'Please enter a product name.');
        $this->assertRefused('/api/admin/products', ['name' => 'Y', 'price' => 1, 'sale_price' => 5], 'Please choose a category.');
        $this->assertRefused('/api/admin/products', ['name' => 'Y', 'category_id' => 'abc'], 'Please choose a category.');
        $this->assertRefused('/api/admin/products', ['name' => 'Y', 'category_id' => $this->cakes, 'price' => '500', 'sale_price' => '500'], 'Sale price must be lower than the normal price (or leave it empty).');
        $this->assertSame(1, db_connect()->table('products')->countAllResults());

        // Prices accept commas; a sale price without a normal price is allowed; negatives become 0; long text is cut.
        $res = $this->postJson('/api/admin/products', [
            'name' => str_repeat('é', 170), 'category_id' => (string) $this->cakes, 'price' => '1,250.75', 'sale_price' => '999.5',
            'description' => str_repeat('d', 2100), 'is_featured' => 'true',
        ]);
        $this->assertStatusCode($res, 201);
        $p = $this->json($res, 'product');
        $this->assertSame([str_repeat('é', 160), 'item', 1250.75, 999.5, false, true, 2000], [$p['name'], $p['slug'], $p['price'], $p['sale_price'], $p['is_active'], $p['is_featured'], strlen($p['description'])]);

        $res = $this->postJson('/api/admin/products', ['name' => 'Free', 'category_id' => $this->cakes, 'price' => '', 'sale_price' => '-20']);
        $this->assertStatusCode($res, 201);
        $this->assertStringContainsString('"price":0,"sale_price":0,"is_active":false,"is_featured":false,"images":[]}', $this->body($res));
        $this->assertSame(['0.00', '0.00'], [$this->productRow($this->json($res, 'product.id'))['price'], $this->productRow($this->json($res, 'product.id'))['sale_price']]);
    }

    public function test_showing_and_editing_a_product(): void
    {
        $p = $this->addProduct();
        $this->makeProduct(['name' => 'Rose Box Deluxe', 'category_id' => $this->cat]); // takes the address rose-box-deluxe

        $res = $this->getJson("/api/admin/products/{$p['id']}");
        $this->assertStatusCode($res, 200);
        $this->assertSame(['product' => $p], $this->json($res));

        $res = $this->postMultipart("/api/admin/products/{$p['id']}", [
            'name' => '  Rose Box Deluxe  ', 'category_id' => (string) $this->cakes, 'description' => " Lovely\nroses ", 'price' => '1500',
            'sale_price' => '1200', 'is_active' => '0', 'is_featured' => '1',
        ], ['images' => [$this->photo(), $this->fakeFile('notes.png', 'hello')]]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message', 'warnings', 'product'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'message', 'Product updated.');
        $this->assertJsonPath($res, 'warnings', ['notes.png: Not a valid image file.']);
        $product = $this->json($res, 'product');
        $this->assertSame(self::PRODUCT_KEYS, array_keys($product));
        $this->assertSame([$p['id'], 'Rose Box Deluxe', 'rose-box-deluxe-2', $this->cakes, "Lovely\nroses", 1500, 1200, false, true], [
            $product['id'], $product['name'], $product['slug'], $product['category_id'], $product['description'], $product['price'],
            $product['sale_price'], $product['is_active'], $product['is_featured'],
        ]);
        // The new photo goes after the existing one.
        $this->assertCount(2, $product['images']);
        $this->assertSame($p['images'][0], $product['images'][0]);
        $this->assertSame([0, 1], $this->sorts(array_column($product['images'], 'id')));
        $this->assertSame(['product' => $product], $this->json($this->getJson("/api/admin/products/{$p['id']}")));

        // Saving again with the same name keeps the address; an empty sale price clears it.
        $res = $this->postJson("/api/admin/products/{$p['id']}", ['name' => 'Rose Box Deluxe', 'category_id' => $this->cakes, 'price' => '1500', 'sale_price' => '']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'product.slug', 'rose-box-deluxe-2');
        $this->assertJsonPath($res, 'product.sale_price', null);
        $this->assertJsonPath($res, 'product.is_featured', false);
        $this->assertJsonPath($res, 'warnings', []);

        // Same checks as when adding.
        $path = "/api/admin/products/{$p['id']}";
        $this->assertRefused($path, ['name' => '', 'category_id' => $this->cakes], 'Please enter a product name.');
        $this->assertRefused($path, ['name' => 'X', 'category_id' => 999], 'Please choose a category.');
        $this->assertRefused($path, ['name' => 'X', 'category_id' => $this->cakes, 'price' => 500, 'sale_price' => 600], 'Sale price must be lower than the normal price (or leave it empty).');
        $this->assertSame('Rose Box Deluxe', $this->productRow($p['id'])['name']);

        // A product that doesn't exist: 404 before the form is looked at.
        foreach ([$this->postJson('/api/admin/products/99999', ['name' => '']), $this->getJson('/api/admin/products/99999')] as $res) {
            $this->assertStatusCode($res, 404);
            $this->assertSame($this->error('Not found.'), $this->body($res));
        }
    }

    // ------------------------------------------------------------------------------------------------------
    // Photos
    // ------------------------------------------------------------------------------------------------------

    public function test_photos_of_one_product_cant_be_changed_through_another(): void
    {
        $a        = $this->addProduct(['name' => 'A']);
        $b        = $this->addProduct(['name' => 'B']);
        $imageOfB = $b['images'][0]['id'];

        foreach ([
            $this->deleteJson("/api/admin/products/{$a['id']}/images/{$imageOfB}"),
            $this->postJson("/api/admin/products/{$a['id']}/images/{$imageOfB}/main"),
            $this->deleteJson("/api/admin/products/{$a['id']}/images/99999"),
            $this->postJson("/api/admin/products/{$a['id']}/images/99999/main"),
            $this->deleteJson("/api/admin/products/99999/images/{$imageOfB}"),
            $this->postJson("/api/admin/products/99999/images/{$imageOfB}/main"),
        ] as $res) {
            $this->assertStatusCode($res, 404);
            $this->assertSame($this->error('Not found.'), $this->body($res));
        }
        $this->assertFileExists($this->publicFile($b['images'][0]['src']));
        $this->assertSame(2, db_connect()->table('product_images')->countAllResults());

        $res = $this->deleteJson("/api/admin/products/{$b['id']}/images/{$imageOfB}");
        $this->assertStatusCode($res, 200);
        $this->assertSame(['product'], array_keys($this->json($res)));
        $this->assertJsonCount($res, 0, 'product.images');
        $this->assertJsonPath($res, 'product.id', $b['id']);
        $this->assertFileDoesNotExist($this->publicFile($b['images'][0]['src']));
        $this->assertFileDoesNotExist($this->publicFile($b['images'][0]['thumb']));
        $this->assertFileExists($this->publicFile($a['images'][0]['src']));
    }

    public function test_making_a_photo_the_main_one(): void
    {
        $p         = $this->addProduct([], [$this->photo(), $this->photo(), $this->photo()]);
        [$a, $b, $c] = array_column($p['images'], 'id');
        $this->assertSame([0, 1, 2], $this->sorts([$a, $b, $c]));

        $res = $this->postJson("/api/admin/products/{$p['id']}/images/{$c}/main");
        $this->assertStatusCode($res, 200);
        $this->assertSame(['product'], array_keys($this->json($res)));
        $this->assertSame(self::PRODUCT_KEYS, array_keys($this->json($res, 'product')));
        $this->assertSame([$c, $a, $b], array_column($this->json($res, 'product.images'), 'id'));
        $this->assertSame([0, 1, 2], $this->sorts([$c, $a, $b]));

        // A photo added later goes last (highest sort + 1).
        $res = $this->postMultipart("/api/admin/products/{$p['id']}", $this->text(['name' => 'Rose Box', 'category_id' => $this->cat, 'price' => 1200]), ['images' => [$this->photo()]]);
        $ids = array_column($this->json($res, 'product.images'), 'id');
        $d   = $ids[3];
        $this->assertSame([$c, $a, $b, $d], $ids);
        $this->assertSame([3], $this->sorts([$d]));

        // Already the main photo: the order stays, and like Eloquent (which skips writing an unchanged sort 0)
        // the photo keeps its shifted sort.
        $res = $this->postJson("/api/admin/products/{$p['id']}/images/{$c}/main");
        $this->assertStatusCode($res, 200);
        $this->assertSame([$c, $a, $b, $d], array_column($this->json($res, 'product.images'), 'id'));
        $this->assertSame([1, 2, 3, 4], $this->sorts([$c, $a, $b, $d]));

        // Deleting one keeps the others in order.
        $res = $this->deleteJson("/api/admin/products/{$p['id']}/images/{$a}");
        $this->assertSame([$c, $b, $d], array_column($this->json($res, 'product.images'), 'id'));
    }

    // ------------------------------------------------------------------------------------------------------
    // Deleting products
    // ------------------------------------------------------------------------------------------------------

    public function test_deleting_a_product_deletes_its_photos(): void
    {
        $p = $this->addProduct([], [$this->photo(), $this->photo()]);
        db_connect()->table('orders')->insert([
            'product_id' => $p['id'], 'product_name' => 'Rose Box', 'price_at_order' => 1200, 'customer_name' => 'Asha',
            'customer_phone' => '919876543210', 'status' => 'new', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $res = $this->deleteJson("/api/admin/products/{$p['id']}");
        $this->assertStatusCode($res, 200);
        $this->assertSame('{"message":"Product deleted."}', $this->body($res));
        foreach ($p['images'] as $img) {
            $this->assertFileDoesNotExist($this->publicFile($img['src']));
            $this->assertFileDoesNotExist($this->publicFile($img['thumb']));
        }
        $this->assertSame([], $this->uploadsIn('products'));
        $this->assertSame(0, db_connect()->table('product_images')->countAllResults());
        $this->assertNull($this->productRow($p['id']));
        // Orders keep their details; only the link to the product goes.
        $this->assertNull(db_connect()->table('orders')->get()->getRowArray()['product_id']);
        $this->assertStatusCode($this->getJson("/api/products?slug={$p['slug']}"), 404);

        $res = $this->deleteJson("/api/admin/products/{$p['id']}");
        $this->assertStatusCode($res, 404);
        $this->assertSame($this->error('Not found.'), $this->body($res));
    }

    public function test_deleting_a_product_does_not_turn_its_offer_into_a_site_wide_discount(): void
    {
        // CatalogAdminTest
        $p     = $this->addProduct(['name' => 'On offer']);
        $other = $this->addProduct(['name' => 'Full price']);
        $res   = $this->postJson('/api/admin/offers', ['title' => 'Only this one', 'discount_pct' => 30, 'target' => "product:{$p['id']}", 'is_active' => 1,
            'starts_at' => $this->local(time() - 3600), 'ends_at' => $this->local(time() + 86400)]);
        $this->assertStatusCode($res, 201);
        $this->assertJsonPath($this->getJson("/api/products?slug={$p['slug']}"), 'product.pricing.final', 840);

        $res = $this->deleteJson("/api/admin/products/{$p['id']}");
        $this->assertStatusCode($res, 200);
        $this->assertSame('{"message":"Product deleted, with its 1 offer(s)."}', $this->body($res));
        $this->assertSame(0, db_connect()->table('offers')->countAllResults());
        $this->assertJsonPath($this->getJson("/api/products?slug={$other['slug']}"), 'product.pricing.final', 1200);
        $this->assertJsonCount($this->getJson('/api/site'), 0, 'offers');

        // Two offers (one with a banner photo) for one product go with it; a category offer stays.
        $q = $this->addProduct(['name' => 'Twice on offer']);
        $this->assertStatusCode($this->postMultipart('/api/admin/offers', $this->text($this->offerFields(['title' => 'First', 'target' => "product:{$q['id']}"])), ['image' => $this->photo()]), 201);
        $banner = db_connect()->table('offers')->get()->getRowArray()['image'];
        $this->assertFileExists($this->publicFile($banner));
        $this->assertStatusCode($this->postJson('/api/admin/offers', $this->offerFields(['title' => 'Second', 'target' => "product:{$q['id']}"])), 201);
        $this->assertStatusCode($this->postJson('/api/admin/offers', $this->offerFields(['title' => 'Bouquets', 'target' => "category:{$this->cat}"])), 201);

        $res = $this->deleteJson("/api/admin/products/{$q['id']}");
        $this->assertSame('{"message":"Product deleted, with its 2 offer(s)."}', $this->body($res));
        $this->assertFileDoesNotExist($this->publicFile($banner));
        $this->assertSame(['Bouquets'], array_column(db_connect()->table('offers')->get()->getResultArray(), 'title'));
    }

    // ------------------------------------------------------------------------------------------------------
    // Bulk edit
    // ------------------------------------------------------------------------------------------------------

    public function test_bulk_edit_ignores_products_that_dont_exist(): void
    {
        // CatalogAdminTest
        $p   = $this->makeProduct(['category_id' => $this->cat, 'name' => 'Lily', 'slug' => 'lily', 'price' => 500]);
        $res = $this->postJson('/api/admin/products/bulk', ['rows' => [
            $p    => ['name' => 'Lily Deluxe', 'price' => '650', 'active' => 1],
            99999 => ['name' => 'Ghost', 'price' => '1'],
        ]]);
        $this->assertStatusCode($res, 200);
        $this->assertSame('{"message":"Changes saved."}', $this->body($res));
        $row = $this->productRow($p);
        $this->assertSame(['Lily Deluxe', 'lily-deluxe', '650.00', null, 1, 0], [$row['name'], $row['slug'], $row['price'], $row['sale_price'], (int) $row['is_active'], (int) $row['is_featured']]);
        $this->assertSame(1, db_connect()->table('products')->countAllResults());
    }

    public function test_bulk_edit_saves_prices_and_ready_to_go(): void
    {
        // ShopTest
        $a = $this->makeProduct(['name' => 'Cake A']);
        $b = $this->makeProduct(['name' => 'Cake B']);

        $res = $this->postJson('/api/admin/products/bulk', ['rows' => [
            $a => ['name' => 'Cake A+', 'price' => '1200', 'sale' => '999', 'active' => true, 'featured' => true, 'ready' => true],
            $b => ['name' => 'Cake B', 'price' => '0', 'sale' => '', 'active' => false, 'featured' => false, 'ready' => false],
        ]]);
        $this->assertStatusCode($res, 200);

        $rowA = (new ProductModel())->find($a);
        $this->assertSame(['Cake A+', 'cake-a', 1200.0, 999.0, true], [$rowA['name'], $rowA['slug'], $rowA['price'], $rowA['sale_price'], $rowA['is_featured']]);
        $this->assertFalse((new ProductModel())->find($b)['is_active']);
        $site = $this->getJson('/api/site');
        $this->assertJsonPath($site, 'ready.0.id', $a);
        $this->assertJsonCount($site, 1, 'ready');
        $this->assertSame((string) $a, $this->setting('ready_ids'));
    }

    public function test_bulk_edit_details(): void
    {
        $bouquets = [];
        for ($i = 1; $i <= 6; $i++) {
            $bouquets[] = $this->makeProduct(['category_id' => $this->cat, 'name' => "Bouquet {$i}", 'sort' => $i]);
        }
        $custom = $this->makeProduct(['name' => 'Custom', 'slug' => 'my-custom-address']);

        // Never chosen: the first five live bouquets are "Ready to go".
        $ready = static fn (array $rows): array => array_column(array_filter($rows, static fn (array $r): bool => $r['ready']), 'id');
        $this->assertEqualsCanonicalizing(array_slice($bouquets, 0, 5), $ready($this->json($this->getJson('/api/admin/products'), 'products')));
        $this->assertNull($this->setting('ready_ids'));

        // A row sent un-ticked leaves; ticked rows join at the end in the order sent; rows not sent keep their place.
        $res = $this->postJson('/api/admin/products/bulk', ['rows' => [
            $bouquets[1] => ['name' => 'Bouquet 2', 'price' => '100', 'active' => true, 'ready' => '0'],
            $custom      => ['name' => 'Custom', 'price' => '-50', 'sale' => '  ', 'active' => '1', 'featured' => 'yes', 'ready' => 1],
            $bouquets[5] => ['name' => '', 'price' => 'abc', 'sale' => '20', 'active' => 0, 'ready' => true],
            77777        => ['ready' => true],
        ]]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(implode(',', [$bouquets[0], $bouquets[2], $bouquets[3], $bouquets[4], $custom, $bouquets[5], 77777]), $this->setting('ready_ids'));

        $fields = function (int $id): array {
            $r = $this->productRow($id);

            return [$r['name'], $r['slug'], $r['price'], $r['sale_price'], (int) $r['is_active'], (int) $r['is_featured']];
        };
        // Same name: the address stays (even a hand-made one). Empty name: "Untitled", with a new address.
        $this->assertSame(['Custom', 'my-custom-address', '0.00', null, 1, 1], $fields($custom));
        $this->assertSame(['Untitled', 'untitled', '0.00', '20.00', 0, 0], $fields($bouquets[5]));
        $this->assertSame(['Bouquet 2', 'bouquet-2', '100.00', null, 1, 0], $fields($bouquets[1]));
        $this->assertSame(['Bouquet 1', 'bouquet-1', '1000.00', null, 1, 0], $fields($bouquets[0]));

        $rows = $this->json($this->getJson('/api/admin/products'), 'products');
        $this->assertEqualsCanonicalizing([$bouquets[0], $bouquets[2], $bouquets[3], $bouquets[4], $bouquets[5], $custom], $ready($rows));
        // The home page shows the live ones, in the order they were ticked.
        $this->assertSame([$bouquets[0], $bouquets[2], $bouquets[3], $bouquets[4], $custom], array_column($this->json($this->getJson('/api/site'), 'ready'), 'id'));

        // A new name that another product already uses gets its own address; long names are cut to 160 characters.
        $res = $this->postJson('/api/admin/products/bulk', ['rows' => [
            $bouquets[0] => ['name' => 'Bouquet 3', 'price' => '1000', 'active' => true, 'ready' => true],
            $bouquets[2] => ['name' => str_repeat('ü', 170), 'price' => '1000', 'active' => true, 'ready' => true],
            $bouquets[3] => ['name' => '0', 'price' => '1000', 'active' => true, 'ready' => true],
        ]]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['Bouquet 3', 'bouquet-3-2'], array_slice($fields($bouquets[0]), 0, 2));
        $this->assertSame([str_repeat('ü', 160), 'item'], array_slice($fields($bouquets[2]), 0, 2));
        $this->assertSame(['Untitled', 'untitled-2'], array_slice($fields($bouquets[3]), 0, 2));
        $this->assertSame(implode(',', [$bouquets[4], $custom, $bouquets[5], 77777, $bouquets[0], $bouquets[2], $bouquets[3]]), $this->setting('ready_ids'));

        // Nothing sent: nothing changes.
        $this->assertSame('{"message":"Changes saved."}', $this->body($this->postJson('/api/admin/products/bulk', [])));
        $this->assertSame(implode(',', [$bouquets[4], $custom, $bouquets[5], 77777, $bouquets[0], $bouquets[2], $bouquets[3]]), $this->setting('ready_ids'));

        // Unticking every one leaves "Ready to go" empty (not back to the default bouquets).
        $rows = [];
        foreach ([$bouquets[4], $custom, $bouquets[5], 77777, $bouquets[0], $bouquets[2], $bouquets[3]] as $id) {
            $rows[$id] = ['name' => $id === 77777 ? 'Ghost' : $this->productRow($id)['name'], 'price' => '1', 'active' => true, 'ready' => false];
        }
        $this->assertStatusCode($this->postJson('/api/admin/products/bulk', ['rows' => $rows]), 200);
        $this->assertSame('', $this->setting('ready_ids'));
        $this->assertSame([], $ready($this->json($this->getJson('/api/admin/products'), 'products')));
        $this->assertJsonCount($this->getJson('/api/site'), 0, 'ready');
    }

    public function test_bulk_edit_is_all_or_nothing(): void
    {
        $a = $this->makeProduct(['name' => 'Cake A']);
        $b = $this->makeProduct(['name' => 'Cake B']);

        // The second row can't be read (a list where a name belongs): the first row's changes and the
        // "Ready to go" choice are undone too, as in Laravel's DB::transaction().
        $res = $this->postJson('/api/admin/products/bulk', ['rows' => [
            $a => ['name' => 'Cake A+', 'price' => '5', 'active' => true, 'ready' => true],
            $b => ['name' => ['nope'], 'price' => '5', 'active' => true],
        ]]);
        $this->assertStatusCode($res, 500);
        $this->assertSame(['error'], array_keys($this->json($res)));
        $this->assertSame(['Cake A', '1000.00'], [$this->productRow($a)['name'], $this->productRow($a)['price']]);
        $this->assertNull($this->setting('ready_ids'));
        $this->assertSame(0, db_connect()->transDepth);

        // The connection is fine afterwards.
        $this->assertStatusCode($this->postJson('/api/admin/products/bulk', ['rows' => [$a => ['name' => 'Cake A+', 'price' => '5', 'active' => true]]]), 200);
        $this->assertSame('Cake A+', $this->productRow($a)['name']);
    }

    // ------------------------------------------------------------------------------------------------------
    // Offers
    // ------------------------------------------------------------------------------------------------------

    public function test_offer_rules(): void
    {
        // CatalogAdminTest
        $p    = $this->addProduct();
        $base = ['title' => 'Diwali', 'target' => "product:{$p['id']}", 'is_active' => 1, 'starts_at' => $this->local(time() - 3600), 'ends_at' => $this->local(time() + 86400)];

        $this->assertRefused('/api/admin/offers', ['title' => ''] + $base, 'Please give the offer a title.');
        $this->assertRefused('/api/admin/offers', ['ends_at' => $this->local(time() - 86400)] + $base, 'The end time must be after the start time.');
        // Discount is kept between 0 and 90 %, whatever is sent.
        $res = $this->postJson('/api/admin/offers', ['discount_pct' => 500] + $base);
        $this->assertStatusCode($res, 201);
        $this->assertSame('{"message":"Offer published.","warnings":[]}', $this->body($res));
        $this->assertSame(90, (new OfferModel())->orderBy('id', 'DESC')->first()['discount_pct']);
        // A product or category that doesn't exist is refused, not turned into a site-wide discount.
        $this->assertRefused('/api/admin/offers', ['discount_pct' => 10, 'target' => 'product:99999'] + $base, self::GONE);
        $this->assertRefused('/api/admin/offers', ['discount_pct' => 10, 'target' => 'category:99999'] + $base, self::GONE);
        $this->assertRefused('/api/admin/offers', ['discount_pct' => 10, 'target' => 'nonsense'] + $base, 'Please choose what the offer is for.');
        $this->assertSame(1, db_connect()->table('offers')->countAllResults());

        $this->assertJsonPath($this->getJson("/api/products?slug={$p['slug']}"), 'product.pricing.final', 120);
    }

    public function test_offer_checks_come_in_laravels_order_and_fill_in_defaults(): void
    {
        $past = $this->local(time() - 86400);
        $this->assertRefused('/api/admin/offers', $this->offerFields(['title' => '', 'target' => 'product:99999', 'ends_at' => $past]), 'Please give the offer a title.');
        $this->assertRefused('/api/admin/offers', $this->offerFields(['title' => '   ', 'target' => 'nonsense']), 'Please give the offer a title.');
        $this->assertRefused('/api/admin/offers', $this->offerFields(['target' => 'category:99999', 'ends_at' => $past]), self::GONE);
        $this->assertRefused('/api/admin/offers', $this->offerFields(['target' => 'product', 'ends_at' => $past]), self::GONE);
        $this->assertRefused('/api/admin/offers', $this->offerFields(['target' => 'nonsense', 'ends_at' => $past]), 'Please choose what the offer is for.');
        $this->assertRefused('/api/admin/offers', $this->offerFields(['target' => '']), 'Please choose what the offer is for.');
        $at = $this->local(time() + 3600);
        $this->assertRefused('/api/admin/offers', $this->offerFields(['starts_at' => $at, 'ends_at' => $at]), 'The end time must be after the start time.');
        $this->assertSame(0, db_connect()->table('offers')->countAllResults());

        // No target: everything. No (or unreadable) start: now. Negative discounts: 0. Text is trimmed.
        $before = time();
        $end    = $before + 2 * 86400;
        $res    = $this->postJson('/api/admin/offers', ['title' => '  Free delivery  ', 'subtitle' => '', 'discount_pct' => '-5', 'starts_at' => 'whenever', 'ends_at' => $this->local($end)]);
        $this->assertStatusCode($res, 201);
        $row = $this->offerRow(1);
        $this->assertSame(['Free delivery', '', 0, null, null, 0, null], [$row['title'], $row['subtitle'], (int) $row['discount_pct'], $row['product_id'], $row['category_id'], (int) $row['is_active'], $row['image']]);
        $this->assertEqualsWithDelta($before, strtotime($row['starts_at']), 5);
        $this->assertSame(date('Y-m-d H:i:00', $end), $row['ends_at']);
        $this->assertNotEmpty($row['created_at']);

        // No end: today 23:59. A category; whole percents only; "true" switches it on.
        $start = time() - 3600;
        $res   = $this->postJson('/api/admin/offers', ['title' => 'Cakes today', 'target' => "category:{$this->cakes}", 'discount_pct' => '12.7', 'is_active' => 'true', 'starts_at' => $this->local($start)]);
        $this->assertStatusCode($res, 201);
        $row = $this->offerRow(2);
        $this->assertSame([null, $this->cakes, 12, 1], [$row['product_id'], (int) $row['category_id'], (int) $row['discount_pct'], (int) $row['is_active']]);
        $this->assertSame(date('Y-m-d') . ' 23:59:00', $row['ends_at']);
        $this->assertSame(date('Y-m-d H:i:00', $start), $row['starts_at']);

        // A hidden product can still have an offer (it only has to exist); "product:<id>:x" reads the id.
        $hidden = $this->makeProduct(['name' => 'Hidden', 'is_active' => false]);
        $this->assertStatusCode($this->postJson('/api/admin/offers', $this->offerFields(['target' => "product:{$hidden}:x"])), 201);
        $this->assertSame($hidden, (int) $this->offerRow(3)['product_id']);
    }

    public function test_offer_list_with_what_each_one_applies_to(): void
    {
        $rose   = $this->makeProduct(['name' => 'Rose Box', 'category_id' => $this->cat]);
        $this->makeProduct(['name' => 'Hidden Cake', 'is_active' => false]);
        $apple  = $this->makeProduct(['name' => 'Apple Cake']);
        $model  = new OfferModel();
        $now    = time();
        $at     = static fn (int $t): string => date('Y-m-d H:i:s', $t);
        $live   = (int) $model->insert(['title' => 'Rose week', 'subtitle' => 'Flat 20%', 'discount_pct' => 20, 'product_id' => $rose, 'starts_at' => $at($now - 3600), 'ends_at' => $at($now + 86400), 'is_active' => true]);
        $coming = (int) $model->insert(['title' => 'Cake fest', 'discount_pct' => 10, 'category_id' => $this->cakes, 'starts_at' => $at($now + 86400), 'ends_at' => $at($now + 3 * 86400), 'is_active' => true, 'image' => 'uploads/offers/x.webp']);
        $ended  = (int) $model->insert(['title' => 'Old', 'discount_pct' => 5, 'starts_at' => $at($now - 3 * 86400), 'ends_at' => $at($now - 86400), 'is_active' => true]);
        $paused = (int) $model->insert(['title' => 'Paused', 'discount_pct' => 0, 'starts_at' => $at($now - 3600), 'ends_at' => $at($now + 2 * 86400), 'is_active' => false]);

        $res = $this->getJson('/api/admin/offers');
        $this->assertStatusCode($res, 200);
        $data = $this->json($res);
        $this->assertSame(['offers', 'categories', 'products'], array_keys($data));
        // Latest end first.
        $this->assertSame([$coming, $paused, $live, $ended], array_column($data['offers'], 'id'));
        foreach ($data['offers'] as $o) {
            $this->assertSame(self::OFFER_KEYS, array_keys($o));
        }
        $this->assertSame([
            'id' => $live, 'title' => 'Rose week', 'subtitle' => 'Flat 20%', 'discount_pct' => 20, 'target' => "product:{$rose}", 'applies_to' => 'Rose Box',
            'starts_at' => date('Y-m-d\TH:i', $now - 3600), 'ends_at' => date('Y-m-d\TH:i', $now + 86400), 'is_active' => true, 'image' => null, 'state' => 'live',
        ], $data['offers'][2]);
        $this->assertSame([
            'id' => $coming, 'title' => 'Cake fest', 'subtitle' => '', 'discount_pct' => 10, 'target' => "category:{$this->cakes}", 'applies_to' => 'Cakes',
            'starts_at' => date('Y-m-d\TH:i', $now + 86400), 'ends_at' => date('Y-m-d\TH:i', $now + 3 * 86400), 'is_active' => true, 'image' => '/uploads/offers/x.webp', 'state' => 'scheduled',
        ], $data['offers'][0]);
        $this->assertSame(['all', 'Everything', 'ended'], [$data['offers'][3]['target'], $data['offers'][3]['applies_to'], $data['offers'][3]['state']]);
        $this->assertSame([false, 'paused', 0], [$data['offers'][1]['is_active'], $data['offers'][1]['state'], $data['offers'][1]['discount_pct']]);
        $this->assertStringContainsString('{"id":' . $live . ',"title":"Rose week","subtitle":"Flat 20%","discount_pct":20,"target":"product:' . $rose . '","applies_to":"Rose Box","starts_at":"', $this->body($res));

        $categories = array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'name' => $c['name']], db_connect()->table('categories')->orderBy('sort')->orderBy('id')->get()->getResultArray());
        $this->assertSame($categories, $data['categories']);
        // Only live products, by name.
        $this->assertSame([['id' => $apple, 'name' => 'Apple Cake'], ['id' => $rose, 'name' => 'Rose Box']], $data['products']);

        // Nothing yet.
        db_connect()->table('offers')->truncate();
        $this->assertSame([], $this->json($this->getJson('/api/admin/offers'), 'offers'));
    }

    public function test_offer_banner_photo_editing_pausing_and_deleting(): void
    {
        $res = $this->postMultipart('/api/admin/offers', $this->text($this->offerFields(['title' => 'Valentine’s Rose Week', 'discount_pct' => 20])), ['image' => $this->photo()]);
        $this->assertStatusCode($res, 201);
        $this->assertSame('{"message":"Offer published.","warnings":[]}', $this->body($res));
        $offer = $this->offerRow(1);
        $this->assertMatchesRegularExpression('~^uploads/offers/\d{8}-[0-9a-f]{10}\.webp$~', $offer['image']);
        $this->assertFileExists($this->publicFile($offer['image']));
        // Banners keep no thumbnail.
        $this->assertSame([basename($offer['image'])], $this->uploadsIn('offers'));
        $list = $this->getJson('/api/admin/offers');
        $this->assertJsonPath($list, 'offers.0.image', '/' . $offer['image']);
        $this->assertJsonPath($list, 'offers.0.title', 'Valentine’s Rose Week');
        $this->assertStringContainsString('"title":"Valentine’s Rose Week"', $this->body($list));

        // A new photo replaces the old one (whose file is removed).
        $res = $this->postMultipart('/api/admin/offers/1', $this->text($this->offerFields(['title' => 'Rose Week', 'target' => "category:{$this->cat}"])), ['image' => $this->photo()]);
        $this->assertStatusCode($res, 200);
        $this->assertSame('{"message":"Offer updated.","warnings":[]}', $this->body($res));
        $updated = $this->offerRow(1);
        $this->assertNotSame($offer['image'], $updated['image']);
        $this->assertFileDoesNotExist($this->publicFile($offer['image']));
        $this->assertSame([basename($updated['image'])], $this->uploadsIn('offers'));
        $this->assertSame(['Rose Week', $this->cat, null], [$updated['title'], (int) $updated['category_id'], $updated['product_id']]);

        // Something that isn't a photo is reported and the current photo stays; no photo keeps it too.
        $res = $this->postMultipart('/api/admin/offers/1', $this->text($this->offerFields(['title' => 'Rose Week 2'])), ['image' => $this->fakeFile('banner.png', 'not an image')]);
        $this->assertStatusCode($res, 200);
        $this->assertSame('{"message":"Offer updated.","warnings":["Image: Not a valid image file."]}', $this->body($res));
        $this->assertSame(['Rose Week 2', $updated['image']], [$this->offerRow(1)['title'], $this->offerRow(1)['image']]);
        $res = $this->postJson('/api/admin/offers/1', $this->offerFields(['title' => 'Rose Week 3', 'is_active' => 0]));
        $this->assertSame('{"message":"Offer updated.","warnings":[]}', $this->body($res));
        $this->assertSame(['Rose Week 3', $updated['image'], 0, null], [$this->offerRow(1)['title'], $this->offerRow(1)['image'], (int) $this->offerRow(1)['is_active'], $this->offerRow(1)['category_id']]);
        $this->assertFileExists($this->publicFile($updated['image']));

        // A new offer whose photo can't be used is still published, without a photo.
        $res = $this->postMultipart('/api/admin/offers', $this->text($this->offerFields(['title' => 'No photo', 'ends_at' => $this->local(time() + 7200)])), ['image' => $this->fakeFile('x.gif', 'GIF89a')]);
        $this->assertStatusCode($res, 201);
        $this->assertSame('{"message":"Offer published.","warnings":["Image: Not a valid image file."]}', $this->body($res));
        $this->assertNull($this->offerRow(2)['image']);

        // Pause and resume.
        $this->assertSame('{"message":"Offer resumed."}', $this->body($this->postJson('/api/admin/offers/1/toggle')));
        $this->assertSame(1, (int) $this->offerRow(1)['is_active']);
        $this->assertJsonPath($this->getJson('/api/admin/offers'), 'offers.0.state', 'live');
        $this->assertSame('{"message":"Offer paused."}', $this->body($this->postJson('/api/admin/offers/1/toggle')));
        $this->assertSame(0, (int) $this->offerRow(1)['is_active']);
        $list = $this->getJson('/api/admin/offers');
        $this->assertSame([1, 2], array_column($this->json($list, 'offers'), 'id'));
        $this->assertSame(['paused', 'live'], array_column($this->json($list, 'offers'), 'state'));

        // Delete: the photo goes too.
        $res = $this->deleteJson('/api/admin/offers/1');
        $this->assertStatusCode($res, 200);
        $this->assertSame('{"message":"Offer deleted."}', $this->body($res));
        $this->assertFileDoesNotExist($this->publicFile($updated['image']));
        $this->assertNull($this->offerRow(1));
        $this->assertSame([], $this->uploadsIn('offers'));

        // An offer that doesn't exist: 404 before the form is looked at.
        foreach ([$this->postJson('/api/admin/offers/1', ['title' => '']), $this->postJson('/api/admin/offers/1/toggle'), $this->deleteJson('/api/admin/offers/1')] as $res) {
            $this->assertStatusCode($res, 404);
            $this->assertSame($this->error('Not found.'), $this->body($res));
        }
    }
}
