<?php

use think\migration\Migrator;

class LinodeAccountProxy extends Migrator
{
    public function change()
    {
        if (! $this->hasTable('linode')) {
            return;
        }

        $table = $this->table('linode');
        if (! $table->hasColumn('proxy_id')) {
            $table->addColumn('proxy_id', 'integer', [
                'default' => 0,
                'after' => 'token',
                'comment' => 'Bound proxy id (0=none, -1=default pool)',
            ])->update();
        }
    }
}
