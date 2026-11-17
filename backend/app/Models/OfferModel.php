<?php

namespace App\Models;

use CodeIgniter\Model;

class OfferModel extends Model
{
    protected $table         = 'offers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['title', 'subtitle', 'discount_pct', 'product_id', 'category_id', 'image', 'starts_at', 'ends_at', 'is_active'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected array $casts   = [
        'id'           => 'int',
        'discount_pct' => 'int',
        'product_id'   => '?int',
        'category_id'  => '?int',
        'is_active'    => 'bool',
    ];

    /** live, scheduled, ended or paused. */
    public static function state(array $offer): string
    {
        if (! $offer['is_active']) {
            return 'paused';
        }
        $now = microtime(true); // Carbon compared with microseconds: an offer ending at 23:59:00 is over at 23:59:00.5
        if (strtotime((string) $offer['ends_at']) < $now) {
            return 'ended';
        }

        return strtotime((string) $offer['starts_at']) > $now ? 'scheduled' : 'live';
    }
}
