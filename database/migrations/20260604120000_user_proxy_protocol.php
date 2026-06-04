<?php

use think\migration\Migrator;

class UserProxyProtocol extends Migrator
{
    public function change()
    {
        $table = $this->table('user_proxy');
        if (! $table->hasColumn('protocol')) {
            $table->addColumn('protocol', 'string', [
                'limit' => 16,
                'default' => 'socks5',
                'after' => 'name',
                'comment' => 'Proxy protocol',
            ])->update();
        }
    }
}
