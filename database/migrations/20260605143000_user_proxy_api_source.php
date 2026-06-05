<?php

use think\migration\Migrator;

class UserProxyApiSource extends Migrator
{
    public function change()
    {
        $table = $this->table('user_proxy');
        if (! $table->hasColumn('source_type')) {
            $table->addColumn('source_type', 'string', [
                'limit' => 16,
                'default' => 'manual',
                'after' => 'protocol',
                'comment' => 'Proxy source type',
            ])->update();
        }

        if (! $table->hasColumn('api_url')) {
            $table->addColumn('api_url', 'text', [
                'null' => true,
                'after' => 'source_type',
                'comment' => 'Proxy API URL',
            ])->update();
        }
    }
}
