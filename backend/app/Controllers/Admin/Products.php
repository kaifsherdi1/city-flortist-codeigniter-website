<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Libraries\Catalog;
use App\Libraries\Images;
use App\Models\OfferModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Dashboard → Products: the product list with quick edits, the product form and its photos.
 * Same answers as the Laravel version's Admin\ProductController.
 */
class Products extends ApiController
{
    /** GET /api/admin/categories: for category pickers. */
    public function categories(): ResponseInterface
    {
        $rows = db_connect()->table('categories')->select('id, name, slug')->orderBy('sort')->orderBy('id')->get()->getResultArray();

        return $this->json(['categories' => array_map(static fn (array $c): array => [
            'id'   => (int) $c['id'],
            'name' => $c['name'],
            'slug' => $c['slug'],
        ], $rows)]);
    }

    /** GET /api/admin/products?q=&cat=&unpriced=1&featured=1 */
    public function index(): ResponseInterface
    {
        $search = trim((string) $this->query('q', ''));
        $cat    = (string) $this->query('cat', '');

        $q = db_connect()->table('products p')
            ->select('p.id, p.name, p.slug, p.price, p.sale_price, p.is_active, p.is_featured, c.name AS category_name')
            // The main photo's thumbnail: the first photo by sort, then id.
            ->select('(SELECT i.thumb_path FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort ASC, i.id ASC LIMIT 1) AS thumb', false)
            ->join('categories c', 'c.id = p.category_id');
        if ($search !== '') {
            // Like Laravel's where('p.name', 'like', "%$search%"): % and _ typed in the search stay wildcards.
            $q->where('p.name LIKE', "%{$search}%");
        }
        if ($cat !== '') {
            $q->where('c.slug', $cat);
        }
        if ($this->flag('unpriced')) {
            $q->where('p.price <=', 0);
        }
        if ($this->flag('featured')) {
            $q->where('p.is_featured', 1);
        }
        $rows = $q->orderBy('c.sort')->orderBy('p.sort')->orderBy('p.id', 'DESC')->get()->getResultArray();

        $ready = array_flip(Catalog::currentReadyIds());

        return $this->json([
            'products' => array_map(static fn (array $p): array => [
                'id'         => (int) $p['id'],
                'name'       => $p['name'],
                'slug'       => $p['slug'],
                'category'   => $p['category_name'],
                'price'      => (float) $p['price'],
                'sale_price' => $p['sale_price'] !== null ? (float) $p['sale_price'] : null,
                'active'     => (bool) $p['is_active'],
                'featured'   => (bool) $p['is_featured'],
                'ready'      => isset($ready[(int) $p['id']]),
                'thumb'      => $p['thumb'] ? '/' . $p['thumb'] : null,
            ], $rows),
        ]);
    }

