<?php

namespace App\Libraries;

use Config\Florist;
use Throwable;

/** Shop settings stored in the `settings` table, with defaults for anything never saved. */
class Settings
{
    /** Settings the public website receives. */
    public const PUBLIC_KEYS = [
        'shop_name', 'whatsapp_number', 'shop_phone', 'email', 'address', 'hours',
        'map_embed', 'map_link', 'instagram', 'facebook', 'hero_title', 'hero_subtitle',
        'google_review_link', 'google_rating', 'google_review_count',
    ];

    /** Settings the owner edits in Admin → Settings → Shop details. */
    public const EDITABLE_KEYS = [
        'whatsapp_number', 'shop_phone', 'email', 'address', 'hours',
        'map_embed', 'map_link', 'instagram', 'facebook', 'hero_title', 'hero_subtitle',
        'google_review_link', 'google_rating', 'google_review_count',
    ];

    // The shop's Google Maps listing ("City Florist", near Court Circle, Deshpande Nagar).
    public const MAPS_CID = '10825630060150326606';

    public const MAPS_PLACE_ID = 'ChIJU9x_hUPXuDsRTgUN3S5dPJY';

    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'shop_name'       => 'City Florist',
            'whatsapp_number' => config(Florist::class)->whatsappNumber,
            'shop_phone'      => '+91 99000 94843',
            'email'           => 'cityfloristh@gmail.com',
            'address'         => 'Shop No. B-6, C Block, Ravenkar Complex, T.B. Road, Near Court Circle, Deshpande Nagar, Hubballi, Karnataka 580029',
            'hours'           => 'Open daily · 8:00 AM – 10:00 PM',
            // The listing itself (not a text search), so the pin and the "City Florist" card are always right.
            'map_embed' => 'https://maps.google.com/maps?cid=' . self::MAPS_CID . '&hl=en&z=17&output=embed',
            'map_link'  => 'https://www.google.com/maps/search/?api=1&query=City+Florist+Hubballi&query_place_id=' . self::MAPS_PLACE_ID,
            // Google's own "write a review" page for the listing. Google reviews are what local search ranks on.
            'google_review_link' => 'https://search.google.com/local/writereview?placeid=' . self::MAPS_PLACE_ID,
            // Shown as "4.7 ★ on Google (40 reviews)" next to the website's own reviews. Empty hides it.
            // The owner updates these from the Google listing now and then.
            'google_rating'       => '4.7',      // from the Google listing, 15 Nov 2026
            'google_review_count' => '43',
            'instagram'           => 'https://www.instagram.com/_city_florist__hubli/',
            'facebook'            => '',
            'hero_title'          => 'Fresh flowers. Made up today.',
            'hero_subtitle'       => 'Bouquets, chocolate bouquets, wedding car and room decoration, bridal garlands, cakes and teddies. Near Court Circle, Deshpande Nagar, Hubballi.',
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::defaults();

            try {
                foreach (db_connect()->table('settings')->get()->getResultArray() as $row) {
                    self::$cache[$row['name']] = $row['value'];
                }
            } catch (Throwable) {
                // Not installed yet.
            }
        }

        return self::$cache;
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::all());
    }

    public static function get(string $name, string $default = ''): string
    {
        return (string) (self::all()[$name] ?? $default);
    }

    public static function put(string $name, ?string $value): void
    {
        db_connect()->query(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, $value],
        );
        self::$cache = null;
    }

    /** Forget the cached values (at the start of every request; see Config\Events). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function publicValues(): array
    {
        return array_intersect_key(self::all(), array_flip(self::PUBLIC_KEYS));
    }
}
