<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;
use App\Libraries\Settings;

/**
 * Plainer wording: the home page headline and the category taglines. Only text still exactly as the website
 * first wrote it is replaced; anything the owner changed in Settings or Categories is kept.
 */
class PlainSiteWording extends FloristMigration
{
    private const SETTINGS = [
        'hero_title'    => ['Crafted in petals. Delivered with love.', 'Fresh flowers. Made up today.'],
        'hero_subtitle' => [
            'Fresh flower bouquets, chocolate bouquets, car & wedding-night decoration and bridal garlands — handmade in Hubballi.',
            'Bouquets, chocolate bouquets, wedding car and room decoration, bridal garlands, cakes and teddies. Near Court Circle, Deshpande Nagar, Hubballi.',
        ],
    ];

    private const TAGLINES = [
        'Hand-tied roses, lilies & seasonal blooms for every feeling.'       => 'Roses, lilies, gerberas and seasonal flowers, wrapped to order.',
        'Sweet surprises wrapped like flowers — Ferrero, Dairy Milk & more.' => 'Dairy Milk, KitKat, Ferrero Rocher and more, with or without roses.',
        'Wedding car styling with fresh flowers, ribbons & elegance.'        => 'Fresh flowers and ribbons on the bonnet, mirrors and doors.',
        'Wedding-night & surprise room décor with petals, candles and lights.' => 'Petals, candles and lights for wedding nights and surprises.',
        'Fresh varmala & haar for the bride and groom.'                      => 'Fresh varmala and haar for the bride and groom.',
        'Soft, huggable teddies to gift on their own or with flowers.'       => 'Soft teddies to gift on their own or with flowers.',
        'Celebration cakes for birthdays, anniversaries & surprises.'        => 'Cakes for birthdays, anniversaries and surprises.',
    ];

    public function up(): void
    {
        if ($this->hasTable('settings')) {
            foreach (self::SETTINGS as $name => [$old, $new]) {
                $this->db->table('settings')->where('name', $name)->where('value', $old)->update(['value' => $new]);
            }
            Settings::flush();
        }
        if ($this->hasTable('categories')) {
            foreach (self::TAGLINES as $old => $new) {
                $this->db->table('categories')->where('tagline', $old)->update(['tagline' => $new]);
            }
        }
    }

    public function down(): void
    {
        // Wording only; nothing to undo.
    }
}
