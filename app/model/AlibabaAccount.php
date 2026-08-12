<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AlibabaAccount extends Model
{
    protected $pk = 'id';
    protected $table = 'alibaba_account';
}
