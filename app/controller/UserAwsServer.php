<?php

namespace app\controller;

use app\controller\AwsApi;
use app\controller\AwsList;
use app\controller\Tools;
use app\controller\UserTask;
use app\model\Aws;
use app\model\User;
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

        View::assign([
            'accounts' => $accounts,
            'personalise' => $personalise,
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

    private static function getAWSClient(string $region, string $access_key, string $secret_key): object
    {
        return new Ec2Client([
            'region' => $region,
            'version' => 'latest',
            'credentials' => [
                'key' => $access_key,
                'secret' => $secret_key,
            ],
        ]);
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
        $names = explode(',', $vm_name);
        $remarks = explode(',', $vm_remark);
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
        $proxy_url = null;
        if (input('socks5_switch') === 'true') {
            try {
                $proxy_url = ProxyController::createSocks5ProxyUrlFromInput();
            } catch (\Exception $e) {
                return json(Tools::msg('0', '创建失败', $e->getMessage()));
            }
        }

        $quota = AwsApi::getQuota($vm_location, $account->ak, $account->sk, $proxy_url);
        if ($quota === 'null') {
            return json(Tools::msg('0', '创建失败', '此账户在此区域可能未开通'));
        }
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
        ];
        // 初始化创建任务
        $progress = 0;
        $steps = $vm_number * 3;
        $task_id = UserTask::create(session('user_id'), '创建AWS虚拟机', $params, $task_uuid);
        // 开始创建
        foreach ($names as $vm_name) {
            $name = $vm_name . date('YmdHis', time());
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
                    AwsApi::createIpv6EC2($client, $controller_params);
                } else {
                    UserTask::update($task_id, (++$progress / $steps), '正在创建 EC2');
                    AwsApi::createOnlyIpv4EC2($client, $controller_params);
                }
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
            $client = $this->getAWSClient(input('location/s'), $account->ak, $account->sk);
            return json($client->describeInstances()->toArray());
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

            $client = $this->getAWSClient($location, $account->ak, $account->sk);
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
            $client = $this->getAWSClient($location, $account->ak, $account->sk);

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

            // 分配新弹性 IP
            [$new_public_ip, $new_allocation_id] = AwsApi::allocateAddress($client);

            // 获取子网 ID 并关联
            $subnet_id = $instance['SubnetId'] ?? null;
            if ($subnet_id === null) {
                throw new \Exception('无法获取实例的子网信息');
            }

            $client->associateAddress([
                'AllocationId' => $new_allocation_id,
                'InstanceId' => $instance_id,
            ]);

            return json(Tools::msg('1', '更换成功', "旧 IP: {$old_public_ip}<br>新 IP: {$new_public_ip}"));
        } catch (\Exception $e) {
            return json(Tools::msg('0', '更换失败', $e->getMessage()));
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
            $client = $this->getAWSClient($location, $account->ak, $account->sk);

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
            $client = $this->getAWSClient($location, $account->ak, $account->sk);
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
}
