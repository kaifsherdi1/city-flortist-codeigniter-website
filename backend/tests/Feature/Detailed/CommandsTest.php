<?php

namespace Tests\Feature\Detailed;

use App\Libraries\Catalog;
use App\Libraries\Crypto;
use App\Libraries\Installer;
use App\Libraries\Passwords;
use App\Libraries\Settings;
use App\Libraries\Text;
use App\Models\AdminModel;
use App\Models\CategoryModel;
use App\Models\OfferModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\ReviewModel;
use CodeIgniter\Test\StreamFilterTrait;
use CodeIgniter\Test\TestResponse;
use Config\Florist;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\After;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\FeatureTestCase;

/**
 * The maintenance commands (php spark florist:install, florist:demo-data, florist:export-static and
 * florist:admin-recover), checked against the Laravel version's Artisan commands: what they print, their exit codes,
 * and what they change in the database and on disk.
 *
 * florist:export-static only ever writes into a temporary frontend folder here, never frontend-user.
 *
 * @internal
 */
final class CommandsTest extends FeatureTestCase
{
    use StreamFilterTrait;

    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private const RECOVER_HINT = 'Give it to the owner privately; they should change it in Settings → Change password straight away.';

    /** The temporary frontend-user folder for this test (Config\Florist::$frontendPath). */
    private string $frontend = '';

