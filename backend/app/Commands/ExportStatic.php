<?php

namespace App\Commands;

use App\Libraries\Catalog;
use App\Libraries\Settings;
use App\Libraries\Text;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Florist;

/**
 * php spark florist:export-static
 *
 * Exports the live catalogue (settings, categories, products, offers) into frontend-user/src/catalog/
 * and its photos into frontend-user/public/catalog/, so the website still shows every product when
 * it is hosted without PHP (for example on Vercel). The folder is Config\Florist::$frontendPath.
 */
class ExportStatic extends BaseCommand
{
    protected $group       = 'City Florist';
    protected $name        = 'florist:export-static';
    protected $description = 'Snapshot the catalogue into frontend-user for static hosting (Vercel)';
    protected $usage       = 'florist:export-static';

    /** @var array<string, true> Photos copied so far, by their path under uploads/. */
    private array $copied = [];

    private string $photos = '';

    public function run(array $params): int
    {
        // Read the shop as it is now, also when this runs inside a longer process (the test suite).
        Settings::flush();
        Catalog::flush();

        $frontend     = rtrim(config(Florist::class)->frontendPath, '/\\');
        $this->photos = "{$frontend}/public/catalog";   // served as /catalog/...
        $data         = "{$frontend}/src/catalog";      // JSON, bundled into the site's JavaScript

        // Values are replaced in place: the website compares the live API with this snapshot as JSON text.
        $categories = array_map(fn (array $c): array => array_replace($c, ['cover' => $this->asset($c['cover'])]), Catalog::publicCategories());

        $site               = Catalog::site();
        $site['categories'] = $categories;
        $site['offers']     = array_map(fn (array $o): array => array_replace($o, ['image' => $this->asset($o['image'])]), $site['offers']);
        foreach (['featured', 'ready', 'wholesale'] as $key) {
            $site[$key] = $this->withAssets($site[$key]);
        }
        $products = ['products' => $this->withAssets(Catalog::allProducts()), 'categories' => $categories];

        if (! is_dir($data)) {
            mkdir($data, 0755, true);
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents("{$data}/site.json", json_encode($site, $flags));
        file_put_contents("{$data}/products.json", json_encode($products, $flags));

        CLI::write(sprintf(
            'Exported %d products and %d categories to %s, and %d images to %s',
            count($products['products']),
            count($categories),
            $data,
            count($this->copied),
            $this->photos,
        ), 'green');

        return EXIT_SUCCESS;
    }

    /** Copies an /uploads/... file into the export and returns its new public path. */
    private function asset(?string $path): ?string
    {
        // With florist.separateDomains the API gives absolute photo URLs (https://api.example.com/uploads/...).
        $base = rtrim(config('App')->baseURL, '/');
        if ($path && $base !== '' && str_starts_with($path, $base . '/uploads/')) {
            $path = substr($path, strlen($base));
        }
        if (! $path || ! str_starts_with($path, '/uploads/')) {
            return $path;
        }
        $rel = substr($path, strlen('/uploads/'));
        $src = Text::publicFile(ltrim($path, '/'));
        if (! isset($this->copied[$rel]) && is_file($src)) {
            $dest = "{$this->photos}/{$rel}";
            if (! is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            copy($src, $dest);
            $this->copied[$rel] = true;
        }

        return '/catalog/' . $rel;
    }

    private function withAssets(array $products): array
    {
        return array_map(function (array $p): array {
            $p['images'] = array_map(fn (array $i): array => ['src' => $this->asset($i['src']), 'thumb' => $this->asset($i['thumb'])], $p['images']);

            return $p;
        }, $products);
    }
}
