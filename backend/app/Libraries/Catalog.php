<?php

namespace App\Libraries;

use App\Models\ReviewModel;
use CodeIgniter\Database\BaseBuilder;
use Throwable;

/**
 * What the public website shows: products, categories, running offers and the price a customer pays.
 * Pricing here is the server-side source of truth for orders.
 */
class Catalog
{
    public const WHOLESALE_SLUG = 'wholesale-flowers';

    public const WHOLESALE_TAGLINE = 'Fresh loose flowers in bulk for events, temples, shops and florists.';

    public const WHOLESALE_DESCRIPTION = 'Fresh loose flowers from City Florist, Hubballi, sold in bulk by the bunch or by the kilo. '
        . 'For weddings, events, temples, shops and florists. Message us for today’s rate and the quantity you need.';

    public const DEFAULT_DESCRIPTION = 'Handcrafted fresh at City Florist, Hubballi. Message us to customise colours, size or add a card.';

    /** How many bouquets the home page's "Ready to go" ring shows until the owner picks them in Admin → Products. */
    public const READY_DEFAULT_COUNT = 5;

    private static ?array $offers = null;

    /** [slug, name, tagline] in display order. */
    public static function defaultCategories(): array
    {
        return [
            ['flower-bouquets', 'Flower Bouquets', 'Roses, lilies, gerberas and seasonal flowers, wrapped to order.'],
            ['chocolate-bouquets', 'Chocolate Bouquets', 'Dairy Milk, KitKat, Ferrero Rocher and more, with or without roses.'],
            ['car-decoration', 'Car Decoration', 'Fresh flowers and ribbons on the bonnet, mirrors and doors.'],
            ['room-decoration', 'Room Decoration', 'Petals, candles and lights for wedding nights and surprises.'],
            ['garlands', 'Bridal Garlands', 'Fresh varmala and haar for the bride and groom.'],
            ['teddy-bears', 'Teddy Bears', 'Soft teddies to gift on their own or with flowers.'],
            ['cakes', 'Cakes', 'Cakes for birthdays, anniversaries and surprises.'],
            // Shown in its own "Wholesale Flowers" section on the home page, not in the Collections slider.
            ['wholesale-flowers', 'Wholesale Flowers', self::WHOLESALE_TAGLINE],
        ];
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Forget the cached offers (at the start of every request; see Config\Events). */
    public static function flush(): void
    {
        self::$offers = null;
    }

    /** Offers that are switched on and running right now, biggest discount first. */
    public static function activeOffers(): array
    {
        if (self::$offers === null) {
            $now          = self::now();
            self::$offers = db_connect()->table('offers o')
                ->select('o.*, p.slug AS product_slug, p.name AS product_name, c.slug AS category_slug, c.name AS category_name')
                ->join('products p', 'p.id = o.product_id', 'left')
                ->join('categories c', 'c.id = o.category_id', 'left')
                ->where('o.is_active', 1)
                ->where('o.starts_at <=', $now)
                ->where('o.ends_at >=', $now)
                ->orderBy('o.discount_pct', 'DESC')
                ->orderBy('o.ends_at', 'ASC')
                ->get()->getResultArray();
        }

        return self::$offers;
    }

    /** Running offers shaped for the home page banner. */
    public static function presentOffers(): array
    {
        return array_map(static fn (array $o): array => [
            'id'       => (int) $o['id'],
            'title'    => $o['title'],
            'subtitle' => $o['subtitle'] ?? '',
            'discount' => (int) $o['discount_pct'],
            'ends_at'  => date(DATE_ATOM, strtotime($o['ends_at'])),
            'image'    => Text::publicPath($o['image']),
            'link'     => $o['product_slug'] ? '/product/' . $o['product_slug'] : ($o['category_slug'] ? '/shop?cat=' . $o['category_slug'] : '/shop'),
        ], self::activeOffers());
    }

    /** Best running offer for a product: product-specific, its category, or site-wide. */
    public static function offerFor(array $product): ?array
    {
        $best = null;
        foreach (self::activeOffers() as $offer) {
            $applies = ($offer['product_id'] && (int) $offer['product_id'] === (int) $product['id'])
                || ($offer['category_id'] && (int) $offer['category_id'] === (int) $product['category_id'])
                || (! $offer['product_id'] && ! $offer['category_id']);
            if ($applies && (! $best || $offer['discount_pct'] > $best['discount_pct'])) {
                $best = $offer;
            }
        }

        return $best;
    }

    /** Works out the price a customer pays. */
    public static function pricing(array $product): array
    {
        $price = (float) $product['price'];
        $sale  = $product['sale_price'] !== null && $product['sale_price'] !== '' ? (float) $product['sale_price'] : null;

        // Wholesale market rates change daily, so those flowers never show a fixed price.
        if ($price <= 0 || ($product['category_slug'] ?? null) === self::WHOLESALE_SLUG) {
            return ['price' => 0, 'final' => 0, 'discount_pct' => 0, 'offer' => null, 'on_request' => true];
        }

        $base  = ($sale !== null && $sale > 0 && $sale < $price) ? $sale : $price;
        $offer = self::offerFor($product);
        $final = $offer ? round($base * (100 - (int) $offer['discount_pct']) / 100) : $base;

        return [
            'price'        => $price,
            'final'        => $final,
            'discount_pct' => $final < $price ? (int) round(($price - $final) / $price * 100) : 0,
            'offer'        => $offer ? $offer['title'] : null,
            'on_request'   => false,
        ];
    }

    /** product id → [{src, thumb}, …] */
    public static function productImages(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $map  = [];
        $rows = db_connect()->table('product_images')->whereIn('product_id', $productIds)->orderBy('sort')->orderBy('id')->get()->getResultArray();
        foreach ($rows as $img) {
            $map[(int) $img['product_id']][] = [
                'src'   => Text::publicPath($img['path']),
                'thumb' => Text::publicPath($img['thumb_path']),
            ];
        }

        return $map;
    }

    /** Shapes product rows (joined with their category) for the public API. */
    public static function presentProducts(array $rows): array
    {
        $images = self::productImages(array_map(static fn ($r) => (int) $r['id'], $rows));

        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'slug'        => $r['slug'],
            'name'        => $r['name'],
            'description' => $r['description'] ?? '',
            'category'    => ['slug' => $r['category_slug'], 'name' => $r['category_name']],
            'featured'    => (bool) $r['is_featured'],
            'images'      => $images[(int) $r['id']] ?? [],
            'pricing'     => self::pricing($r),
        ], $rows);
    }

    /**
     * Live products as the public API shows them.
     *
     * @param callable(BaseBuilder): mixed|null $where receives the query builder (tables aliased p and c)
     * @param list<string>                      $order list of "column [desc]" strings
     */
    public static function products(?callable $where = null, array $order = ['p.sort', 'p.id desc'], int $limit = 0, bool $activeOnly = true): array
    {
        $q = db_connect()->table('products p')
            ->select('p.*, c.slug AS category_slug, c.name AS category_name')
            ->join('categories c', 'c.id = p.category_id');
        if ($activeOnly) {
            $q->where('p.is_active', 1);
        }
        if ($where !== null) {
            $where($q);
        }
        foreach ($order as $o) {
            [$col, $dir] = array_pad(explode(' ', $o), 2, 'asc');
            $q->orderBy($col, strtoupper($dir));
        }
        if ($limit > 0) {
            $q->limit($limit);
        }

        return self::presentProducts($q->get()->getResultArray());
    }

    public static function inCategory(string $slug, array $order = ['p.sort', 'p.id'], int $limit = 0): array
    {
        return self::products(static fn (BaseBuilder $q) => $q->where('c.slug', $slug), $order, $limit);
    }

    public static function featured(): array
    {
        return self::products(static fn (BaseBuilder $q) => $q->where('p.is_featured', 1), ['p.sort', 'p.id'], 12);
    }

    public static function publicCategories(): array
    {
        $db   = db_connect();
        $rows = $db->table('categories c')
            ->select('c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count', false)
            ->orderBy('c.sort')->orderBy('c.id')
            ->get()->getResultArray();

        return array_map(static function (array $c) use ($db): array {
            $cover = $c['cover_image'] ?: null;
            if ($cover === null) {
                $row = $db->table('product_images i')
                    ->select('i.path')
                    ->join('products p', 'p.id = i.product_id')
                    ->where('p.category_id', $c['id'])->where('p.is_active', 1)
                    ->orderBy('p.is_featured', 'DESC')->orderBy('p.sort')->orderBy('i.sort')
                    ->limit(1)->get()->getRowArray();
                $cover = $row['path'] ?? null;
            }

            return [
                'slug'    => $c['slug'],
                'name'    => $c['name'],
                'tagline' => $c['tagline'] ?? '',
                'cover'   => Text::publicPath($cover ?: null),
                'count'   => (int) $c['product_count'],
            ];
        }, $rows);
    }

    /**
     * IDs the owner ticked as "Ready to go" in Admin → Products (setting `ready_ids`), or null if they
     * have never chosen, in which case the first few flower bouquets are shown.
     */
    public static function readyIds(): ?array
    {
        if (! Settings::has('ready_ids')) {
            return null;
        }

        return array_values(array_filter(array_map('intval', explode(',', Settings::get('ready_ids')))));
    }

    /** Products for the home page's "Ready to go" ring, in the order the owner ticked them. */
    public static function readyProducts(): array
    {
        $ids = self::readyIds();
        if ($ids === null) {
            return self::inCategory('flower-bouquets', ['p.is_featured desc', 'p.sort', 'p.id'], self::READY_DEFAULT_COUNT);
        }
        if ($ids === []) {
            return [];
        }
        $rows = self::products(static fn (BaseBuilder $q) => $q->whereIn('p.id', $ids));
        $pos  = array_flip($ids);
        usort($rows, static fn ($a, $b) => $pos[$a['id']] <=> $pos[$b['id']]);

        return $rows;
    }

    /** The IDs currently shown in "Ready to go" (the default bouquets until the owner chooses). */
    public static function currentReadyIds(): array
    {
        return self::readyIds() ?? array_map(static fn ($p) => $p['id'], self::readyProducts());
    }

    public static function saveReadyIds(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        Settings::put('ready_ids', implode(',', $ids));
    }

    /** Everything the home page needs in one response (also used for the static snapshot). */
    public static function site(): array
    {
        return [
            'settings'   => Settings::publicValues(),
            'categories' => self::publicCategories(),
            'offers'     => self::presentOffers(),
            'featured'   => self::featured(),
            'ready'      => self::readyProducts(),
            'wholesale'  => self::inCategory(self::WHOLESALE_SLUG),
            'reviews'    => self::reviews(),
        ];
    }

    /** Approved customer reviews (newest first, up to 12) and their average. */
    public static function reviews(): array
    {
        try {
            $model = new ReviewModel();

            return [
                'summary' => $model->summary(),
                'items'   => array_map([ReviewModel::class, 'toPublic'], $model->where('status', 'approved')->orderBy('id', 'DESC')->findAll(12)),
            ];
        } catch (Throwable) {
            return ['summary' => ['count' => 0, 'average' => 0], 'items' => []]; // before the reviews table exists
        }
    }

    /** Every live product, for the shop page. */
    public static function allProducts(): array
    {
        return self::products(null, ['c.sort', 'p.is_featured desc', 'p.sort', 'p.id desc']);
    }
}