    /** @var list<string> Temporary folders, removed after each test. */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->frontend                      = $this->tempDir('cityflorist-frontend-');
        config(Florist::class)->frontendPath = $this->frontend;
    }

    #[After]
    protected function removeTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    // ------------------------------------------------------------------------------------------------------
    // florist:install
    // ------------------------------------------------------------------------------------------------------

    public function test_install_without_the_starter_photos(): void
    {
        $this->assertFalse(Installer::installed());

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:install', ['no-seed' => null]));
        $this->assertSame([
            '✓ Tables created',
            '✓ Admin login created',
            '✓ Categories created',
            'Log in at /admin with username "admin" and the password from .env, then change it in Settings.',
        ], $this->printed());

        $config = config(Florist::class);
        $admin  = (new AdminModel())->first();
        $this->assertTrue(Installer::installed());
        $this->assertSame($config->adminUsername, $admin['username']);
        $this->assertTrue(Passwords::check($config->adminPassword, $admin['password_hash']));
        $this->assertSame(strtolower(trim($config->adminEmail)), $admin['email']);
        $this->assertSame(Text::normalizePhone($config->adminPhone), $admin['phone']);
        $this->assertSame(8, db_connect()->table('categories')->countAllResults());
        $this->assertSame(0, db_connect()->table('products')->countAllResults());
        $this->assertSame('2', Settings::get('catalog_rev'));
        $this->assertSame('City Florist', Settings::get('shop_name'));
        $this->assertDirectoryDoesNotExist($this->publicRoot . 'uploads');
    }

    public function test_install_on_a_database_without_any_tables_runs_every_migration(): void
    {
        $db = db_connect();
        $this->dropAllTables();

        try {
            $this->assertFalse(Installer::installed());
            config(Florist::class)->adminUsername = 'owner';

            // The way `php spark florist:install --no-seed` passes its option.
            $this->resetStreamFilterBuffer();
            command('florist:install --no-seed');
            $this->assertSame([
                '✓ Tables created',
                '✓ Admin login created',
                '✓ Categories created',
                'Log in at /admin with username "owner" and the password from .env, then change it in Settings.',
            ], $this->printed());

            foreach (['admins', 'categories', 'products', 'product_images', 'offers', 'orders', 'settings', 'reviews', 'customers', 'password_reset_tokens'] as $table) {
                $this->assertTrue($db->tableExists($table, false), "table {$table}");
            }
            $migrations = glob(APPPATH . 'Database/Migrations/*.php');
            $this->assertSame(count($migrations), $db->table('ci_migrations')->countAllResults());
            $this->assertSame('owner', (new AdminModel())->first()['username']);
            $this->assertSame(0, $db->table('products')->countAllResults());
        } finally {
            Installer::migrate(); // whatever happened, the next tests find every table
        }
    }

    public function test_install_on_an_installed_site_only_runs_new_migrations(): void
    {
        $this->install();
        $db = db_connect();

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:install'));
        $this->assertSame(['Already installed. Running any new migrations instead.', 'Nothing to migrate.'], $this->printed());
        $this->assertSame(1, $db->table('admins')->countAllResults());
        $this->assertSame(0, $db->table('products')->countAllResults(), 'no starter photos on an installed site');

        // A new version ships a migration (here: the last one, taken out of the history), and --no-seed changes nothing.
        $count = $db->table('ci_migrations')->countAllResults();
        $last  = $db->table('ci_migrations')->orderBy('id', 'DESC')->get(1)->getRowArray();
        $db->table('ci_migrations')->where('id', $last['id'])->delete();

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:install', ['no-seed' => null]));
        $this->assertSame([
            'Already installed. Running any new migrations instead.',
            'Running migrations.',
            '  2026-11-17-000000_CustomerAccountsAndStaff DONE',
        ], $this->printed());
        $this->assertSame($count, $db->table('ci_migrations')->countAllResults());
        $this->assertSame(1, $db->table('admins')->countAllResults());
    }

    public function test_install_with_the_starter_photos(): void
    {
        $manifest = json_decode((string) file_get_contents(rtrim(config(Florist::class)->seedPath, '/\\') . '/manifest.json'), true);
        $this->assertCount(109, $manifest);

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:install'));
        $this->assertSame([
            '✓ Tables created',
            '✓ Admin login created',
            '✓ Categories created',
            '✓ 109 starter products imported',
            'Log in at /admin with username "admin" and the password from .env, then change it in Settings.',
        ], $this->printed());

        $db = db_connect();
        $this->assertSame(109, $db->table('products')->countAllResults());
        $this->assertSame(109, $db->table('product_images')->countAllResults());
        $this->assertSame(10, $db->table('products')->where('is_featured', 1)->countAllResults());
        $this->assertSame(0, $db->table('products')->where('price >', 0)->countAllResults(), 'starter products wait for a price');
        $this->assertCount(218, glob($this->publicRoot . 'uploads/products/*.webp'));
        $this->assertFileEquals(rtrim(config(Florist::class)->seedPath, '/\\') . '/flower-bouquets/01.webp', $this->publicFile('/uploads/products/flower-bouquets-01.webp'));
    }

    public function test_install_refuses_the_example_password_on_the_live_site(): void
    {
        $config                = config(Florist::class);
        $config->production    = true;
        $config->adminPassword = Installer::DEFAULT_PASSWORD;

        // Not caught by the command, as in Laravel: `php spark` prints the error and exits with 1.
        $thrown = null;

        try {
            $this->spark('florist:install', ['no-seed' => null]);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertStringStartsWith('Set a strong ', $thrown->getMessage());
        $this->assertSame([], $this->printed());
        $this->assertFalse(Installer::installed());
        $this->assertSame(0, db_connect()->table('admins')->countAllResults());
    }

    // ------------------------------------------------------------------------------------------------------
    // florist:demo-data
    // ------------------------------------------------------------------------------------------------------

    public function test_demo_data_imports_new_photos_and_prices_unpriced_products_once(): void
    {
        $this->install();
        $db = db_connect();
        // A category the site didn't have yet (deleted here) is created.
        $db->table('categories')->where('slug', 'cakes')->delete();
        config(Florist::class)->seedPath = $this->smallSeed();

        $cat = $this->categoryIds();
        $this->product('flower-bouquet-no-15', $cat['flower-bouquets']);
        $this->product('flower-bouquet-no-17', $cat['flower-bouquets']);
        $this->product('test-garland', $cat['garlands']);
        $this->product('test-loose', $cat['wholesale-flowers']);
        $this->product('owner-priced', $cat['flower-bouquets'], 1234.5, 999);
        $hampers = (new CategoryModel())->insert(['slug' => 'gift-hampers', 'name' => 'Gift Hampers', 'tagline' => null, 'sort' => 9]);
        $this->product('hamper-one', (int) $hampers);

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:demo-data'));
        $this->assertSame(['Added category Cakes', 'Imported 2 new products, priced 6 products.'], $this->printed());
        $this->assertSame(8 + 1, $db->table('categories')->countAllResults());
        $this->assertSame(6, (int) $db->table('categories')->where('slug', 'cakes')->get()->getRowArray()['sort']);

        // Prices from Laravel's formula (crc32 of the slug), checked against the snapshot Laravel exported.
        $prices = $this->prices();
        $this->assertSame([
            'flower-bouquet-no-15' => ['1499.00', '1149.00'],  // 20% off
            'flower-bouquet-no-17' => ['599.00', '499.00'],    // 15% off
            'test-garland'         => ['1999.00', '1749.00'],  // 10% off
            'test-loose'           => ['249.00', null],
            'owner-priced'         => ['1234.50', '999.00'],   // the owner's price is never touched
            'hamper-one'           => ['0.00', null],          // no demo prices for this category
            'flower-bouquet-no-01' => ['1499.00', '1299.00'],
            'flower-bouquet-no-02' => ['2199.00', null],
        ], $prices);

        // The two seed photos with a known category and a file became products (not featured: only the installer does that).
        $imported = $db->table('products')->whereIn('slug', ['flower-bouquet-no-01', 'flower-bouquet-no-02'])->orderBy('id')->get()->getResultArray();
        $this->assertSame(['Flower Bouquet No. 01', 'Flower Bouquet No. 02'], array_column($imported, 'name'));
        $this->assertSame(['Seed description one.', Catalog::DEFAULT_DESCRIPTION], array_column($imported, 'description'));
        $this->assertSame([0, 0], array_column($imported, 'is_featured'));
        $this->assertSame([0, 2], array_column($imported, 'sort'));
        $this->assertSame([1, 1], array_column($imported, 'is_active'));
        $this->assertFileExists($this->publicFile('/uploads/products/flower-bouquets-01.webp'));
        $this->assertFileExists($this->publicFile('/uploads/products/flower-bouquets-01-thumb.webp'));
        $this->assertFileDoesNotExist($this->publicFile('/uploads/products/balloons-02.webp'));
        $this->assertSame(2, $db->table('product_images')->countAllResults());

        // Safe to run again: nothing new, nothing re-priced.
        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:demo-data'));
        $this->assertSame(['Imported 0 new products, priced 0 products.'], $this->printed());
        $this->assertSame($prices, $this->prices());
        $this->assertSame(8, $db->table('products')->countAllResults());
    }

    public function test_demo_data_gives_the_starter_products_the_prices_laravel_gave_them(): void
    {
        $this->install();
        // The starter products as the installer makes them (without copying their photos again), all still at ₹0.
        $manifest = json_decode((string) file_get_contents(rtrim(config(Florist::class)->seedPath, '/\\') . '/manifest.json'), true);
        $cat      = $this->categoryIds();
        $products = new ProductModel();
        foreach ($manifest as $item) {
            $this->product($products->uniqueSlug($item['name']), $cat[$item['category']]);
        }
        config(Florist::class)->seedPath = $this->tempDir('cityflorist-noseed-'); // no manifest: nothing to import

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:demo-data'));
        $this->assertSame(['Imported 0 new products, priced 109 products.'], $this->printed());

        // What frontend-user/src/catalog/products.json (exported by the Laravel version after its demo-data) shows.
        $prices = $this->prices();
        $laravel = [
            'flower-bouquet-no-01'    => ['1499.00', '1299.00'],
            'flower-bouquet-no-04'    => ['1199.00', null],
            'flower-bouquet-no-20'    => ['599.00', '449.00'],
            'chocolate-bouquet-no-01' => ['2499.00', '1949.00'],
            'chocolate-bouquet-no-16' => ['2999.00', null],
            'car-decoration-no-02'    => ['2999.00', '2499.00'],
            'car-decoration-no-07'    => ['5999.00', null],
            'room-decoration-no-08'   => ['12999.00', null],
            'falling-petals-surprise' => ['9999.00', '8449.00'],
            'bridal-garland-no-04'    => ['3499.00', '2749.00'],
            'bridal-garland-no-10'    => ['2499.00', '1949.00'],
            'teddy-bear-no-02'        => ['999.00', '849.00'],
            'cake-no-01'              => ['549.00', null],
            'cake-no-02'              => ['1199.00', '999.00'],
        ];
        foreach ($laravel as $slug => $expected) {
            $this->assertSame($expected, $prices[$slug], $slug);
        }
        $this->assertSame([], array_filter($prices, static fn (array $p): bool => (float) $p[0] <= 0), 'every product priced');
        $sale = count(array_filter($prices, static fn (array $p): bool => $p[1] !== null));
        $this->assertGreaterThan(15, $sale, 'about 1 in 4 on sale');
        $this->assertLessThan(45, $sale);
    }

    // ------------------------------------------------------------------------------------------------------
    // florist:export-static
    // ------------------------------------------------------------------------------------------------------

    public function test_export_static_writes_the_snapshot_the_website_compares_with_the_api(): void
    {
        $this->install();
        $cat = $this->categoryIds();

        $roses = $this->product('red-roses', $cat['flower-bouquets'], 1499, 1299, true);
        $this->image($roses, 'uploads/products/red-roses.webp', 0);
        $this->image($roses, 'uploads/products/gone.webp', 1, false); // in the database, but the file is missing
        $marigold = $this->product('loose-marigold', $cat['wholesale-flowers']);
        $this->image($marigold, 'uploads/products/marigold.webp', 0);
        $hidden = $this->product('hidden-lily', $cat['flower-bouquets'], 999, null, false, false);
        $this->image($hidden, 'uploads/products/hidden-lily.webp', 0);
        (new CategoryModel())->update($cat['cakes'], ['cover_image' => 'uploads/categories/cakes.webp']);
        $this->file('uploads/categories/cakes.webp');
        (new OfferModel())->insert([
            'title' => 'Diwali offer', 'subtitle' => 'On every bouquet', 'discount_pct' => 10, 'product_id' => null,
            'category_id' => $cat['flower-bouquets'], 'image' => 'uploads/offers/diwali.webp',
            'starts_at' => date('Y-m-d H:i:s', time() - 86400), 'ends_at' => date('Y-m-d H:i:s', time() + 7 * 86400), 'is_active' => 1,
        ]);
        $this->file('uploads/offers/diwali.webp');
        (new ReviewModel())->insert(['name' => 'Priya Patil', 'rating' => 5, 'text' => 'Lovely flowers – on time ₹ well spent', 'phone' => null, 'verified' => 1, 'status' => 'approved']);

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:export-static'));
        $data   = $this->frontend . '/src/catalog';
        $photos = $this->frontend . '/public/catalog';
        // red-roses (+thumb), marigold (+thumb), the cakes cover and the offer picture.
        $this->assertSame([sprintf('Exported 2 products and 8 categories to %s, and 6 images to %s', $data, $photos)], $this->printed());

        $siteText     = (string) file_get_contents("{$data}/site.json");
        $productsText = (string) file_get_contents("{$data}/products.json");
        $site         = json_decode($siteText, true);
        $products     = json_decode($productsText, true);

        // Written by json_encode with JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, on one line.
        $this->assertSame(self::normalized($siteText), $siteText);
        $this->assertSame(self::normalized($productsText), $productsText);
        $this->assertStringContainsString('Open daily · 8:00 AM – 10:00 PM', $siteText);
        $this->assertStringNotContainsString('\/', $siteText);
        $this->assertStringNotContainsString('"/uploads/', $siteText . $productsText);

        $this->assertSame(['settings', 'categories', 'offers', 'featured', 'ready', 'wholesale', 'reviews'], array_keys($site));
        $this->assertSame(['products', 'categories'], array_keys($products));
        $this->assertSame($site['categories'], $products['categories']);
        $this->assertSame('/catalog/categories/cakes.webp', $site['categories'][6]['cover']);
        $this->assertSame('/catalog/products/red-roses.webp', $site['categories'][0]['cover']);
        $this->assertNull($site['categories'][1]['cover']);
        $this->assertSame('/catalog/offers/diwali.webp', $site['offers'][0]['image']);
        $this->assertSame([
            ['src' => '/catalog/products/red-roses.webp', 'thumb' => '/catalog/products/red-roses-thumb.webp'],
            ['src' => '/catalog/products/gone.webp', 'thumb' => '/catalog/products/gone-thumb.webp'],
        ], $site['featured'][0]['images']);
        $this->assertSame(['red-roses', 'loose-marigold'], array_column($products['products'], 'slug'));
        $this->assertSame('/catalog/products/marigold.webp', $site['wholesale'][0]['images'][0]['src']);

        // The website's sameAsSnapshot(): the live API, with /uploads/ read as /catalog/, is the same JSON.
        $this->assertSame(self::asSnapshot($this->getJson('/api/site')), self::normalized($siteText));
        $this->assertSame(self::asSnapshot($this->getJson('/api/products')), self::normalized($productsText));

        // The photos were copied; missing files and hidden products' photos were not.
        foreach (['products/red-roses.webp', 'products/red-roses-thumb.webp', 'products/marigold.webp', 'products/marigold-thumb.webp', 'categories/cakes.webp', 'offers/diwali.webp'] as $rel) {
            $this->assertFileEquals($this->publicFile('/uploads/' . $rel), "{$photos}/{$rel}");
        }
        $this->assertFileDoesNotExist("{$photos}/products/gone.webp");
        $this->assertFileDoesNotExist("{$photos}/products/hidden-lily.webp");
        $this->assertCount(6, $this->filesUnder($photos));

        // Running it again rewrites the same snapshot.
        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:export-static'));
        $this->assertSame([sprintf('Exported 2 products and 8 categories to %s, and 6 images to %s', $data, $photos)], $this->printed());
        $this->assertSame($siteText, file_get_contents("{$data}/site.json"));
        $this->assertSame($productsText, file_get_contents("{$data}/products.json"));
    }

    public function test_export_static_of_an_empty_shop_sees_changes_made_since_the_last_request(): void
    {
        $this->install();
        $this->getJson('/api/site'); // the running offers and settings are now cached in this process
        $this->assertSame('Fresh flowers. Made up today.', Settings::get('hero_title'));

        // Changed behind the caches' back, like another process would.
        db_connect()->query('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['hero_title', 'Fresh today']);
        (new OfferModel())->insert([
            'title' => 'Flash sale', 'subtitle' => null, 'discount_pct' => 5, 'product_id' => null, 'category_id' => null,
            'image' => null, 'starts_at' => date('Y-m-d H:i:s', time() - 60), 'ends_at' => date('Y-m-d H:i:s', time() + 3600), 'is_active' => 1,
        ]);
        $this->assertSame('Fresh flowers. Made up today.', Settings::get('hero_title'), 'still cached');

        // The frontend folder doesn't exist yet: the command makes src/catalog (and no photo folder without photos).
        $this->assertDirectoryDoesNotExist($this->frontend);

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:export-static'));
        $data   = $this->frontend . '/src/catalog';
        $photos = $this->frontend . '/public/catalog';
        $this->assertSame([sprintf('Exported 0 products and 8 categories to %s, and 0 images to %s', $data, $photos)], $this->printed());
        $this->assertDirectoryDoesNotExist($photos);

        $site = json_decode((string) file_get_contents("{$data}/site.json"), true);
        $this->assertSame('Fresh today', $site['settings']['hero_title']);
        $this->assertSame(['Flash sale'], array_column($site['offers'], 'title'));
        $this->assertNull($site['offers'][0]['image']);
        $this->assertSame('', $site['offers'][0]['subtitle']);
        $this->assertSame([], $site['featured']);
        $this->assertSame(['summary' => ['count' => 0, 'average' => 0], 'items' => []], $site['reviews']);
        $this->assertSame('{"products":[],"categories":' . json_encode($site['categories'], self::FLAGS) . '}', file_get_contents("{$data}/products.json"));
    }

    // ------------------------------------------------------------------------------------------------------
    // florist:admin-recover
    // ------------------------------------------------------------------------------------------------------

    public function test_admin_recover_turns_off_two_step_login(): void
    {
        $this->install();
        $admin = $this->withTwoFactor((new AdminModel())->first());
        $this->assertTrue(AdminModel::hasTwoFactor($admin));

        // A logged-in browser stays logged in: the password didn't change.
        $this->actingAsAdmin($admin);
        $this->assertNotSame(401, $this->getJson('/api/admin/dashboard')->response()->getStatusCode());

        // `php spark florist:admin-recover admin --two-factor-off`
        $this->resetStreamFilterBuffer();
        command('florist:admin-recover admin --two-factor-off');
        $this->assertSame(['Two-step login is off for admin. Ask the owner to turn it on again in Settings.'], $this->printed());

        $row = (new AdminModel())->find($admin['id']);
        $this->assertNull($row['two_factor_secret']);
        $this->assertNull($row['two_factor_recovery_codes']);
        $this->assertNull($row['two_factor_confirmed_at']);
        $this->assertNull($row['two_factor_last_step']);
        $this->assertFalse(AdminModel::hasTwoFactor($row));
        $this->assertSame($admin['password_hash'], $row['password_hash']);
        $this->assertSame($admin['remember_token'], $row['remember_token']);
        $this->assertNotSame(401, $this->getJson('/api/admin/dashboard')->response()->getStatusCode());

        // The username is "admin" when none is given, and it is matched like the login box does (any case).
        $this->withTwoFactor($row);
        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:admin-recover', ['two-factor-off' => null]));
        $this->assertSame(['Two-step login is off for admin. Ask the owner to turn it on again in Settings.'], $this->printed());
        $this->withTwoFactor($row);
        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:admin-recover', ['ADMIN', 'two-factor-off' => null]));
        $this->assertSame(['Two-step login is off for admin. Ask the owner to turn it on again in Settings.'], $this->printed());
        $this->assertFalse(AdminModel::hasTwoFactor((new AdminModel())->find($admin['id'])));
    }

    public function test_admin_recover_sets_a_temporary_password_and_signs_out_every_device(): void
    {
        $this->install();
        $admins = new AdminModel();
        $admin  = $admins->first();
        $admins->builder()->where('id', $admin['id'])->update(['remember_token' => 'old-token']);
        $this->actingAsAdmin($admin);
        $this->assertNotSame(401, $this->getJson('/api/admin/dashboard')->response()->getStatusCode());

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:admin-recover', ['admin', 'reset-password' => null]));
        $lines = $this->printed();
        $this->assertCount(2, $lines);
        $this->assertMatchesRegularExpression('/^Temporary password for admin: [A-Za-z0-9]{14}$/', $lines[0]);
        $this->assertSame(self::RECOVER_HINT, $lines[1]);
        $temp = substr($lines[0], strlen('Temporary password for admin: '));
        $this->assertMatchesRegularExpression('/[A-Za-z]/', $temp);
        $this->assertMatchesRegularExpression('/[0-9]/', $temp);
        $this->assertFalse(Installer::isStartingPassword($temp));

        $row = $admins->find($admin['id']);
        $this->assertTrue(Passwords::check($temp, $row['password_hash']));
        $this->assertFalse(Passwords::check(config(Florist::class)->adminPassword, $row['password_hash']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{60}$/', $row['remember_token']);
        $this->assertSame($admin['created_at'], $row['created_at']);

        // The browser that was logged in is signed out (Laravel's auth.session).
        $res = $this->getJson('/api/admin/dashboard');
        $this->assertStatusCode($res, 401);
        $this->assertJsonPath($res, 'error', 'Please log in again.');

        // A new password every time, with letters and digits only.
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            $this->spark('florist:admin-recover', ['reset-password' => null]);
            $temp = substr($this->printed()[0], strlen('Temporary password for admin: '));
            $this->assertMatchesRegularExpression('/^(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{14}$/', $temp);
            $seen[$temp] = true;
        }
        $this->assertCount(25, $seen);
    }

    public function test_admin_recover_both_options_and_the_username_after_an_option(): void
    {
        $this->install();
        $admins = new AdminModel();
        $staff  = (int) $admins->insert(['username' => 'priya', 'password_hash' => Passwords::hash('Petals#Hubballi9'), 'role' => 'staff', 'status' => 'active']);
        $this->withTwoFactor($admins->find($staff));
        $owner = $admins->first();

        $this->assertSame(EXIT_SUCCESS, $this->spark('florist:admin-recover', ['priya', 'two-factor-off' => null, 'reset-password' => null]));
        $lines = $this->printed();
        $this->assertCount(3, $lines);
        $this->assertSame('Two-step login is off for priya. Ask the owner to turn it on again in Settings.', $lines[0]);
        $this->assertMatchesRegularExpression('/^Temporary password for priya: [A-Za-z0-9]{14}$/', $lines[1]);
        $this->assertSame(self::RECOVER_HINT, $lines[2]);
        $row = $admins->find($staff);
        $this->assertFalse(AdminModel::hasTwoFactor($row));
        $this->assertTrue(Passwords::check(substr($lines[1], strlen('Temporary password for priya: ')), $row['password_hash']));
        $this->assertSame($owner, $admins->find($owner['id']), 'the owner is not touched');

        // `php spark florist:admin-recover --reset-password priya`: CodeIgniter reads "priya" as the option's value,
        // Laravel as the username. It must be priya's password that changes, not the owner's.
        $before = $admins->find($staff)['password_hash'];
        $this->resetStreamFilterBuffer();
        command('florist:admin-recover --reset-password priya');
        $this->assertMatchesRegularExpression('/^Temporary password for priya: [A-Za-z0-9]{14}$/', $this->printed()[0]);
        $this->assertNotSame($before, $admins->find($staff)['password_hash']);
        $this->assertSame($owner, $admins->find($owner['id']));

        // Two usernames: refused, nothing changes.
        $snapshot = $admins->findAll();
        $this->assertSame(EXIT_ERROR, $this->spark('florist:admin-recover', ['admin', 'reset-password' => 'priya']));
        $this->assertSame(['Too many arguments to "florist:admin-recover" command, expected arguments "username".'], $this->printed());
        $this->assertSame($snapshot, $admins->findAll());
    }

    public function test_admin_recover_errors(): void
    {
        $this->install();
        $admins = new AdminModel();
        $admins->insert(['username' => 'priya', 'password_hash' => Passwords::hash('Petals#Hubballi9'), 'role' => 'staff', 'status' => 'active']);
        $snapshot = $admins->findAll();

        // Unknown username → failure (1) and the list of usernames; checked before the options, as in Laravel.
        $this->assertSame(EXIT_ERROR, $this->spark('florist:admin-recover', ['nobody', 'two-factor-off' => null]));
        $this->assertSame(['No admin with that username. Admins: admin, priya'], $this->printed());
        $this->assertSame(EXIT_ERROR, $this->spark('florist:admin-recover', ['nobody']));
        $this->assertSame(['No admin with that username. Admins: admin, priya'], $this->printed());

        // No option → a warning and EXIT_USER_INPUT.
        $this->assertSame(EXIT_USER_INPUT, $this->spark('florist:admin-recover', ['admin']));
        $this->assertSame(['Choose --two-factor-off and/or --reset-password.'], $this->printed());
        $this->assertSame(EXIT_USER_INPUT, $this->spark('florist:admin-recover'));
        $this->assertSame(['Choose --two-factor-off and/or --reset-password.'], $this->printed());
        $this->assertSame(EXIT_USER_INPUT, $this->spark('florist:admin-recover', ['admin', 'two-factor' => null]));

        $this->assertSame($snapshot, $admins->findAll(), 'nothing changed');

        // With no admins at all, the list is empty.
        db_connect()->table('admins')->truncate();
        $this->assertSame(EXIT_ERROR, $this->spark('florist:admin-recover', ['reset-password' => null]));
        $this->assertSame(['No admin with that username. Admins:'], $this->printed());
    }

    // ------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------

    /**
     * Runs a command the way `php spark` does: words first (numeric keys), then options as name => value|null.
     * Returns the exit code; the output is in printed().
     */
    private function spark(string $command, array $params = []): int
    {
        // florist:export-static must never write into the real frontend-user folder.
        $this->assertSame($this->frontend, config(Florist::class)->frontendPath);
        $this->resetStreamFilterBuffer();

        return service('commands')->run($command, $params);
    }

    /** @return list<string> The lines the command printed (STDOUT and STDERR), without colours or blank lines. */
    private function printed(): array
    {
        $text  = (string) preg_replace('/\e\[[0-9;]*m/', '', $this->getStreamFilterBuffer());
        $lines = array_map('rtrim', preg_split('/\R/', $text));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    private function tempDir(string $prefix): string
    {
        $dir              = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(5));
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function dropAllTables(): void
    {
        $db = db_connect();
        $db->resetDataCache();
        $db->disableForeignKeyChecks();
        foreach ($db->listTables() as $table) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $db->enableForeignKeyChecks();
        $db->resetDataCache();
        $this->assertSame([], $db->listTables());
    }

    /** @return array<string, int> category slug → id */
    private function categoryIds(): array
    {
        return array_map('intval', array_column(db_connect()->table('categories')->get()->getResultArray(), 'id', 'slug'));
    }

    private function product(string $slug, int $categoryId, float $price = 0, ?float $sale = null, bool $featured = false, bool $active = true): int
    {
        return (int) (new ProductModel())->insert([
            'category_id' => $categoryId, 'name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug,
            'description' => 'Test product.', 'price' => $price, 'sale_price' => $sale,
            'is_featured' => $featured, 'is_active' => $active, 'sort' => 0,
        ]);
    }

    /** A product photo (and its thumbnail) in the database, with the files in the test web root unless $onDisk is false. */
    private function image(int $productId, string $path, int $sort, bool $onDisk = true): void
    {
        $thumb = substr($path, 0, -5) . '-thumb.webp';
        (new ProductImageModel())->insert(['product_id' => $productId, 'path' => $path, 'thumb_path' => $thumb, 'sort' => $sort]);
        if ($onDisk) {
            $this->file($path);
            $this->file($thumb);
        }
    }

    /** A file in the test web root ("uploads/…"), with content unique to it. */
    private function file(string $path): void
    {
        $file = $this->publicFile('/' . $path);
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, 'photo ' . $path);
    }

    /** @return list<string> Files under a folder, relative to it. */
    private function filesUnder(string $dir): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $item) {
            $files[] = $item->getPathname();
        }

        return $files;
    }

    /** @return array<string, array{0: string, 1: string|null}> slug → [price, sale_price] as MySQL returns them */
    private function prices(): array
    {
        $out = [];
        foreach (db_connect()->table('products')->orderBy('id')->get()->getResultArray() as $row) {
            $out[$row['slug']] = [$row['price'], $row['sale_price']];
        }

        return $out;
    }

    /**
     * Starter photos for florist:demo-data in a temporary folder, four entries: two good ones (one without its own
     * description), one for a category the shop doesn't have and one whose photo is missing.
     */
    private function smallSeed(): string
    {
        $real = rtrim(config(Florist::class)->seedPath, '/\\');
        $dir  = $this->tempDir('cityflorist-seed-');
        mkdir($dir . '/flower-bouquets', 0777, true);
        foreach (['01.webp', '01-thumb.webp', '02.webp', '02-thumb.webp'] as $name) {
            copy("{$real}/flower-bouquets/{$name}", "{$dir}/flower-bouquets/{$name}");
        }
        $manifest = [
            ['category' => 'flower-bouquets', 'name' => 'Flower Bouquet No. 01', 'image' => 'flower-bouquets/01.webp', 'thumb' => 'flower-bouquets/01-thumb.webp', 'description' => 'Seed description one.'],
            ['category' => 'balloons', 'name' => 'Balloon Bunch', 'image' => 'flower-bouquets/02.webp', 'thumb' => 'flower-bouquets/02-thumb.webp'],
            ['category' => 'flower-bouquets', 'name' => 'Flower Bouquet No. 02', 'image' => 'flower-bouquets/02.webp', 'thumb' => 'flower-bouquets/02-thumb.webp'],
            ['category' => 'flower-bouquets', 'name' => 'Missing Photo', 'image' => 'flower-bouquets/99.webp', 'thumb' => 'flower-bouquets/99-thumb.webp'],
        ];
        file_put_contents("{$dir}/manifest.json", json_encode($manifest));

        return $dir;
    }

    /** Turns two-step login on for an account, the way TwoFactor::confirm stores it. Returns the fresh row. */
    private function withTwoFactor(array $admin): array
    {
        $admins = new AdminModel();
        $admins->builder()->where('id', $admin['id'])->update([
            'two_factor_secret'         => Crypto::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => AdminModel::encryptCodes([Passwords::hash('ABCDE12345')]),
            'two_factor_confirmed_at'   => date('Y-m-d H:i:s'),
            'two_factor_last_step'      => 12345,
        ]);

        return $admins->find($admin['id']);
    }

    /** The website's sameAsSnapshot(): the live API's JSON with "/uploads/ read as "/catalog/. */
    private static function asSnapshot(TestResponse $live): string
    {
        return str_replace('"/uploads/', '"/catalog/', self::normalized((string) $live->response()->getBody()));
    }

    /** JSON text as JSON.stringify writes it (parsed, then written again without escaping slashes or unicode). */
    private static function normalized(string $json): string
    {
        return (string) json_encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR), self::FLAGS);
    }
}
