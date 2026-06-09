<?php

use think\migration\Migrator;

class AwsServerCacheTable extends Migrator
{
    public function change()
    {
        if ($this->hasTable('aws_server')) {
            return;
        }

        $table = $this->table('aws_server', ['comment' => 'AWS 实例缓存（供 cloudflare_dns 拉取）']);
        $table->addColumn('resource_key', 'string', ['limit' => 191, 'comment' => '唯一键 provider|account|region|instance|ipver'])
            ->addColumn('account_id', 'integer', ['default' => 0, 'comment' => '归属 AWS 账户 id'])
            ->addColumn('region', 'string', ['limit' => 64, 'default' => '', 'comment' => '区域'])
            ->addColumn('instance_id', 'string', ['limit' => 128, 'default' => '', 'comment' => '实例 id'])
            ->addColumn('name', 'string', ['limit' => 191, 'default' => '', 'comment' => '名称'])
            ->addColumn('ip_version', 'string', ['limit' => 8, 'default' => 'ipv4', 'comment' => 'ipv4/ipv6'])
            ->addColumn('current_ip', 'string', ['limit' => 64, 'default' => '', 'comment' => '当前公网 IP'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => '', 'comment' => '实例状态'])
            ->addColumn('instance_type', 'string', ['limit' => 64, 'default' => '', 'comment' => '实例规格'])
            ->addColumn('remark', 'string', ['limit' => 191, 'default' => '', 'comment' => '备注'])
            ->addColumn('last_seen_at', 'integer', ['default' => 0, 'comment' => '最近一次同步时间'])
            ->addColumn('created_at', 'integer', ['default' => 0, 'comment' => '创建时间'])
            ->addColumn('updated_at', 'integer', ['default' => 0, 'comment' => '更新时间'])
            ->addIndex(['resource_key'], ['unique' => true, 'name' => 'uniq_resource_key'])
            ->addIndex(['account_id', 'region'], ['name' => 'idx_account_region'])
            ->create();
    }
}
