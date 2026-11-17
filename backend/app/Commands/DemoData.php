<?php

namespace App\Commands;

use App\Libraries\Installer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark florist:demo-data
 *
 * Fills the catalogue with demo data, safe to run more than once:
 *   1. creates categories added since the site was installed;
 *   2. imports seed photos (Database/Seeds/starter/manifest.json) that aren't products yet;
 *   3. gives every product still at ₹0 a realistic Hubballi price, with about 1 in 4 on sale.
 * Products the owner has already priced are never touched.
 */
class DemoData extends BaseCommand
{
    /** Typical 2026 retail prices for a florist in a tier-2 city (garlands priced per pair). */
    private const PRICE_TIERS = [
        'flower-bouquets'    => [599, 799, 999, 1199, 1499, 1799, 2199, 2499],
        'chocolate-bouquets' => [899, 1199, 1499, 1799, 2199, 2499, 2999],
        'car-decoration'     => [2999, 3999, 4999, 5999, 7499, 8999, 10999],
        'room-decoration'    => [3499, 4499, 5499, 6999, 8499, 9999, 12999],
        'garlands'           => [1199, 1499, 1999, 2499, 2999, 3499, 4499],
        'teddy-bears'        => [299, 449, 599, 799, 999, 1299, 1599],
        'cakes'              => [549, 699, 849, 999, 1199, 1499],
        'wholesale-flowers'  => [199, 249, 299, 349, 399],   // per bunch
    ];

    protected $group       = 'City Florist';
    protected $name        = 'florist:demo-data';
    protected $description = 'Import new starter photos and give unpriced products a demo price';
    protected $usage       = 'florist:demo-data';

    public function run(array $params): int
    {
        foreach (Installer::ensureCategories() as $name) {
            CLI::write("Added category {$name}");
        }

        $imported = Installer::importSeedPhotos();

        // Prices come from a hash of the slug, so re-running gives the same result.
        $priced = 0;
        $db     = db_connect();
        $rows   = $db->table('products p')
            ->select('p.id, p.slug, c.slug AS category')
            ->join('categories c', 'c.id = p.category_id')
            ->where('p.price <=', 0)
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $tiers = self::PRICE_TIERS[$row['category']] ?? null;
            if (! $tiers) {
                continue;
            }
            $hash  = crc32($row['slug']);
            $price = $tiers[$hash % count($tiers)];
            // Roughly 1 in 4 on sale at 10–20% off, rounded to a ₹…49 / ₹…99 price.
            $sale = null;
            if ($hash % 4 === 0) {
                $off  = [0.9, 0.85, 0.8][intdiv($hash, 4) % 3];
                $sale = max(99, (int) (floor($price * $off / 50) * 50) - 1);
            }
            $db->table('products')->where('id', $row['id'])->update(['price' => $price, 'sale_price' => $sale]);
            $priced++;
        }

        CLI::write("Imported {$imported} new products, priced {$priced} products.", 'green');

        return EXIT_SUCCESS;
    }
}
