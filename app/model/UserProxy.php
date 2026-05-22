<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class UserProxy extends Model
{
    protected $pk = 'id';
    protected $table = 'user_proxy';
}
