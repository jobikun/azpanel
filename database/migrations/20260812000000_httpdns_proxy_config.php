<?php

use think\migration\Migrator;

class HttpdnsProxyConfig extends Migrator
{
    public function up(): void
    {
        if (! $this->hasTable('config')) {
            return;
        }

        foreach (['ali_httpdns_proxy_id', 'ali_httpdns_proxy_user_id'] as $item) {
            $rows = $this->fetchAll("SELECT id FROM config WHERE item = '" . $item . "' LIMIT 1");
            if ($rows === []) {
                $this->table('config')->insert([
                    'item' => $item,
                    'value' => '0',
                    'class' => 'resolv',
                    'default_value' => '0',
                    'type' => 'int',
                ])->saveData();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('config')) {
            $this->execute("DELETE FROM config WHERE item IN ('ali_httpdns_proxy_id', 'ali_httpdns_proxy_user_id')");
        }
    }
}
