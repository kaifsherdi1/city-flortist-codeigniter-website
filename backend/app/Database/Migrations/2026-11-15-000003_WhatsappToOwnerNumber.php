<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;
use App\Libraries\Settings;
use Config\Florist;

/**
 * WhatsApp orders and enquiries go to the owner's number (florist.whatsappNumber, 91 99000 94843).
 * Only replaces the number if it is still one the website set earlier; a number typed in Settings is kept.
 */
class WhatsappToOwnerNumber extends FloristMigration
{
    private const PREVIOUS = ['919999999999', '919901794277', ''];

    public function up(): void
    {
        if (! $this->hasTable('settings')) {
            return;
        }
        $current = $this->setting('whatsapp_number');
        if ($current === null || in_array($current, self::PREVIOUS, true)) {
            $this->putSetting('whatsapp_number', config(Florist::class)->whatsappNumber);
        }
        Settings::flush();
    }

    public function down(): void
    {
        // The owner's number; nothing to undo.
    }
}
