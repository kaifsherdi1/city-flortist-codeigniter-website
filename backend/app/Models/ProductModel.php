<?php

namespace App\Models;

use App\Libraries\Text;
use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table         = 'products';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['category_id', 'name', 'slug', 'description', 'price', 'sale_price', 'is_featured', 'is_active', 'sort'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected array $casts   = [
        'id'          => 'int',
        'category_id' => 'int',
        'price'       => 'float',
        'sale_price'  => '?float',
        'is_featured' => 'bool',
        'is_active'   => 'bool',
        'sort'        => 'int',
    ];

    /** A slug from the name that no other product uses. */
    public function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base      = Text::slugify($name);
        $candidate = $base;
        for ($i = 2; $this->slugTaken($candidate, $ignoreId); $i++) {
            $candidate = "{$base}-{$i}";
        }

        return $candidate;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        $q = $this->builder()->where('slug', $slug);
        if ($ignoreId) {
            $q->where('id <>', $ignoreId);
        }

        return $q->countAllResults() > 0;
    }

    /** Photos in display order; the first one is the main photo. */
    public function sortedImages(int $productId): array
    {
        return (new ProductImageModel())->where('product_id', $productId)->orderBy('sort')->orderBy('id')->findAll();
    }
}
