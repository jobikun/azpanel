<?php

namespace app\controller;

use app\controller\AwsApi;
use app\controller\AwsList;
use app\controller\Tools;
use app\controller\UserTask;
use app\model\Aws;
use app\model\AwsServer;
use app\model\User;
use app\model\UserProxy;
use Aws\Ec2\Ec2Client;
use think\facade\View;
use think\helper\Str;

class UserAwsServer extends UserBase
{
    public function index()
    {
        $accounts = Aws::where('user_id', session('user_id'))
            ->where('disable', 0) // 只要没有被禁用的
            ->order('id', 'desc')
            ->select();

        View::assign([
            'accounts' => $accounts,
            'locations' => AwsList::instanceRegion(),
        ]);
        return View::fetch('../app/view/user/aws/server/index.html');
    }

    public function cached()
    {
        $emails = Aws::where('user_id', session('user_id'))->column('email', 'id');
        $account_ids = array_keys($emails);

        $servers = [];
        if (!empty($account_ids)) {
            try {
                $rows = AwsServer::whereIn('account_id', $account_ids)
                    ->order('last_seen_at', 'desc')
                    ->select();
                foreach ($rows as $row) {
                    $servers[] = [
                        'name' => (string) ($row->name ?? $row->instance_id ?? ''),
                        'account_email' => (string) ($emails[(int) $row->account_id] ?? $row->account_id),
                        'account_id' => (int) $row->account_id,
                        'region' => (string) ($row->region ?? ''),
                        'instance_id' => (string) ($row->instance_id ?? ''),
                        'ip_version' => (string) ($row->ip_version ?? 'ipv4'),
                        'current_ip' => (string) ($row->current_ip ?? ''),
                        'status' => (string) ($row->status ?? ''),
                        'instance_type' => (string) ($row->instance_type ?? ''),
                        'remark' => (string) ($row->remark ?? ''),
                        'resource_key' => (string) ($row->resource_key ?? ''),
                        'last_seen_at' => $row->last_seen_at ? date('Y-m-d H:i:s', (int) $row->last_seen_at) : '-',
                    ];
                }
            } catch (\Throwable $e) {
                $servers = [];
            }
        }

        View::assign('servers', $servers);
        return View::fetch('../app/view/user/aws/server/cached.html');
    }

    public function create()
    {
        $accounts = Aws::where('user_id', session('user_id'))
            ->where('disable', 0) // 只要没有被禁用的
            ->order('id', 'desc')
            ->select();

        $designated_id = (int) input('id');
        if ($designated_id !== 0) {
            $designated_account = Aws::where('user_id', session('user_id'))->find($designated_id);
            if ($designated_account === null) {
                return View::fetch('../app/view/user/reject.html');
            }
            View::assign('designated_account', $designated_account);
        }

        $user = User::find(session('user_id'));
        $personalise = json_decode($user->personalise, true);
        $proxies = UserProxy::where('user_id', session('user_id'))
            ->where('enabled', 1)
            ->order('is_default', 'desc')
            ->order('id', 'desc')
            ->select();
        $proxies = ProxyController::normalizeProxyRecords($proxies);

        View::assign([
            'accounts' => $accounts,
            'personalise' => $personalise,
            'proxies' => $proxies,
            'disk_sizes' => [16, 32, 64, 128],
            'sizes' => AwsList::instanceSizes(),
            'images' => AwsList::instanceImage(),
            'locations' => AwsList::instanceRegion(),
        ]);
        return View::fetch('../app/view/user/aws/server/create.html');
    }

    private static function getIpPermissions(): array
    {
        return [
            [
                'IpProtocol' => -1,
                'IpRanges' => [
                    [
                        'CidrIp' => '0.0.0.0/0',
                    ],
                ],
                'Ipv6Ranges' => [
                    [
                        'CidrIpv6' => '::/0',
                    ],
                ],
            ],
        ];
    }

