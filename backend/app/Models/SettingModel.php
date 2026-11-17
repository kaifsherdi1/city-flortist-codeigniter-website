<?php

namespace App\Models;

use CodeIgniter\Model;

/** Key/value shop settings. Read and write them through App\Libraries\Settings. */
class SettingModel extends Model
{
    protected $table            = 'settings';
    protected $primaryKey       = 'name';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = ['name', 'value'];
    protected $useTimestamps    = false;
}
