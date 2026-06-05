<?php

use think\migration\db\Column;
use think\migration\Migrator;

class UserProxyTable extends Migrator
{
    public function change()
    {
        if ($this->hasTable('user_proxy')) {
            return;
        }

        $table = $this->table('user_proxy');
        $table->addColumn('user_id', 'integer', ['comment' => 'Owner user id'])
            ->addColumn('name', 'string', ['limit' => 128, 'comment' => 'Proxy name'])
            ->addColumn('address', 'string', ['limit' => 255, 'comment' => 'SOCKS5 host'])
            ->addColumn('port', 'integer', ['comment' => 'SOCKS5 port'])
            ->addColumn('username', 'string', ['limit' => 255, 'default' => '', 'comment' => 'SOCKS5 username'])
            ->addColumn('password', 'string', ['limit' => 255, 'default' => '', 'comment' => 'SOCKS5 password'])
            ->addColumn('enabled', 'integer', ['default' => 1, 'comment' => 'Enabled flag'])
            ->addColumn('is_default', 'integer', ['default' => 0, 'comment' => 'Default proxy flag'])
            ->addColumn('created_at', 'integer', ['comment' => 'Created time'])
            ->addColumn('updated_at', 'integer', ['comment' => 'Updated time'])
            ->addIndex(['user_id', 'enabled'])
            ->addIndex(['user_id', 'is_default'])
            ->create();
    }
}
