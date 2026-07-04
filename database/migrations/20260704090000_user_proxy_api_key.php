<?php

use think\migration\Migrator;

class UserProxyApiKey extends Migrator
{
    public function change()
    {
        if (! $this->hasTable('user_proxy')) {
            return;
        }

        $table = $this->table('user_proxy');
        if (! $table->hasColumn('api_key')) {
            $table->addColumn('api_key', 'string', [
                'limit' => 255,
                'default' => '',
                'after' => 'api_url',
                'comment' => 'Proxy API key',
            ])->update();
        }
    }
}
