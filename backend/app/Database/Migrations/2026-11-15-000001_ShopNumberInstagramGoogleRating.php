<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;
use App\Libraries\Settings;

/**
 * Shop number as on the Google listing (B-6), the shop's Instagram page and its Google rating.
 * Only values still on what the website set before are replaced; the owner's own edits are kept.
 */
class ShopNumberInstagramGoogleRating extends FloristMigration
{
    private const OLD = [
        'address'             => ['Shop No. B7A, C Block, Ravenkar Complex, T.B. Road, Near Court Circle, Deshpande Nagar, Hubballi, Karnataka 580029', 'Hubballi, Karnataka', ''],
        'instagram'           => [''],
        'google_rating'       => [''],
        'google_review_count' => [''],
    ];

    public function up(): void
    {
        if (! $this->hasTable('settings')) {
            return;
        }
        $defaults = Settings::defaults();
        foreach (self::OLD as $name => $old) {
            $current = $this->setting($name);
            if ($current === null || in_array($current, $old, true)) {
                $this->putSetting($name, $defaults[$name]);
            }
        }
        Settings::flush();
    }

    public function down(): void
    {
        // Shop details the owner keeps; nothing to undo.
    }
}
