<?php
declare(strict_types=1);

namespace app\command;

use app\controller\InternalCloudflareDns;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

class syncAwsResources extends Command
{
    protected function configure()
    {
        $this->setName('syncAwsResources')
            ->setDescription('Refresh AWS instance cache (aws_server) for cloudflare_dns');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            $count = InternalCloudflareDns::syncAwsResourceCache([]);
            $output->writeln("<info>AWS resource cache refreshed, {$count} entries.</info>");
        } catch (\Throwable $e) {
            Log::write($e->getMessage(), 'aws_sync_error');
            $output->writeln('<error>AWS resource sync failed: ' . $e->getMessage() . '</error>');
        }
    }
}
