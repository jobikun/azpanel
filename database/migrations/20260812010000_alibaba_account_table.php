<?php

use think\migration\Migrator;

class AlibabaAccountTable extends Migrator
{
    public function up(): void
    {
        if (! $this->hasTable('alibaba_account')) {
            $this->table('alibaba_account')
                ->addColumn('user_id', 'integer', ['comment' => 'Owner user ID'])
                ->addColumn('name', 'string', ['limit' => 128, 'comment' => 'Account label'])
                ->addColumn('access_key_id', 'string', ['limit' => 128, 'comment' => 'Alibaba Cloud AccessKey ID'])
                ->addColumn('access_key_secret', 'string', ['limit' => 255, 'comment' => 'Alibaba Cloud AccessKey Secret'])
                ->addColumn('proxy_id', 'integer', ['default' => 0, 'comment' => 'Bound proxy ID (0=none, -1=default)'])
                ->addColumn('remark', 'text', ['null' => true, 'comment' => 'Remark'])
                ->addColumn('created_at', 'integer', ['default' => 0])
                ->addColumn('updated_at', 'integer', ['default' => 0])
                ->addIndex(['user_id'])
                ->addIndex(['user_id', 'access_key_id'], ['unique' => true, 'name' => 'uniq_user_alibaba_ak'])
                ->create();
        }

        if ($this->hasTable('config')) {
            $values = [];
            foreach ($this->fetchAll("SELECT item, value FROM config WHERE item IN ('ali_ak', 'ali_sk', 'ali_httpdns_proxy_id', 'ali_httpdns_proxy_user_id')") as $row) {
                $values[(string) $row['item']] = (string) $row['value'];
            }

            $accessKeyId = trim($values['ali_ak'] ?? '');
            $accessKeySecret = trim($values['ali_sk'] ?? '');
            if ($accessKeyId !== '' && $accessKeySecret !== '' && $this->hasTable('user')) {
                $ownerId = (int) ($values['ali_httpdns_proxy_user_id'] ?? 0);
                $owners = $ownerId > 0
                    ? $this->fetchAll('SELECT id FROM `user` WHERE id = ' . $ownerId . ' LIMIT 1')
                    : [];
                if ($owners === []) {
                    $owners = $this->fetchAll('SELECT id FROM `user` WHERE is_admin = 1 ORDER BY id LIMIT 1');
                }
                if ($owners === []) {
                    $owners = $this->fetchAll('SELECT id FROM `user` ORDER BY id LIMIT 1');
                }

                $existing = array_column($this->fetchAll('SELECT access_key_id FROM alibaba_account'), 'access_key_id');
                if ($owners !== [] && !in_array($accessKeyId, $existing, true)) {
                    $this->table('alibaba_account')->insert([
                        'user_id' => (int) $owners[0]['id'],
                        'name' => 'Migrated global Alibaba Cloud account',
                        'access_key_id' => $accessKeyId,
                        'access_key_secret' => $accessKeySecret,
                        'proxy_id' => (int) ($values['ali_httpdns_proxy_id'] ?? 0),
                        'remark' => 'Automatically migrated from admin DNS settings',
                        'created_at' => time(),
                        'updated_at' => time(),
                    ])->saveData();
                }
            }

            $this->execute("DELETE FROM config WHERE item IN ('ali_httpdns_proxy_id', 'ali_httpdns_proxy_user_id')");
        }
    }

    public function down(): void
    {
        if ($this->hasTable('alibaba_account')) {
            $this->table('alibaba_account')->drop()->save();
        }
    }
}