    private static function getAWSClient(string $region, string $access_key, string $secret_key, ?string $proxy_url = null): object
    {
        $params = [
            'region' => $region,
            'version' => 'latest',
            'credentials' => [
                'key' => $access_key,
                'secret' => $secret_key,
            ],
        ];

        if ($proxy_url !== null) {
            $params['http'] = ProxyController::createAwsHttpOptions($proxy_url);
        }

        return new Ec2Client($params);
    }

    private static function generateScriptContent(string $name, string $passwd, string $custom_script): string
    {
        $text = "#!/bin/bash\necho root:" . $passwd . "|sudo chpasswd root\nsudo rm -rf /etc/ssh/sshd_config\nsudo tee /etc/ssh/sshd_config <<EOF\nClientAliveInterval 120\nSubsystem       sftp    /usr/lib/openssh/sftp-server\nX11Forwarding yes\nPrintMotd no\nChallengeResponseAuthentication no\nPasswordAuthentication yes\nPermitRootLogin yes\nUsePAM yes\nAcceptEnv LANG LC_*\nEOF\nsudo systemctl restart sshd\n";
        $text .= "sudo hostnamectl set-hostname " . $name . "\n";
        if ($custom_script !== '') {
            $text .= $custom_script;
        }
        return $text;
    }

