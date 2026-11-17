<?php

namespace App\Controllers\Api;

use App\Controllers\ApiController;
use App\Libraries\Catalog as Shop;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;

/** The public website's catalogue: /api/site and /api/products. */
class Catalog extends ApiController
{
    /** GET /api/site: everything the home page needs in one request. */
    public function site(): ResponseInterface
    {
        return $this->json(Shop::site());
    }

    /**
     * GET /api/products             → all live products
     * GET /api/products?cat=<slug>  → one category
     * GET /api/products?slug=<slug> → one product + related
     */
    public function products(): ResponseInterface
    {
        $slug = (string) $this->query('slug', '');
        if ($slug !== '') {
            $found = Shop::products(static fn (BaseBuilder $q) => $q->where('p.slug', $slug), ['p.id'], 1);
            if ($found === []) {
                return $this->json(['error' => 'Product not found'], 404);
            }
            $product = $found[0];
            $related = Shop::products(
                static fn (BaseBuilder $q) => $q->where('c.slug', $product['category']['slug'])->where('p.slug <>', $slug),
                ['p.is_featured desc', 'p.sort'],
                4,
            );

            return $this->json(['product' => $product, 'related' => $related]);
        }

        $cat      = (string) $this->query('cat', '');
        $products = $cat !== ''
            ? Shop::inCategory($cat, ['p.is_featured desc', 'p.sort', 'p.id desc'])
            : Shop::allProducts();

        return $this->json(['products' => $products, 'categories' => Shop::publicCategories()]);
    }
}
