<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table         = 'categories';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['slug', 'name', 'tagline', 'cover_image', 'sort'];
    protected $useTimestamps = false;
    protected array $casts   = ['id' => 'int', 'sort' => 'int'];
}
