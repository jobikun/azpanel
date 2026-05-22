<?php

use think\migration\Migrator;

class LinodeAccountTable extends Migrator
{
    public function change()
    {
        $table = $this->table('linode');
        $table->addColumn('user_id', 'integer', ['comment' => 'Owner user id'])
            ->addColumn('label', 'string', ['limit' => 128, 'comment' => 'Account label'])
            ->addColumn('token', 'text', ['comment' => 'Linode personal access token'])
            ->addColumn('email', 'string', ['limit' => 255, 'default' => '', 'comment' => 'Linode profile email'])
            ->addColumn('username', 'string', ['limit' => 255, 'default' => '', 'comment' => 'Linode profile username'])
            ->addColumn('mark', 'text', ['null' => true, 'comment' => 'Remark'])
            ->addColumn('disable', 'integer', ['default' => 0, 'comment' => 'Disabled flag'])
            ->addColumn('created_at', 'integer', ['comment' => 'Created time'])
            ->addColumn('updated_at', 'integer', ['comment' => 'Updated time'])
            ->addIndex(['user_id', 'disable'])
            ->create();
    }
}
