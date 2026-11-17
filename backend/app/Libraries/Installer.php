<?php

namespace App\Libraries;

use App\Models\AdminModel;
use App\Models\CategoryModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use CodeIgniter\Database\MigrationRunner;
use Config\Florist;
use RuntimeException;

/** First-time setup: tables, the admin login, categories, default settings and the starter products. */
class Installer
{
    /** Starter photos shown on the home page as featured. */
    private const FEATURED = [
        'flower-bouquets/01', 'flower-bouquets/04', 'flower-bouquets/14', 'flower-bouquets/20',
        'chocolate-bouquets/16', 'chocolate-bouquets/18', 'chocolate-bouquets/10',
        'car-decoration/07', 'garlands/09', 'garlands/10',
    ];

    /** Descriptions for starter products, by category. */
    public const DESCRIPTIONS = [
        'room-decoration' => 'A romantic surprise set up in your room with fresh rose petals, candles and fairy lights. '
            . 'Representative photo: we design every setup for your room, theme and budget.',
        'teddy-bears' => 'A soft, huggable teddy from City Florist, Hubballi. Gift it on its own or add a bouquet or chocolates. '
            . 'Message us for other sizes and colours.',
        'wholesale-flowers' => Catalog::WHOLESALE_DESCRIPTION,
        'cakes'             => 'A fresh celebration cake for birthdays, anniversaries and surprises. '
            . 'Message us to choose the flavour, weight and the name or message on top. Please order a few hours ahead.',
    ];

    /** The password shipped in .env.example and the README: public knowledge, so never good enough to keep. */
    public const DEFAULT_PASSWORD = 'ChangeMe@123';

    /** True for a password anyone could know: the shipped default, or the starting one from .env (kept in plain text). */
    public static function isStartingPassword(string $password): bool
    {
        return in_array($password, array_unique([self::DEFAULT_PASSWORD, config(Florist::class)->adminPassword]), true);
    }

    /**
     * On the live site, refuses to create the admin with the public default password. Otherwise anyone who opens
     * /admin right after a deploy could install and log in with "ChangeMe@123".
     */
    public static function assertSafeToInstall(): void
    {
        $config   = config(Florist::class);
        $password = $config->adminPassword;
        if ($config->isProduction() && ($password === self::DEFAULT_PASSWORD || strlen($password) < 10)) {
            throw new RuntimeException('Set a strong florist.adminPassword (10+ characters, not the example one) in .env before installing.');
        }
    }

    /**
     * True once an admin exists. Only a missing or empty admins table means "not installed": if the database can't
     * be reached the error is thrown, so an outage never shows strangers the Install screen (with the admin
     * username) or lets the install answer print the database error.
     */
    public static function installed(): bool
    {
        $db = db_connect();

        return $db->tableExists('admins', false) && $db->table('admins')->countAllResults() > 0;
    }

    private static function migrations(): MigrationRunner
    {
        $runner = service('migrations', null, null, false);
        $runner->setNamespace('App');

        return $runner;
    }

    /** Runs every migration that hasn't run yet. */
    public static function migrate(): void
    {
        self::migrations()->latest();
        db_connect()->resetDataCache();
    }

    /**
     * Applies database changes shipped with a new version. Hosting without SSH can't run
     * `php spark migrate`, so the dashboard calls this when it opens. Cheap when there is nothing to do.
     */
    public static function upgrade(): void
    {
        $runner = self::migrations();
        $group  = config(\Config\Database::class)->defaultGroup;
        $ran    = array_map(static fn ($row): string => $runner->getObjectUid($row), $runner->getHistory($group));
        foreach (array_keys($runner->findMigrations()) as $uid) {
            if (! in_array($uid, $ran, true)) {
                self::migrate();

                return;
            }
        }
    }

    /** Runs the whole install. Returns a log of what was done. */
    public static function run(bool $seed = true): array
    {
        self::assertSafeToInstall();
        $config = config(Florist::class);
        $log    = [];

        self::migrate();
        $log[] = 'Tables created';

        (new AdminModel())->insert([
            'username'      => $config->adminUsername,
            'password_hash' => Passwords::hash($config->adminPassword),
            'email'         => strtolower(trim($config->adminEmail)) ?: null,
            'phone'         => Text::normalizePhone($config->adminPhone),
        ]);
        $log[] = 'Admin login created';

        self::ensureCategories();
        $log[] = 'Categories created';

        foreach (Settings::defaults() as $k => $v) {
            Settings::put($k, $v);
        }
        // Fresh installs are already on the current catalogue layout.
        Settings::put('catalog_rev', '2');

        if ($seed) {
            $count = self::importSeedPhotos(true);
            $log[] = "{$count} starter products imported";
        }

        return $log;
    }

    /** Creates any default category that doesn't exist yet. Returns the names added. */
    public static function ensureCategories(): array
    {
        $model = new CategoryModel();
        $added = [];
        foreach (Catalog::defaultCategories() as $i => [$slug, $name, $tagline]) {
            if ($model->where('slug', $slug)->countAllResults() === 0) {
                $model->insert(['slug' => $slug, 'name' => $name, 'tagline' => $tagline, 'sort' => $i]);
                $added[] = $name;
            }
        }

        return $added;
    }

    /**
     * Turns starter photos from Seeds/starter/manifest.json into products, skipping any already imported.
     * Returns how many products were created.
     */
    public static function importSeedPhotos(bool $featured = false): int
    {
        $seedDir    = rtrim(config(Florist::class)->seedPath, '/\\');
        $manifest   = json_decode((string) @file_get_contents("{$seedDir}/manifest.json"), true) ?: [];
        $categories = array_column((new CategoryModel())->findAll(), 'id', 'slug');
        $products   = new ProductModel();
        $images     = new ProductImageModel();
        $dir        = Text::publicFile('uploads/products');
        $count      = 0;

        foreach ($manifest as $i => $item) {
            $name = pathinfo($item['image'], PATHINFO_FILENAME);
            $base = $item['category'] . '-' . $name;
            $path = "uploads/products/{$base}.webp";
            $src  = "{$seedDir}/{$item['image']}";
            if (! isset($categories[$item['category']]) || ! is_file($src) || $images->where('path', $path)->countAllResults() > 0) {
                continue;
            }
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($src, Text::publicFile($path));
            copy("{$seedDir}/{$item['thumb']}", Text::publicFile("uploads/products/{$base}-thumb.webp"));

            $productId = $products->insert([
                'category_id' => $categories[$item['category']],
                'name'        => $item['name'],
                'slug'        => $products->uniqueSlug($item['name']),
                // Written from each photo; the category text is the fallback for photos without one.
                'description' => $item['description'] ?? self::DESCRIPTIONS[$item['category']] ?? Catalog::DEFAULT_DESCRIPTION,
                'price'       => 0,
                'is_featured' => $featured && in_array($item['category'] . '/' . $name, self::FEATURED, true),
                'is_active'   => true,
                'sort'        => $i,
            ]);
            $images->insert(['product_id' => $productId, 'path' => $path, 'thumb_path' => "uploads/products/{$base}-thumb.webp", 'sort' => 0]);
            $count++;
        }

        return $count;
    }
}
