<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;
use App\Libraries\Catalog;
use App\Libraries\Text;

/**
 * For sites installed before November 2026: the old "Ready to Go" category held loose wholesale
 * flowers, so it becomes "Wholesale Flowers". "Ready to go" now shows bouquets the owner picks.
 * Does nothing on fresh installs and on sites already upgraded (setting catalog_rev >= 2).
 */
class CatalogRev2 extends FloristMigration
{
    public function up(): void
    {
        if ((int) $this->setting('catalog_rev') >= 2) {
            return;
        }

        $old = $this->db->table('categories')->select('id')->where('slug', 'ready-to-go')->get()->getRowArray();
        $hasWholesale = $this->db->table('categories')->where('slug', 'wholesale-flowers')->countAllResults() > 0;
        if ($old && ! $hasWholesale) {
            $this->db->table('categories')->where('id', $old['id'])->update([
                'slug' => 'wholesale-flowers', 'name' => 'Wholesale Flowers', 'tagline' => Catalog::WHOLESALE_TAGLINE,
            ]);
            $this->db->table('products')->where('category_id', $old['id'])->like('description', 'A fresh bunch from today', 'after')
                ->update(['description' => Catalog::WHOLESALE_DESCRIPTION]);
        }

        // Starter photos are named after their category; keep that true so demo data isn't imported twice.
        $images = $this->db->table('product_images')->like('path', 'uploads/products/ready-to-go-', 'after')->get()->getResultArray();
        foreach ($images as $img) {
            $new = [];
            foreach (['path', 'thumb_path'] as $col) {
                $new[$col] = str_replace('/ready-to-go-', '/wholesale-flowers-', $img[$col]);
                if (is_file(Text::publicFile($img[$col])) && ! is_file(Text::publicFile($new[$col]))) {
                    rename(Text::publicFile($img[$col]), Text::publicFile($new[$col]));
                }
            }
            $this->db->table('product_images')->where('id', $img['id'])->update($new);
        }

        $this->putSetting('catalog_rev', '2');
    }

    public function down(): void
    {
        // One-way data upgrade.
    }
}
