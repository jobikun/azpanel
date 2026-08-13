<?php

use think\migration\Migrator;

class AlibabaAccountDohEndpoint extends Migrator
{
    public function up(): void
    {
        if (! $this->hasTable('alibaba_account')) { return; }
        if (! $this->hasColumn('alibaba_account', 'doh_endpoint')) {
            $this->table('alibaba_account')->addColumn('doh_endpoint', 'string', [
                'limit' => 255,
                'default' => '',
                'comment' => 'Saved Alibaba HTTPDNS encrypted DoH endpoint',
                'after' => 'access_key_secret',
            ])->update();
        }
    }

    public function down(): void
    {
        if (! $this->hasTable('alibaba_account')) { return; }
        if ($this->hasColumn('alibaba_account', 'doh_endpoint')) {
            $this->table('alibaba_account')->removeColumn('doh_endpoint')->update();
        }
    }
}
