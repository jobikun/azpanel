<?php

use think\migration\Migrator;

class AwsServerTable extends Migrator
{
    public function change()
    {
        $table = $this->table('aws_server');
        $table->addColumn('resource_key', 'string', ['limit' => 512, 'comment' => 'Cloudflare DNS resource key'])
            ->addColumn('account_id', 'integer', ['comment' => 'AWS account ID'])
            ->addColumn('region', 'string', ['limit' => 120, 'comment' => 'AWS region'])
            ->addColumn('instance_id', 'string', ['limit' => 120, 'comment' => 'AWS instance ID'])
            ->addColumn('name', 'string', ['limit' => 255, 'default' => '', 'comment' => 'Instance name'])
            ->addColumn('ip_version', 'string', ['limit' => 10, 'default' => 'ipv4', 'comment' => 'ipv4 / ipv6'])
            ->addColumn('current_ip', 'string', ['limit' => 120, 'default' => '', 'comment' => 'Current public IP'])
            ->addColumn('status', 'string', ['limit' => 40, 'default' => '', 'comment' => 'Instance state'])
            ->addColumn('instance_type', 'string', ['limit' => 80, 'default' => '', 'comment' => 'Instance type'])
            ->addColumn('remark', 'text', ['null' => true, 'comment' => 'Remark'])
            ->addColumn('last_seen_at', 'integer', ['default' => 0, 'comment' => 'Last loaded time'])
            ->addColumn('created_at', 'integer', ['default' => 0, 'comment' => 'Created time'])
            ->addColumn('updated_at', 'integer', ['default' => 0, 'comment' => 'Updated time'])
            ->addIndex(['resource_key'], ['unique' => true, 'name' => 'uniq_aws_server_resource_key'])
            ->addIndex(['account_id', 'region'])
            ->create();
    }
}
