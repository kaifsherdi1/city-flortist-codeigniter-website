<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;
use App\Libraries\Catalog;
use Config\Florist;

/**
 * Gives each starter product its own description (written from its photo, in Seeds/starter/manifest.json).
 * Only products still on the generic sentence are changed; anything the owner wrote is kept.
 */
class DescribeStarterProducts extends FloristMigration
{
    public function up(): void
    {
        if (! $this->hasTable('products') || ! $this->hasTable('product_images')) {
            return;
        }
        $manifest = json_decode((string) @file_get_contents(config(Florist::class)->seedPath . 'manifest.json'), true) ?: [];
        foreach ($manifest as $item) {
            if (empty($item['description'])) {
                continue;
            }
            $path  = 'uploads/products/' . $item['category'] . '-' . pathinfo($item['image'], PATHINFO_FILENAME) . '.webp';
            $image = $this->db->table('product_images')->select('product_id')->where('path', $path)->get()->getRowArray();
            if (! $image) {
                continue;
            }
            $this->db->table('products')->where('id', $image['product_id'])
                ->groupStart()
                ->where('description', null)->orWhere('description', '')->orWhere('description', Catalog::DEFAULT_DESCRIPTION)
                ->groupEnd()
                ->update(['description' => $item['description']]);
        }
    }

    public function down(): void
    {
        // Descriptions are content the owner keeps editing; nothing to undo.
    }
}
