<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Linode extends Model
{
    protected $pk = 'id';
    protected $table = 'linode';
}
