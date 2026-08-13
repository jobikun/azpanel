<?php

use think\migration\Migrator;

class AlibabaAccountDohEndpoint extends Migrator
{
    public function up(): void
    {
        if (! $this->hasTable('alibaba_account')) { return; }
        $table = $this->table('alibaba_account');
        if (! $table->hasColumn('doh_endpoint')) {
            $table->addColumn('doh_endpoint', 'string', [
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
        $table = $this->table('alibaba_account');
        if ($table->hasColumn('doh_endpoint')) {
            $table->removeColumn('doh_endpoint')->update();
        }
    }
}