    private static function decodeStoredQuota(Aws $account): array
    {
        $quota = $account->quota ?? null;
        if (is_array($quota)) {
            return $quota;
        }

        $raw_quota = method_exists($account, 'getData') ? $account->getData('quota') : $quota;
        if (is_array($raw_quota)) {
            return $raw_quota;
        }

        if (is_string($raw_quota) && $raw_quota !== '') {
            $decoded = json_decode($raw_quota, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_string($quota) && $quota !== '') {
            $decoded = json_decode($quota, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private static function getStoredRegionQuota(Aws $account, string $region)
    {
        $quota = self::decodeStoredQuota($account);
        $value = $quota[$region] ?? 'null';

        return is_numeric($value) ? (int) $value : $value;
    }

    private static function getCreateQuota(Aws $account, string $region, ?string $proxy_url)
    {
        $quota = AwsApi::getQuota($region, $account->ak, $account->sk, $proxy_url);
        if ($quota !== 'null') {
            return $quota;
        }

        return self::getStoredRegionQuota($account, $region);
    }

    public function save()
    {
        $vm_name = input('vm_name/s');
        $vm_remark = input('vm_remark/s');
        $vm_passwd = input('vm_passwd/s');
        $specified_size = input('specified_size/s');
        $vm_script = input('vm_script/s');
        $vm_location = input('vm_location/s');
        $vm_size = input('vm_size/s');
        $vm_account = input('vm_account/d');
        $vm_disk_size = input('vm_disk_size/d');
        $vm_image = input('vm_image/s');
        $task_uuid = input('task_uuid/s');
        $ipv6_network = input('ipv6_network/s');

        // 空账户检查
        if ($vm_account === '') {
            return json(Tools::msg('0', '创建失败', '你还没有添加账户'));
        }
        // 所有权检查
        $account = Aws::find($vm_account);
        if ($account->user_id !== (int) session('user_id')) {
            return json(Tools::msg('0', '创建失败', '你不是此账户的持有者'));
        }
        // 虚拟机名称分隔
        if ($vm_remark === '') {
            $vm_remark = $vm_name;
        }
        $names = array_map('trim', explode(',', $vm_name));
        $remarks = array_map('trim', explode(',', $vm_remark));
        $vm_number = count($names);
        if (count($names) !== $vm_number || count($remarks) !== $vm_number || count($names) !== count($remarks)) {
            return json(Tools::msg('0', '创建失败', '请检查创建数量、备注和虚拟机名称是否正确分隔'));
        }
        // 虚拟机名称检查
        foreach ($names as $name) {
            if ($name === '') {
                return json(Tools::msg('0', '创建失败', '虚拟机名称不能为空'));
            }
        }
        foreach ($remarks as $remark) {
            if ($remark === '') {
                return json(Tools::msg('0', '创建失败', '虚拟机备注不能为空'));
            }
        }
        // 检查自定义脚本
        if (Str::contains($vm_script, '#!/bin/bash') || Str::contains($vm_script, '#!/bin/sh')) {
            return json(Tools::msg('0', '创建失败', '自定义脚本不需要以 #!/bin/bash 或 #!/bin/sh 开头，因为已经包含。可直接输入需要执行的代码。注意：部分命令可能需要 sudo'));
        }
        // 检查区域权限
        try {
            $proxy_url = ProxyController::getProxyUrlForAccount($account);
            $proxy_label = ProxyController::getProxyLabelForAccount($account);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '创建失败', $e->getMessage()));
        }

        $quota = self::getCreateQuota($account, $vm_location, $proxy_url);
        // 记录创建参数
        $params = [
            'account' => [
                'id' => $account->id,
                'email' => $account->email,
                'quota' => $quota,
            ],
            'server' => [
                'name' => $vm_name,
                'mark' => $vm_remark,
                'size' => $vm_size,
                'count' => $vm_number,
                'image' => $vm_image,
                'location' => $vm_location,
                'disk_size' => $vm_disk_size,
                'script' => base64_encode($vm_script),
            ],
            'proxy' => [
                'source' => $proxy_label,
                'enabled' => $proxy_url !== null,
            ],
        ];
        // 初始化创建任务
        $progress = 0;
        $steps = $vm_number * 3;
        $task_id = UserTask::create(session('user_id'), '创建AWS虚拟机', $params, $task_uuid);
        UserTask::update($task_id, 0, 'Proxy for this create: ' . $proxy_label);
        // 开始创建
        foreach ($names as $pointer => $vm_name) {
            $name = $vm_name . date('YmdHis', time());
            $remark = (string) ($remarks[$pointer] ?? $vm_name);
            try {
                $images = AwsList::instanceImage();
                if (!isset($images[$vm_image])) {
                    throw new \RuntimeException('Invalid AWS image selected: ' . $vm_image);
                }

                $controller_params = [
                    'name' => $name,
                    'disk_size' => $vm_disk_size,
                    'size' => $specified_size === '' ? $vm_size : $specified_size,
                    'userDataRaw' => $this->generateScriptContent($vm_name, $vm_passwd, $vm_script),
                    'imageName' => $images[$vm_image]['imageName'],
                    'imageOwner' => $images[$vm_image]['imageOwner'],
                    'IpPermissions' => $this->getIpPermissions(),
                ];
                UserTask::update($task_id, (++$progress / $steps), '正在创建会话');
                $client = AwsApi::createAWSClient($vm_location, $account->ak, $account->sk, $proxy_url !== null, 'ec2', $proxy_url);
                if ($ipv6_network === 'true' && AwsApi::countRegionVpc($client, $vm_location) <= 4) {
                    UserTask::update($task_id, (++$progress / $steps), '正在创建具有 IPv6 的 EC2');
                    $create_result = AwsApi::createIpv6EC2($client, $controller_params);
                } else {
                    UserTask::update($task_id, (++$progress / $steps), '正在创建 EC2');
                    $create_result = AwsApi::createOnlyIpv4EC2($client, $controller_params);
                }
                InternalCloudflareDns::cacheCreatedAwsInstance((int) $account->id, $vm_location, [
                    'instance_id' => (string) ($create_result['instance_id'] ?? ''),
                    'name' => $name,
                    'public_ip' => (string) ($create_result['public_ip'] ?? ''),
                    'ipv6_addr' => (string) ($create_result['ipv6_addr'] ?? ''),
                    'status' => 'running',
                    'instance_type' => (string) $controller_params['size'],
                    'remark' => $remark,
                ]);
                UserTask::update($task_id, (++$progress / $steps), 'AWS instance cached');
                //AwsApi::createOnlyIpv4EC2($client, $controller_params);
            } catch (\Exception $e) {
                $error = $e->getLine() . ':' . $e->getMessage();
                UserTask::end($task_id, true, ['msg' => $error]);
                return json(Tools::msg('0', '创建失败', $error));
            }
        }

        UserTask::end($task_id, false);
        sleep(1);
        return json(Tools::msg('1', '创建结果', '创建成功'));
    }

    public function read($id)
    {
        $account = Aws::where('user_id', session('user_id'))->find($id);
        try {
            $location = input('location/s');
            $client = $this->getAWSClient($location, $account->ak, $account->sk, ProxyController::getProxyUrlForAccount($account));
            $result = $client->describeInstances()->toArray();
            // 加载到的实例顺手写入 aws_server 缓存，供 cloudflare_dns 拉取
            try {
                InternalCloudflareDns::cacheAwsInstances((int) $id, (string) $location, $result);
            } catch (\Throwable $e) {
                // 缓存失败不影响正常返回
            }
            return json($result);
        } catch (\Exception $e) {
            return json([
                'ret' => 0,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    public function edit()
    {
        return false;
    }

    public function update($id)
    {
        $account = Aws::where('user_id', session('user_id'))->find($id);
        try {
            $action = input('action/s');
            $location = input('location/s');
            $instances = input('instances/a');

            $client = $this->getAWSClient($location, $account->ak, $account->sk, ProxyController::getProxyUrlForAccount($account));
            return json(AwsApi::manageInstances($client, $action, $instances));
        } catch (\Exception $e) {
            return json([
                'ret' => 0,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    public function delete()
    {
        return false;
    }

    public function remark()
    {
        $resource_key = trim((string) input('resource_key/s', ''));
        $remark = trim((string) input('remark/s', ''));
        if ($resource_key === '') {
            return json(Tools::msg('0', '修改失败', '缓存记录不存在'));
        }
        if ($remark === '') {
            return json(Tools::msg('0', '修改失败', '备注不能为空'));
        }

        $account_ids = array_map(
            'intval',
            Aws::where('user_id', session('user_id'))->column('id')
        );
        $server = AwsServer::where('resource_key', $resource_key)->find();
        if ($server === null || !in_array((int) $server->account_id, $account_ids, true)) {
            return json(Tools::msg('0', '修改失败', '缓存记录不存在或无权修改'));
        }

        AwsServer::where('account_id', (int) $server->account_id)
            ->where('region', (string) $server->region)
            ->where('instance_id', (string) $server->instance_id)
            ->update([
                'remark' => $remark,
                'updated_at' => time(),
            ]);

        return json(Tools::msg('1', '修改成功', '备注已更新'));
    }

    public function changeIp()
    {
        $account_id = input('account_id/d');
        $location = input('location/s');
        $instance_id = input('instance_id/s');

        $account = Aws::where('user_id', session('user_id'))->find($account_id);
        if ($account === null) {
            return json(Tools::msg('0', '更换失败', '账户未找到'));
        }

        try {
            $client = $this->getAWSClient($location, $account->ak, $account->sk, ProxyController::getProxyUrlForAccount($account));

            // 获取实例信息
            $result = $client->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'instance-id',
                        'Values' => [$instance_id],
                    ],
                ],
            ]);
            $instance = $result['Reservations'][0]['Instances'][0];

            $old_public_ip = 'null';

            // 检查并释放当前弹性 IP
            if (isset($instance['NetworkInterfaces'][0]['Association']['AllocationId'])) {
                $old_allocation_id = $instance['NetworkInterfaces'][0]['Association']['AllocationId'];
                $old_public_ip = $instance['NetworkInterfaces'][0]['Association']['PublicIp'];

                // 检查是否有多个弹性 IP
                $eni_result = $client->describeNetworkInterfaces([
                    'Filters' => [
                        [
                            'Name' => 'association.allocation-id',
                            'Values' => [$old_allocation_id],
                        ],
                    ],
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

            // 获取子网 ID
            $subnet_id = $instance['SubnetId'] ?? null;
            if ($subnet_id === null) {
                throw new \Exception('无法获取实例的子网信息');
            }

            // 分配新弹性 IP
            [$new_public_ip, $new_allocation_id] = AwsApi::allocateAddress($client);

            // 绑定新弹性 IP，失败则释放刚申请的 IP，避免僵尸 EIP 占满配额
            try {
                $client->associateAddress([
                    'AllocationId' => $new_allocation_id,
                    'InstanceId' => $instance_id,
                ]);
            } catch (\Exception $e) {
                $client->releaseAddress(['AllocationId' => $new_allocation_id]);
                throw $e;
            }

            return json(Tools::msg('1', '更换成功', "旧 IP: {$old_public_ip}<br>新 IP: {$new_public_ip}"));
        } catch (\Exception $e) {
            return json(Tools::msg('0', '更换失败', $e->getMessage()));
        }
    }

    /**
     * 一键释放当前区域下所有未使用（未绑定实例）的弹性 IP，腾出配额
     */
    public function releaseUnusedIps()
    {
        $account_id = input('account_id/d');
        $location = input('location/s');

        $account = Aws::where('user_id', session('user_id'))->find($account_id);
        if ($account === null) {
            return json(Tools::msg('0', '释放失败', '账户未找到'));
        }

        try {
            $client = $this->getAWSClient($location, $account->ak, $account->sk, ProxyController::getProxyUrlForAccount($account));

            $result = $client->describeAddresses([
                'Filters' => [
                    ['Name' => 'domain', 'Values' => ['vpc']],
                ],
            ]);

            $released = [];
            $failed = [];
            foreach ($result['Addresses'] as $address) {
                // 既没绑定实例、也没有关联 ID 的，就是未使用的闲置弹性 IP
                if (empty($address['AssociationId']) && empty($address['InstanceId'])) {
                    try {
                        $client->releaseAddress(['AllocationId' => $address['AllocationId']]);
                        $released[] = $address['PublicIp'];
                    } catch (\Exception $e) {
                        $failed[] = $address['PublicIp'] . '（' . $e->getMessage() . '）';
                    }
                }
            }

            if ($released === [] && $failed === []) {
                return json(Tools::msg('1', '释放完成', '当前区域没有未使用的弹性 IP'));
            }

            $content = '已释放 ' . count($released) . ' 个闲置弹性 IP';
            if ($released !== []) {
                $content .= '：<br>' . implode('<br>', $released);
            }
            if ($failed !== []) {
                $content .= '<br>释放失败 ' . count($failed) . ' 个：<br>' . implode('<br>', $failed);
            }

            return json(Tools::msg('1', '释放完成', $content));
        } catch (\Exception $e) {
            return json(Tools::msg('0', '释放失败', $e->getMessage()));
        }
    }

    public function addIpv4()
    {
        $account_id = input('account_id/d');
        $location = input('location/s');
        $instance_id = input('instance_id/s');

        $account = Aws::where('user_id', session('user_id'))->find($account_id);
        if ($account === null) {
            return json(Tools::msg('0', '增加失败', '账户未找到'));
        }

        try {
            $client = $this->getAWSClient($location, $account->ak, $account->sk, ProxyController::getProxyUrlForAccount($account));

            // 分配弹性 IP
            [$public_ip, $allocation_id] = AwsApi::allocateAddress($client);

            // 获取实例的子网 ID
            $result = $client->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'instance-id',
                        'Values' => [$instance_id],
                    ],
                ],
            ]);
            $subnet_id = $result['Reservations'][0]['Instances'][0]['SubnetId'] ?? null;
            if ($subnet_id === null) {
                throw new \Exception('无法获取实例的子网信息');
            }

            // 关联弹性 IP
            $client->associateAddress([
                'AllocationId' => $allocation_id,
                'InstanceId' => $instance_id,
            ]);

            return json(Tools::msg('1', '增加成功', "新 IPv4: {$public_ip}"));
        } catch (\Exception $e) {
            return json(Tools::msg('0', '增加失败', $e->getMessage()));
        }
    }

    public function addIpv6()
    {
        $account_id = input('account_id/d');
        $location = input('location/s');
        $instance_id = input('instance_id/s');

        $account = Aws::where('user_id', session('user_id'))->find($account_id);
        if ($account === null) {
            return json(Tools::msg('0', '增加失败', '账户未找到'));
        }

        try {
            $client = $this->getAWSClient($location, $account->ak, $account->sk, ProxyController::getProxyUrlForAccount($account));
            $result = $client->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'instance-id',
                        'Values' => [$instance_id],
                    ],
                ],
            ]);

            $instance = $result['Reservations'][0]['Instances'][0] ?? null;
            if ($instance === null) {
                throw new \RuntimeException('Instance not found: ' . $instance_id);
            }

            $network_interface = $instance['NetworkInterfaces'][0] ?? null;
            if ($network_interface === null) {
                throw new \RuntimeException('No network interface found for instance ' . $instance_id . '.');
            }

            $vpc_id = $instance['VpcId'] ?? null;
            $subnet_id = $instance['SubnetId'] ?? null;
            $network_interface_id = $network_interface['NetworkInterfaceId'] ?? null;
            if ($vpc_id === null || $subnet_id === null || $network_interface_id === null) {
                throw new \RuntimeException('Missing VPC, subnet, or network interface information for instance ' . $instance_id . '.');
            }

            if (($network_interface['Ipv6Addresses'] ?? []) !== []) {
                return json(Tools::msg('1', '增加成功', '该实例已有 IPv6: ' . $network_interface['Ipv6Addresses'][0]['Ipv6Address']));
            }

            AwsApi::ensureSubnetIpv6CidrBlock($client, $vpc_id, $subnet_id);
            AwsApi::ensureRouteTableIpv6Route($client, $vpc_id, $subnet_id);
            $ipv6 = AwsApi::assignIpv6Addresses($client, $network_interface_id);

            return json(Tools::msg('1', '增加成功', '新增 IPv6: ' . $ipv6));
        } catch (\Exception $e) {
            return json(Tools::msg('0', '增加失败', $e->getMessage()));
        }
    }

    public function changeIpv6()
    {
        $account_id = input('account_id/d');
        $location = input('location/s');
        $instance_id = input('instance_id/s');

        $account = Aws::where('user_id', session('user_id'))->find($account_id);
        if ($account === null) {
            return json(Tools::msg('0', '更换失败', '账户未找到'));
        }

        try {
            $client = $this->getAWSClient($location, $account->ak, $account->sk, ProxyController::getProxyUrlForAccount($account));
            $result = $client->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'instance-id',
                        'Values' => [$instance_id],
                    ],
                ],
            ]);

            $instance = $result['Reservations'][0]['Instances'][0] ?? null;
            if ($instance === null) {
                throw new \RuntimeException('Instance not found: ' . $instance_id);
            }

            $network_interface = $instance['NetworkInterfaces'][0] ?? null;
            if ($network_interface === null) {
                throw new \RuntimeException('No network interface found for instance ' . $instance_id . '.');
            }

            $vpc_id = $instance['VpcId'] ?? null;
            $subnet_id = $instance['SubnetId'] ?? null;
            $network_interface_id = $network_interface['NetworkInterfaceId'] ?? null;
            if ($vpc_id === null || $subnet_id === null || $network_interface_id === null) {
                throw new \RuntimeException('Missing VPC, subnet, or network interface information for instance ' . $instance_id . '.');
            }

            $old_ipv6_addresses = array_column($network_interface['Ipv6Addresses'] ?? [], 'Ipv6Address');
            AwsApi::unassignIpv6Addresses($client, $network_interface_id, $old_ipv6_addresses);
            AwsApi::ensureSubnetIpv6CidrBlock($client, $vpc_id, $subnet_id);
            AwsApi::ensureRouteTableIpv6Route($client, $vpc_id, $subnet_id);
            $new_ipv6 = AwsApi::assignIpv6Addresses($client, $network_interface_id);

            $old_ipv6 = $old_ipv6_addresses === [] ? 'null' : implode(', ', $old_ipv6_addresses);
            return json(Tools::msg('1', '更换成功', "旧 IPv6: {$old_ipv6}<br>新 IPv6: {$new_ipv6}"));
        } catch (\Exception $e) {
            return json(Tools::msg('0', '更换失败', $e->getMessage()));
        }
    }
}