    /** POST /api/admin/products/bulk {rows: {id: {name, price, sale, active, featured, ready}}} */
    public function bulk(): ResponseInterface
    {
        $rows = (array) $this->input('rows', []);

        // "Ready to go": drop the rows sent from the current choice, then add back the ticked ones (in order).
        $ready = array_values(array_diff(Catalog::currentReadyIds(), array_map('intval', array_keys($rows))));
        foreach ($rows as $id => $row) {
            if (! empty($row['ready'])) {
                $ready[] = (int) $id;
            }
        }

        // One transaction, like Laravel's DB::transaction(): anything that fails undoes every change.
        $db = db_connect();
        $db->transBegin();

        try {
            Catalog::saveReadyIds($ready);
            $products = new ProductModel();
            foreach ($rows as $id => $row) {
                $product = $products->find((int) $id);
                if (! $product) {
                    continue; // deleted meanwhile (or never existed): ignored
                }
                $name    = mb_substr(trim((string) ($row['name'] ?? '')), 0, 160) ?: 'Untitled';
                $sale    = trim((string) ($row['sale'] ?? ''));
                $changes = [
                    'name'        => $name,
                    'price'       => max(0, (float) ($row['price'] ?? 0)),
                    'sale_price'  => $sale === '' ? null : max(0, (float) $sale),
                    'is_active'   => ! empty($row['active']),
                    'is_featured' => ! empty($row['featured']),
                ];
                // A new address only when the name changed, so links already shared keep working.
                if ($product['name'] !== $name) {
                    $changes['slug'] = $products->uniqueSlug($name, (int) $product['id']);
                }
                $products->update((int) $product['id'], $changes);
            }
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return $this->json(['message' => 'Changes saved.']);
    }

    /** GET /api/admin/products/{product} */
    public function show(int|string $id): ResponseInterface
    {
        $product = $this->product((int) $id);

        return $this->json(['product' => $this->present((int) $product['id'])]);
    }

    /** POST /api/admin/products (multipart, with images[]) */
    public function store(): ResponseInterface
    {
        $data     = $this->validated();
        $products = new ProductModel();
        $id       = (int) $products->insert($data + ['slug' => $products->uniqueSlug($data['name']), 'sort' => 0]);
        $warnings = $this->addImages($id);

        return $this->json(['message' => 'Product added.', 'warnings' => $warnings, 'product' => $this->present($id)], 201);
    }

    /** POST /api/admin/products/{product} (multipart, with images[]). POST because PHP only parses uploads on POST. */
    public function update(int|string $id): ResponseInterface
    {
        $product  = $this->product((int) $id);
        $id       = (int) $product['id'];
        $data     = $this->validated();
        $products = new ProductModel();
        $products->update($id, $data + ['slug' => $products->uniqueSlug($data['name'], $id)]);
        $warnings = $this->addImages($id);

        return $this->json(['message' => 'Product updated.', 'warnings' => $warnings, 'product' => $this->present($id)]);
    }

    /** DELETE /api/admin/products/{product}: the product and its photos. */
    public function destroy(int|string $id): ResponseInterface
    {
        $product = $this->product((int) $id);
        $id      = (int) $product['id'];
        $db      = db_connect();

        foreach ($db->table('product_images')->where('product_id', $id)->get()->getResultArray() as $img) {
            Images::delete($img['path'], $img['thumb_path']);
        }
        // Offers made for just this product go too. Left behind, the database would clear their product
        // link and an offer with no product means "everything": a site-wide discount nobody asked for.
        $offerModel = new OfferModel();
        $offers     = $offerModel->where('product_id', $id)->findAll();
        foreach ($offers as $offer) {
            Images::delete($offer['image']);
            $offerModel->delete((int) $offer['id']);
        }
        $db->table('product_images')->where('product_id', $id)->delete();
        (new ProductModel())->delete($id);

        $count = count($offers);

        return $this->json(['message' => $count ? 'Product deleted, with its ' . $count . ' offer(s).' : 'Product deleted.']);
    }

    /** POST /api/admin/products/{product}/images/{image}/main */
    public function mainImage(int|string $id, int|string $imageId): ResponseInterface
    {
        [$product, $image] = $this->productImage((int) $id, (int) $imageId);

        db_connect()->table('product_images')->where('product_id', $product['id'])->set('sort', 'sort + 1', false)->update();
        // Eloquent's $image->update(['sort' => 0]) only writes when the value it loaded (before the increment
        // above) differs from 0, so a photo already at sort 0 keeps its incremented value. Done the same way.
        if ((int) $image['sort'] !== 0) {
            db_connect()->table('product_images')->where('id', $image['id'])->update(['sort' => 0]);
        }

        return $this->json(['product' => $this->present((int) $product['id'])]);
    }

    /** DELETE /api/admin/products/{product}/images/{image} */
    public function deleteImage(int|string $id, int|string $imageId): ResponseInterface
    {
        [$product, $image] = $this->productImage((int) $id, (int) $imageId);

        Images::delete($image['path'], $image['thumb_path']);
        (new ProductImageModel())->delete((int) $image['id']);

        return $this->json(['product' => $this->present((int) $product['id'])]);
    }

    // ------------------------------------------------------------------------------------------------------

    /** The product, or 404 "Not found." (Laravel's route model binding). */
    private function product(int $id): array
    {
        $product = (new ProductModel())->find($id);
        if (! is_array($product)) {
            $this->missing();
        }

        return $product;
    }

    /**
     * The product and one of its photos. 404 when either is missing, or when the photo belongs to another
     * product (so photos of one product can't be changed through another).
     *
     * @return array{0: array, 1: array}
     */
    private function productImage(int $productId, int $imageId): array
    {
        $product = $this->product($productId);
        $image   = (new ProductImageModel())->find($imageId);
        if (! is_array($image)) {
            $this->missing();
        }
        // Laravel checked this inside the controller (abort_unless), so it is a controller answer.
        if ((int) $image['product_id'] !== (int) $product['id']) {
            $this->fail('Not found.', 404);
        }

        return [$product, $image];
    }

    private function validated(): array
    {
        $data = [
            'name'        => $this->str('name', 160),
            'category_id' => (int) $this->input('category_id', 0),
            'description' => $this->str('description', 2000),
            'price'       => $this->money('price') ?? 0,
            'sale_price'  => $this->money('sale_price'),
            'is_active'   => $this->flag('is_active'),
            'is_featured' => $this->flag('is_featured'),
        ];
        if ($data['name'] === '') {
            $this->fail('Please enter a product name.');
        }
        if (db_connect()->table('categories')->where('id', $data['category_id'])->countAllResults() === 0) {
            $this->fail('Please choose a category.');
        }
        if ($data['sale_price'] !== null && $data['price'] > 0 && $data['sale_price'] >= $data['price']) {
            $this->fail('Sale price must be lower than the normal price (or leave it empty).');
        }

        return $data;
    }

    /** Saves uploaded photos after the existing ones. Returns a message for each photo that failed. */
    private function addImages(int $productId): array
    {
        $warnings = $this->uploadWarnings('images');
        $db       = db_connect();
        $sort     = $db->table('product_images')->where('product_id', $productId)->countAllResults() > 0
            ? (int) ($db->table('product_images')->selectMax('sort')->where('product_id', $productId)->get()->getRowArray()['sort'] ?? 0) + 1
            : 0;
        $images = new ProductImageModel();
        foreach ($this->files('images') as $file) {
            try {
                [$path, $thumb] = Images::store((string) $file->getTempName());
                $images->insert(['product_id' => $productId, 'path' => $path, 'thumb_path' => $thumb, 'sort' => $sort++]);
            } catch (Throwable $e) {
                $warnings[] = $file->getClientName() . ': ' . $e->getMessage();
            }
        }

        return $warnings;
    }

    /** The product as the edit form uses it (fresh from the database). */
    private function present(int $id): array
    {
        $products = new ProductModel();
        $p        = $products->find($id);

        return [
            'id'          => (int) $p['id'],
            'name'        => $p['name'],
            'slug'        => $p['slug'],
            'category_id' => (int) $p['category_id'],
            'description' => $p['description'] ?? '',
            'price'       => (float) $p['price'],
            'sale_price'  => $p['sale_price'] !== null ? (float) $p['sale_price'] : null,
            'is_active'   => (bool) $p['is_active'],
            'is_featured' => (bool) $p['is_featured'],
            'images'      => array_map(static fn (array $i): array => [
                'id'    => (int) $i['id'],
                'src'   => '/' . $i['path'],
                'thumb' => '/' . $i['thumb_path'],
            ], $products->sortedImages($id)),
        ];
    }
}
