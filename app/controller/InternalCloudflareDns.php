<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Aws;
use app\model\AwsServer;
use app\model\Azure;
use app\model\AzureServer;
use app\model\Config;
use think\helper\Str;

class InternalCloudflareDns extends BaseController
{
    public function changeIp()
    {
        $payload = $this->payload();
        $auth_error = $this->authError($payload);
        if ($auth_error !== null) {
            return json([
                'status' => 'failed',
                'message' => $auth_error['message'],
            ], $auth_error['code']);
        }

        try {
            $provider = strtolower(trim((string) ($payload['provider'] ?? '')));
            $ip_version = $this->normalizeIpVersion((string) ($payload['ip_version'] ?? 'ipv4'));

            if ($provider === 'azure') {
                $data = $this->changeAzure($payload, $ip_version);
            } elseif ($provider === 'aws') {
                $data = $this->changeAws($payload, $ip_version);
            } else {
                throw new \InvalidArgumentException('Unsupported provider: ' . ($provider === '' ? 'empty' : $provider));
            }

            return json([
                'status' => 'success',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            $code = $e instanceof \InvalidArgumentException ? 400 : 500;
            return json([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    public function resources()
    {
        $payload = $this->payload();
        $auth_error = $this->authError($payload);
        if ($auth_error !== null) {
            return json([
                'status' => 'failed',
                'message' => $auth_error['message'],
            ], $auth_error['code']);
        }

        try {
            $provider = strtolower(trim((string) ($payload['provider'] ?? 'all')));
            $refresh = $this->isTruthy($payload['refresh'] ?? null);
            if (!in_array($provider, ['all', '', 'azure', 'aws'], true)) {
                throw new \InvalidArgumentException('Unsupported provider: ' . $provider);
            }

            $resources = [];
            if ($provider === 'all' || $provider === '' || $provider === 'azure') {
                $resources = array_merge($resources, $this->listAzureResources());
            }
            if ($provider === 'all' || $provider === '' || $provider === 'aws') {
                // 默认只读缓存表，秒回；仅当 refresh=1 时才实时拉取 AWS（慢，会走代理）
                if ($refresh) {
                    $this->syncAwsResourceCache($payload);
                }
                $requested_account = (int) ($payload['account_id'] ?? 0);
                $requested_region = trim((string) ($payload['region'] ?? $payload['location'] ?? ''));
                $resources = array_merge($resources, $this->listCachedAwsResources($requested_account, $requested_region));
            }

            return json([
                'status' => 'success',
                'data' => [
                    'resources' => $resources,
                ],
            ]);
        } catch (\Throwable $e) {
            $code = $e instanceof \InvalidArgumentException ? 400 : 500;
            return json([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    private function listAzureResources(): array
    {
        $items = [];
        $servers = AzureServer::order('id', 'desc')->select();
        foreach ($servers as $server) {
            $ip = trim((string) ($server->ip_address ?? ''));
            $items[] = [
                'key' => 'azure|' . (string) ($server->account_id ?? '') . '|' . (string) ($server->location ?? '') . '|' . (string) ($server->vm_id ?? '') . '|ipv4',
                'provider' => 'azure',
                'name' => (string) ($server->name ?? $server->vm_id ?? 'Azure VM'),
                'resource_id' => (string) ($server->vm_id ?? ''),
                'account_id' => (string) ($server->account_id ?? ''),
                'region' => (string) ($server->location ?? ''),
                'ip_version' => 'ipv4',
                'current_ip' => $ip === 'null' ? '' : $ip,
                'status' => (string) ($server->status ?? ''),
                'remark' => (string) ($server->resource_group ?? ''),
                'port' => 22,
            ];
        }
        return $items;
    }

    /**
     * 实时拉取 AWS 实例并写入 aws_server 缓存表。
     * 慢操作（遍历账户×区域，可能走代理），由 refresh 参数或定时命令触发，不在普通列表请求里执行。
     *
     * @return int 本次同步到的实例条目数
     */
    public static function syncAwsResourceCache(array $payload = []): int
    {
        $requested_account = (int) ($payload['account_id'] ?? 0);
        $requested_region = trim((string) ($payload['region'] ?? $payload['location'] ?? ''));
        $accounts = Aws::where('disable', 0)->order('id', 'desc')->select();
        $regions = $requested_region !== '' ? [$requested_region => $requested_region] : AwsList::instanceRegion();
        $proxy_url = self::proxyUrl($payload);
        $synced = 0;

        foreach ($accounts as $account) {
            if ($requested_account > 0 && (int) $account->id !== $requested_account) {
                continue;
            }
            foreach ($regions as $region => $_label) {
                try {
                    $client = AwsApi::createAWSClient($region, $account->ak, $account->sk, $proxy_url !== null && $proxy_url !== '', 'ec2', $proxy_url);
                    $result = $client->describeInstances();
                } catch (\Throwable $e) {
                    continue;
                }

                foreach (($result['Reservations'] ?? []) as $reservation) {
                    foreach (($reservation['Instances'] ?? []) as $instance) {
                        $state = (string) ($instance['State']['Name'] ?? 'unknown');
                        if ($state === 'terminated' || $state === 'shutting-down') {
                            continue;
                        }
                        $instance_id = (string) ($instance['InstanceId'] ?? '');
                        if ($instance_id === '') {
                            continue;
                        }
                        $name = self::awsInstanceName($instance, $instance_id);
                        $public_ipv4 = self::awsInstancePublicIpv4($instance);
                        if ($public_ipv4 !== '') {
                            self::persistAwsResource([
                                'key' => 'aws|' . (string) $account->id . '|' . $region . '|' . $instance_id . '|ipv4',
                                'name' => $name,
                                'resource_id' => $instance_id,
                                'account_id' => (string) $account->id,
                                'region' => $region,
                                'ip_version' => 'ipv4',
                                'current_ip' => $public_ipv4,
                                'status' => $state,
                                'remark' => (string) ($instance['InstanceType'] ?? ''),
                            ]);
                            $synced++;
                        }
                        foreach (self::awsInstanceIpv6Addresses($instance) as $ipv6) {
                            self::persistAwsResource([
                                'key' => 'aws|' . (string) $account->id . '|' . $region . '|' . $instance_id . '|ipv6|' . $ipv6,
                                'name' => $name . ' IPv6',
                                'resource_id' => $instance_id,
                                'account_id' => (string) $account->id,
                                'region' => $region,
                                'ip_version' => 'ipv6',
                                'current_ip' => $ipv6,
                                'status' => $state,
                                'remark' => (string) ($instance['InstanceType'] ?? ''),
                            ]);
                            $synced++;
                        }
                    }
                }
            }
        }

        return $synced;
    }

    private static function persistAwsResource(array $item): void
    {
        try {
            $resource_key = (string) ($item['key'] ?? '');
            if ($resource_key === '') {
                return;
            }
            $now = time();
            $server = AwsServer::where('resource_key', $resource_key)->find();
            if ($server === null) {
                $server = new AwsServer();
                $server->resource_key = $resource_key;
                $server->created_at = $now;
            }
            $server->account_id = (int) ($item['account_id'] ?? 0);
            $server->region = (string) ($item['region'] ?? '');
            $server->instance_id = (string) ($item['resource_id'] ?? '');
            $server->name = (string) ($item['name'] ?? $server->instance_id);
            $server->ip_version = (string) ($item['ip_version'] ?? 'ipv4');
            $server->current_ip = (string) ($item['current_ip'] ?? '');
            $server->status = (string) ($item['status'] ?? '');
            $server->instance_type = (string) ($item['remark'] ?? '');
            $server->remark = (string) ($item['remark'] ?? '');
            $server->last_seen_at = $now;
            $server->updated_at = $now;
            $server->save();
        } catch (\Throwable $e) {
            // The endpoint must still work before the aws_server migration is applied.
        }
    }

    private function listCachedAwsResources(int $requested_account = 0, string $requested_region = ''): array
    {
        try {
            $query = AwsServer::order('last_seen_at', 'desc');
            if ($requested_account > 0) {
                $query = $query->where('account_id', $requested_account);
            }
            if ($requested_region !== '') {
                $query = $query->where('region', $requested_region);
            }
            $servers = $query->select();
        } catch (\Throwable $e) {
            return [];
        }

        $items = [];
        foreach ($servers as $server) {
            $key = (string) ($server->resource_key ?? '');
            if ($key === '') {
                continue;
            }
            $items[] = [
                'key' => $key,
                'provider' => 'aws',
                'name' => (string) ($server->name ?? $server->instance_id ?? 'AWS Instance'),
                'resource_id' => (string) ($server->instance_id ?? ''),
                'account_id' => (string) ($server->account_id ?? ''),
                'region' => (string) ($server->region ?? ''),
                'ip_version' => (string) ($server->ip_version ?? 'ipv4'),
                'current_ip' => (string) ($server->current_ip ?? ''),
                'status' => (string) ($server->status ?? ''),
                'remark' => (string) ($server->instance_type ?? $server->remark ?? ''),
                'port' => 22,
                'cached' => true,
            ];
        }
        return $items;
    }

    private static function awsInstanceName($instance, string $fallback): string
    {
        foreach (($instance['Tags'] ?? []) as $tag) {
            if (($tag['Key'] ?? '') === 'Name' && trim((string) ($tag['Value'] ?? '')) !== '') {
                return trim((string) $tag['Value']);
            }
        }
        return $fallback;
    }

    private static function awsInstancePublicIpv4($instance): string
    {
        $ip = trim((string) ($instance['PublicIpAddress'] ?? ''));
        if ($ip !== '') {
            return $ip;
        }
        foreach (($instance['NetworkInterfaces'] ?? []) as $interface) {
            $ip = trim((string) ($interface['Association']['PublicIp'] ?? ''));
            if ($ip !== '') {
                return $ip;
            }
        }
        return '';
    }

    private static function awsInstanceIpv6Addresses($instance): array
    {
        $items = [];
        foreach (($instance['NetworkInterfaces'] ?? []) as $interface) {
            foreach (($interface['Ipv6Addresses'] ?? []) as $address) {
                $ip = trim((string) ($address['Ipv6Address'] ?? ''));
                if ($ip !== '') {
                    $items[$ip] = true;
                }
            }
        }
        return array_keys($items);
    }
    private function payload(): array
    {
        $params = request()->param();
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $params = array_merge($params, $decoded);
            }
        }

        return is_array($params) ? $params : [];
    }

    private function authError(array $payload): ?array
    {
        $expected = $this->expectedToken();
        if ($expected === '') {
            return [
                'code' => 500,
                'message' => 'CLOUDFLARE_DNS_INTERNAL_TOKEN is not configured in azpanel.',
            ];
        }

        $authorization = (string) request()->header('authorization', request()->header('Authorization', ''));
        $candidates = [];
        if (preg_match('/Bearer\s+(.+)/i', $authorization, $match)) {
            $candidates[] = trim($match[1]);
        }
        $candidates[] = trim((string) request()->header('x-cloudflare-dns-token', request()->header('X-Cloudflare-Dns-Token', '')));
        $candidates[] = trim((string) ($payload['token'] ?? ''));

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && hash_equals($expected, $candidate)) {
                return null;
            }
        }

        return [
            'code' => 401,
            'message' => 'Unauthorized internal API token.',
        ];
    }

    private function expectedToken(): string
    {
        foreach (['CLOUDFLARE_DNS_INTERNAL_TOKEN', 'CLOUDFLARE_DNS_TOKEN'] as $key) {
            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        try {
            foreach (['cloudflare_dns.internal_token', 'cloudflare_dns_internal_token', 'cloudflare_dns.token'] as $key) {
                $value = env($key, '');
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $row = Config::where('item', 'cloudflare_dns_internal_token')->find();
            if ($row !== null && trim((string) $row->value) !== '') {
                return trim((string) $row->value);
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    private function normalizeIpVersion(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === '4' || $value === 'v4' || $value === 'ipv4') {
            return 'ipv4';
        }
        if ($value === '6' || $value === 'v6' || $value === 'ipv6') {
            return 'ipv6';
        }

        throw new \InvalidArgumentException('Unsupported ip_version: ' . $value);
    }

    private function changeAzure(array $payload, string $ip_version): array
    {
        if ($ip_version !== 'ipv4') {
            throw new \InvalidArgumentException('Azure change-ip currently supports IPv4 only.');
        }

        $resource_id = trim((string) ($payload['resource_id'] ?? $payload['vm_id'] ?? ''));
        if ($resource_id === '') {
            throw new \InvalidArgumentException('Azure resource_id(vm_id) is required.');
        }

        $server = AzureServer::where('vm_id', $resource_id)->find();
        if ($server === null && ctype_digit($resource_id)) {
            $server = AzureServer::find((int) $resource_id);
        }
        if ($server === null) {
            throw new \RuntimeException('Azure server not found: ' . $resource_id);
        }

        $old_ip = (string) ($server->ip_address ?? '');
        $proxy_url = self::proxyUrl($payload);
        $client = ProxyController::createGuzzleClient($proxy_url, [], false);

        $sub_info = AzureApi::getAzureSubscription($server->account_id, $client);
        if (($sub_info['value'][0]['state'] ?? '') !== 'Enabled') {
            throw new \RuntimeException('Azure subscription is not Enabled.');
        }

        $account = Azure::find($server->account_id);
        if ($account === null) {
            throw new \RuntimeException('Azure account not found: ' . $server->account_id);
        }

        $resource_group = $server->resource_group;
        $network_details = AzureApi::getAzureNetworkInterfacesDetails(
            $server->account_id,
            $server->network_interfaces,
            $resource_group,
            $server->at_subscription_id,
            $client
        );

        $security_group_id = $network_details['properties']['networkSecurityGroup']['id'] ?? '';
        if ($security_group_id === '') {
            $security_group_id = AzureApi::createNetworkSecurityGroups(
                $client,
                $account,
                $resource_group,
                $server->location,
                $server->name . '_security'
            );
        }

        $new_ip_id = AzureApi::createAzurePublicNetworkIpv4(
            $client,
            $account,
            Str::substr($server->name, 0, 54) . '_ip4c_' . date('ymdHis'),
            $resource_group,
            $server->location,
            true
        );

        sleep(5);
        $old_ip_id = AzureApi::replacePrimaryNetworkInterfaceIpv4(
            $client,
            $account,
            $resource_group,
            $server->network_interfaces,
            $new_ip_id,
            $server->location,
            $security_group_id
        );

        sleep(3);
        AzureApi::deleteAzureResourceById($client, $account, $old_ip_id);

        $network_details = AzureApi::getAzureNetworkInterfacesDetails(
            $server->account_id,
            $server->network_interfaces,
            $resource_group,
            $server->at_subscription_id,
            $client
        );
        $new_ip = $network_details['properties']['ipConfigurations'][0]['properties']['publicIPAddress']['properties']['ipAddress'] ?? '';
        if ($new_ip === '') {
            throw new \RuntimeException('Azure returned empty new IPv4 address.');
        }

        $server->network_details = json_encode($network_details);
        $server->ip_address = $new_ip;
        $server->save();

        return [
            'provider' => 'azure',
            'resource_id' => (string) $server->vm_id,
            'account_id' => (string) $server->account_id,
            'region' => (string) $server->location,
            'ip_version' => 'ipv4',
            'old_ip' => $old_ip,
            'new_ip' => $new_ip,
        ];
    }

    private function changeAws(array $payload, string $ip_version): array
    {
        $account_id = (int) ($payload['account_id'] ?? 0);
        $region = trim((string) ($payload['region'] ?? $payload['location'] ?? ''));
        $instance_id = trim((string) ($payload['resource_id'] ?? $payload['instance_id'] ?? ''));

        if ($account_id <= 0) {
            throw new \InvalidArgumentException('AWS account_id is required.');
        }
        if ($region === '') {
            throw new \InvalidArgumentException('AWS region is required.');
        }
        if ($instance_id === '') {
            throw new \InvalidArgumentException('AWS resource_id(instance_id) is required.');
        }

        $account = Aws::find($account_id);
        if ($account === null) {
            throw new \RuntimeException('AWS account not found: ' . $account_id);
        }

        $proxy_url = self::proxyUrl($payload);
        $client = AwsApi::createAWSClient($region, $account->ak, $account->sk, $proxy_url !== null && $proxy_url !== '', 'ec2', $proxy_url);

        if ($ip_version === 'ipv6') {
            return $this->changeAwsIpv6($client, $account_id, $region, $instance_id);
        }

        return $this->changeAwsIpv4($client, $account_id, $region, $instance_id);
    }

    private function changeAwsIpv4(object $client, int $account_id, string $region, string $instance_id): array
    {
        $result = $client->describeInstances([
            'Filters' => [[
                'Name' => 'instance-id',
                'Values' => [$instance_id],
            ]],
        ]);
        $instance = $result['Reservations'][0]['Instances'][0] ?? null;
        if ($instance === null) {
            throw new \RuntimeException('AWS instance not found: ' . $instance_id);
        }

        $old_public_ip = 'null';
        if (isset($instance['NetworkInterfaces'][0]['Association']['AllocationId'])) {
            $old_allocation_id = $instance['NetworkInterfaces'][0]['Association']['AllocationId'];
            $old_public_ip = $instance['NetworkInterfaces'][0]['Association']['PublicIp'] ?? 'null';

            $eni_result = $client->describeNetworkInterfaces([
                'Filters' => [[
                    'Name' => 'association.allocation-id',
                    'Values' => [$old_allocation_id],
                ]],
            ]);
            $association_id = $eni_result['NetworkInterfaces'][0]['Association']['AssociationId'] ?? null;
            if ($association_id) {
                $client->disassociateAddress([
                    'AssociationId' => $association_id,
                ]);
            }

            $client->releaseAddress([
                'AllocationId' => $old_allocation_id,
            ]);
        }

        [$new_public_ip, $new_allocation_id] = AwsApi::allocateAddress($client);
        $client->associateAddress([
            'AllocationId' => $new_allocation_id,
            'InstanceId' => $instance_id,
        ]);

        return [
            'provider' => 'aws',
            'resource_id' => $instance_id,
            'account_id' => (string) $account_id,
            'region' => $region,
            'ip_version' => 'ipv4',
            'old_ip' => $old_public_ip,
            'new_ip' => $new_public_ip,
            'allocation_id' => $new_allocation_id,
        ];
    }

    private function changeAwsIpv6(object $client, int $account_id, string $region, string $instance_id): array
    {
        $result = $client->describeInstances([
            'Filters' => [[
                'Name' => 'instance-id',
                'Values' => [$instance_id],
            ]],
        ]);
        $instance = $result['Reservations'][0]['Instances'][0] ?? null;
        if ($instance === null) {
            throw new \RuntimeException('AWS instance not found: ' . $instance_id);
        }

        $network_interface = $instance['NetworkInterfaces'][0] ?? null;
        if ($network_interface === null) {
            throw new \RuntimeException('No network interface found for AWS instance ' . $instance_id . '.');
        }

        $vpc_id = $instance['VpcId'] ?? null;
        $subnet_id = $instance['SubnetId'] ?? null;
        $network_interface_id = $network_interface['NetworkInterfaceId'] ?? null;
        if ($vpc_id === null || $subnet_id === null || $network_interface_id === null) {
            throw new \RuntimeException('Missing VPC, subnet, or network interface information for AWS instance ' . $instance_id . '.');
        }

        $old_ipv6_addresses = array_column($network_interface['Ipv6Addresses'] ?? [], 'Ipv6Address');
        AwsApi::unassignIpv6Addresses($client, $network_interface_id, $old_ipv6_addresses);
        AwsApi::ensureSubnetIpv6CidrBlock($client, $vpc_id, $subnet_id);
        AwsApi::ensureRouteTableIpv6Route($client, $vpc_id, $subnet_id);
        $new_ipv6 = AwsApi::assignIpv6Addresses($client, $network_interface_id);

        return [
            'provider' => 'aws',
            'resource_id' => $instance_id,
            'account_id' => (string) $account_id,
            'region' => $region,
            'ip_version' => 'ipv6',
            'old_ip' => $old_ipv6_addresses === [] ? 'null' : implode(', ', $old_ipv6_addresses),
            'new_ip' => $new_ipv6,
            'network_interface_id' => $network_interface_id,
        ];
    }

    private static function proxyUrl(array $payload): ?string
    {
        $proxy_url = trim((string) ($payload['proxy_url'] ?? ''));
        if ($proxy_url !== '') {
            return $proxy_url;
        }

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            return $proxy_url === '' ? null : $proxy_url;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
