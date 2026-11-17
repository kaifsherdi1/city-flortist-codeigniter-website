<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;
use App\Libraries\Settings;
use Config\Florist;

/**
 * The shop's real contact details: owner's number, full address and the Google Maps listing.
 * Only replaces values still on the old placeholders, so anything the owner typed in Settings is kept.
 */
class SetOwnerContactDetails extends FloristMigration
{
    private const PLACEHOLDERS = [
        'whatsapp_number' => ['919999999999', ''],
        'shop_phone'      => [''],
        'address'         => ['Hubballi, Karnataka', ''],
        'map_embed'       => ['https://www.google.com/maps?q=Hubballi,Karnataka&output=embed', ''],
        'map_link'        => [''],
    ];

    public function up(): void
    {
        if (! $this->hasTable('settings')) {
            return;
        }
        $defaults                    = Settings::defaults();
        $defaults['whatsapp_number'] = config(Florist::class)->whatsappNumber;
        foreach (self::PLACEHOLDERS as $name => $old) {
            $current = $this->setting($name);
            if ($current === null || in_array($current, $old, true)) {
                $this->putSetting($name, $defaults[$name]);
            }
        }
        Settings::flush();
    }

    public function down(): void
    {
        // Contact details are data the owner keeps; nothing to undo.
    }
}
