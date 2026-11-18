<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Libraries\Images;
use App\Libraries\Text;
use App\Models\OfferModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Dashboard → Daily Offers: discounts (or plain announcements) on everything, one category or one product,
 * shown in the home page banner. Same answers as the Laravel version's Admin\OfferController.
 */
class Offers extends ApiController
{
    /** GET /api/admin/offers: every offer, plus what an offer can apply to. */
    public function index(): ResponseInterface
    {
        $db     = db_connect();
        $offers = (new OfferModel())->orderBy('ends_at', 'DESC')->findAll();

        // The names of what each offer applies to (Laravel: with(['product:id,name', 'category:id,name'])).
        $productNames  = $this->names('products', array_column($offers, 'product_id'));
        $categoryNames = $this->names('categories', array_column($offers, 'category_id'));

        $categories = $db->table('categories')->select('id, name')->orderBy('sort')->orderBy('id')->get()->getResultArray();
        $products   = $db->table('products')->select('id, name')->where('is_active', 1)->orderBy('name')->get()->getResultArray();
        $idName     = static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']];

        return $this->json([
            'offers' => array_map(fn (array $o): array => $this->present(
                $o,
                $o['product_id'] ? ($productNames[(int) $o['product_id']] ?? null) : null,
                $o['category_id'] ? ($categoryNames[(int) $o['category_id']] ?? null) : null,
            ), $offers),
            'categories' => array_map($idName, $categories),
            'products'   => array_map($idName, $products),
        ]);
    }

    /** POST /api/admin/offers (multipart, optional image) */
    public function store(): ResponseInterface
    {
        $data    = $this->validated();
        $warning = $this->storeImage($data, null);
        (new OfferModel())->insert($data);

        return $this->json(['message' => 'Offer published.', 'warnings' => array_values(array_filter([$warning]))], 201);
    }

    /** POST /api/admin/offers/{offer} (multipart, optional image) */
    public function update(int|string $id): ResponseInterface
    {
        $offer   = $this->offer((int) $id);
        $data    = $this->validated();
        $warning = $this->storeImage($data, $offer['image']);
        (new OfferModel())->update((int) $offer['id'], $data);

        return $this->json(['message' => 'Offer updated.', 'warnings' => array_values(array_filter([$warning]))]);
    }

    /** POST /api/admin/offers/{offer}/toggle: pause or resume. */
    public function toggle(int|string $id): ResponseInterface
    {
        $offer  = $this->offer((int) $id);
        $active = ! $offer['is_active'];
        (new OfferModel())->update((int) $offer['id'], ['is_active' => $active]);

        return $this->json(['message' => $active ? 'Offer resumed.' : 'Offer paused.']);
    }

    /** DELETE /api/admin/offers/{offer} */
    public function destroy(int|string $id): ResponseInterface
    {
        $offer = $this->offer((int) $id);
        Images::delete($offer['image']);
        (new OfferModel())->delete((int) $offer['id']);

        return $this->json(['message' => 'Offer deleted.']);
    }

    // ------------------------------------------------------------------------------------------------------

    /** The offer, or 404 "Not found." (Laravel's route model binding). */
    private function offer(int $id): array
    {
        $offer = (new OfferModel())->find($id);
        if (! is_array($offer)) {
            $this->missing();
        }

        return $offer;
    }

    private function validated(): array
    {
        [$type, $id] = array_pad(explode(':', (string) $this->input('target', 'all')), 2, 0);
        $starts      = strtotime((string) $this->input('starts_at', 'now')) ?: time();
        $ends        = strtotime((string) $this->input('ends_at', 'today 23:59')) ?: strtotime('today 23:59');

        $data = [
            'title'        => $this->str('title', 160),
            'subtitle'     => $this->str('subtitle'),
            'discount_pct' => max(0, min(90, (int) $this->input('discount_pct', 0))),
            'product_id'   => $type === 'product' && $this->exists('products', (int) $id) ? (int) $id : null,
            'category_id'  => $type === 'category' && $this->exists('categories', (int) $id) ? (int) $id : null,
            'starts_at'    => date('Y-m-d H:i:s', $starts),
            'ends_at'      => date('Y-m-d H:i:s', $ends),
            'is_active'    => $this->flag('is_active'),
        ];
        if ($data['title'] === '') {
            $this->fail('Please give the offer a title.');
        }
        // An unknown target must never quietly turn into a discount on everything.
        if (($type === 'product' && ! $data['product_id']) || ($type === 'category' && ! $data['category_id'])) {
            $this->fail('That product or category no longer exists. Please choose what the offer is for again.');
        }
        if (! in_array($type, ['all', 'product', 'category'], true)) {
            $this->fail('Please choose what the offer is for.');
        }
        if ($ends <= $starts) {
            $this->fail('The end time must be after the start time.');
        }

        return $data;
    }

    private function exists(string $table, int $id): bool
    {
        return db_connect()->table($table)->where('id', $id)->countAllResults() > 0;
    }

    /**
     * id → name for the given ids of a table.
     *
     * @param list<mixed> $ids
     *
     * @return array<int, string>
     */
    private function names(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = db_connect()->table($table)->select('id, name')->whereIn('id', $ids)->get()->getResultArray();

        return array_column($rows, 'name', 'id');
    }

    /**
     * Saves a new banner photo (replacing the old one) into $data['image']. Returns a warning if the photo
     * couldn't be used.
     */
    private function storeImage(array &$data, ?string $current): ?string
    {
        $file = $this->files('image')[0] ?? null;
        if (! $file) {
            return $this->uploadWarnings('image')[0] ?? null;
        }

        try {
            // Banners don't use a thumbnail: it's deleted straight away, with the photo this one replaces.
            [$path, $thumb] = Images::store((string) $file->getTempName(), 'offers');
            Images::delete($thumb, $current);
            $data['image'] = $path;
        } catch (Throwable $e) {
            return 'Image: ' . $e->getMessage();
        }

        return null;
    }

    private function present(array $o, ?string $productName, ?string $categoryName): array
    {
        return [
            'id'           => (int) $o['id'],
            'title'        => $o['title'],
            'subtitle'     => $o['subtitle'] ?? '',
            'discount_pct' => (int) $o['discount_pct'],
            'target'       => $o['product_id'] ? 'product:' . $o['product_id'] : ($o['category_id'] ? 'category:' . $o['category_id'] : 'all'),
            'applies_to'   => $productName ?: ($categoryName ?: 'Everything'),
            'starts_at'    => date('Y-m-d\TH:i', strtotime((string) $o['starts_at'])),
            'ends_at'      => date('Y-m-d\TH:i', strtotime((string) $o['ends_at'])),
            'is_active'    => (bool) $o['is_active'],
            'image'        => Text::publicPath($o['image']),
            'state'        => OfferModel::state($o),
        ];
    }
}
