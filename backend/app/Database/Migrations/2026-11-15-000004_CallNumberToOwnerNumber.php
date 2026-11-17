<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;
use App\Libraries\Settings;

/**
 * The Call button uses the owner's number too (+91 99000 94843), so the site shows one number everywhere.
 * Only replaces a number the website set earlier; a number typed in Settings is kept.
 */
class CallNumberToOwnerNumber extends FloristMigration
{
    private const PREVIOUS = ['+91 99017 94277', ''];

    public function up(): void
    {
        if (! $this->hasTable('settings')) {
            return;
        }
        $current = $this->setting('shop_phone');
        if ($current === null || in_array($current, self::PREVIOUS, true)) {
            $this->putSetting('shop_phone', Settings::defaults()['shop_phone']);
        }
        Settings::flush();
    }

    public function down(): void
    {
        // The owner's number; nothing to undo.
    }
}
