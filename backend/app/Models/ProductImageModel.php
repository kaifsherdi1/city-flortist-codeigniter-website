<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductImageModel extends Model
{
    protected $table         = 'product_images';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['product_id', 'path', 'thumb_path', 'sort'];
    protected $useTimestamps = false;
    protected array $casts   = ['id' => 'int', 'product_id' => 'int', 'sort' => 'int'];
}
