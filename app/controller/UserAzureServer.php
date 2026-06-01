<?php

namespace app\controller;

use app\controller\Ali;
use app\controller\AzureApi;
use app\controller\AzureList;
use app\controller\Tools;
use app\model\Azure;
use app\model\AzureServer;
use app\model\AzureServerResize;
use app\model\Config;
use app\model\ControlRule;
use app\model\SshKey;
use app\model\Traffic;
use app\model\User;
use app\model\UserProxy;
use Carbon\Carbon;
use think\facade\View;
use think\helper\Str;

class UserAzureServer extends UserBase
{
    public function index()
    {
        $servers = AzureServer::where('user_id', session('user_id'))
            ->order('id', 'desc')
            ->select();

        foreach ($servers as $server) {
            // 刷新服务器状态
            if ($server->status === 'PowerState/starting' || $server->status === 'PowerState/stopping') {
                try {
                    $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
                    $server->status = $vm_status['statuses']['1']['code'] ?? 'null';
                    $server->save();
                } catch (\Exception $e) {
                    // 页面渲染前无法读取下拉框代理，默认代理失败时不阻断列表页。
                }
            }

            // 从 network_details 提取所有公网 IPv4 地址
            $all_ips = [];
            $net_details = json_decode($server->network_details, true);
            if ($net_details && isset($net_details['properties']['ipConfigurations'])) {
                foreach ($net_details['properties']['ipConfigurations'] as $ip_config) {
                    if (isset($ip_config['properties']['publicIPAddress']['properties']['ipAddress'])) {
                        $all_ips[] = $ip_config['properties']['publicIPAddress']['properties']['ipAddress'];
                    }
                }
            }
            $server->ip_addresses = !empty($all_ips) ? implode("\n", $all_ips) : $server->ip_address;
        }

        View::assign([
            'servers' => $servers,
            'count' => $servers->count(),
            'sizes' => AzureList::sizes(),
            'locations' => AzureList::locations(),
            'resolv_sync' => Config::obtain('resolv_sync'),
            'ali_whitelist' => Config::obtain('ali_whitelist'),
            'proxies' => $this->getProxies(),
        ]);
        return View::fetch('../app/view/user/azure/server/index.html');
    }

    public function create()
    {
        $accounts = Azure::where('user_id', session('user_id'))
            ->where('az_sub_status', 'Enabled')
            ->order('id', 'desc')
            ->select();

        $traffic_rules = ControlRule::where('user_id', session('user_id'))
            ->select();

        $ssh_key = SshKey::where('user_id', session('user_id'))->find();

        $designated_id = (int) input('id');
        if ($designated_id !== 0) {
            $designated_account = Azure::where('user_id', session('user_id'))->find($designated_id);
            if ($designated_account === null) {
                return View::fetch('../app/view/user/reject.html');
            }
            View::assign('designated_account', $designated_account);
        }

        $user = User::find(session('user_id'));
        $personalise = json_decode($user->personalise, true);

        View::assign([
            'ssh_key' => $ssh_key,
            'accounts' => $accounts,
            'personalise' => $personalise,
            'traffic_rules' => $traffic_rules,
            'sizes' => AzureList::sizes(),
            'images' => AzureList::images(),
            'disk_sizes' => AzureList::diskSizes(),
            'locations' => AzureList::locations(),
            'proxies' => $this->getProxies(),
        ]);
        return View::fetch('../app/view/user/azure/server/create.html');
    }

    public function update($uuid)
    {
        $server = AzureServer::where('user_id', session('user_id'))
            ->where('vm_id', $uuid)
            ->find();

        $server->rule = input('traffic_rule/s');
        $server->save();
        return json(Tools::msg('1', '保存结果', '保存成功'));
    }

    public function save()
    {
        $vm_name = input('vm_name/s');
        $vm_remark = input('vm_remark/s');
        $vm_user = input('vm_user/s');
        $vm_passwd = input('vm_passwd/s');
        $vm_script = input('vm_script/s');
        $vm_location = input('vm_location/s');
        $vm_size = input('vm_size/s');
        $vm_image = input('vm_image/s');
        $task_uuid = input('task_uuid/s');
        //$vm_number       = (int) input('vm_number/s');
        $vm_account = (int) input('vm_account/s');
        $vm_disk_size = (int) input('vm_disk_size/s');
        $vm_ssh_key = (int) input('vm_ssh_key/s');
        $vm_traffic_rule = (int) input('vm_traffic_rule/s');
        $create_check = (int) input('create_check/s');
        $create_ipv6 = (bool) input('create_ipv6/s');

        // 创建账户检查
        if ($vm_account === '') {
            return json(Tools::msg('0', '创建失败', '你还没有添加账户'));
        }

        $account = Azure::find($vm_account);
        if ($account->user_id !== (int) session('user_id')) {
            return json(Tools::msg('0', '创建失败', '你不是此账户的持有者'));
        }

        // 虚拟机用户名与密码检查
        $prohibit_user = ['root', 'Admin', 'admin', 'centos', 'debian', 'ubuntu', 'administrator', 'test'];
        if (!preg_match('/^[a-zA-Z0-9]+$/', $vm_user) || in_array($vm_user, $prohibit_user)) {
            return json(Tools::msg('0', '创建失败', '用户名只允许使用大小写字母与数字的组合，且不能使用常见用户名'));
        }

        $uppercase = preg_match('@[A-Z]@', $vm_passwd);
        $lowercase = preg_match('@[a-z]@', $vm_passwd);
        $number = preg_match('@[0-9]@', $vm_passwd);
        // $symbol    = preg_match('@[^\w]@', $vm_passwd);

        if (!$uppercase || !$lowercase || !$number || strlen($vm_passwd) < 12 || strlen($vm_passwd) > 72) {
            return json(Tools::msg('0', '创建失败', '密码不符合要求，请阅读使用说明'));
        }

        if ($vm_remark === '') {
            $vm_remark = $vm_name;
        }

        // 虚拟机名称与备注检查
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

            if (!preg_match('/^[a-zA-Z0-9]+$/', $name)) {
                return json(Tools::msg('0', '创建失败', '虚拟机名称只允许使用大小写字母与数字的组合'));
            }

            if (strlen($name) > 64) {
                return json(Tools::msg('0', '创建失败', 'Linux 虚拟机名称长度不能超过 64 个字符'));
            }

            if (Str::contains($vm_image, 'Win') && strlen($name) > 15 || is_numeric($name)) {
                return json(Tools::msg('0', '创建失败', 'Windows 虚拟机名称长度不能超过 15 个字符，且不能是纯数字'));
            }
        }

        foreach ($remarks as $remark) {
            if ($remark === '') {
                return json(Tools::msg('0', '创建失败', '虚拟机备注不能为空'));
            }
        }

        // 其他项目检查
        $vm_script = $vm_script === '' ? null : base64_encode($vm_script);

        $images = AzureList::images();
        if (Str::contains($vm_image, 'Win') && !Str::contains($images[$vm_image]['sku'], 'smalldisk') && $vm_disk_size < '127') {
            return json(Tools::msg('0', '创建失败', '此 Windows 系统镜像要求硬盘大小不低于 127 GB'));
        }

        // 记录创建参数
        $params = [
            'account' => [
                'id' => $account->id,
                'status' => $account->az_sub_status,
                'type' => $account->az_sub_type,
                'email' => $account->az_email,
                'check' => $create_check === 1 ? true : false,
            ],
            'server' => [
                'name' => $vm_name,
                'mark' => $vm_remark,
                'count' => $vm_number,
                'disk_size' => $vm_disk_size,
                'user' => $vm_user,
                'image' => $vm_image,
                'location' => $vm_location,
                'size' => $vm_size,
                'script' => $vm_script,
                'ipv6' => $create_ipv6,
            ],
        ];

        /* if (session('user_id') !== 1) {
        return json(Tools::msg('0', '创建失败', '维护中'));
        } */

        // 创建http会话
        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '创建失败', Tools::exceptionMessage($e)));
        }

        // 初始化创建任务
        $progress = 0;
        $steps = ($vm_number * 7) + 6;
        $task_id = UserTask::create(session('user_id'), '创建虚拟机', $params, $task_uuid);

        if ($create_ipv6) {
            $steps += 1; // 多了创建ipv6地址的任务
        }

        if ($account->reg_capacity === 0) {
            ++$steps;
            UserTask::update($task_id, (++$progress / $steps), '正在注册 Microsoft.Capacity');
            AzureApi::registerMainAzureProviders($client, $account, 'Microsoft.Capacity');
        }

        if ($account->providers_register === 0) {
            ++$steps;
            UserTask::update($task_id, (++$progress / $steps), '正在注册 Microsoft.Compute 与 Microsoft.Network');
            AzureApi::registerMainAzureProviders($client, $account, 'Microsoft.Compute');
            AzureApi::registerMainAzureProviders($client, $account, 'Microsoft.Network');

            $account->providers_register = 1;
            $account->save();
        }

        UserTask::update($task_id, (++$progress / $steps), '正在检查订阅状态');
        try {
            $sub_info = AzureApi::getAzureSubscription($account->id, $client); // array
        } catch (\Exception $e) {
            UserTask::end($task_id, true, Tools::exceptionMessage($e));
            return json(Tools::msg('0', '创建失败', Tools::exceptionMessage($e)));
        }
        if ($sub_info['value']['0']['state'] !== 'Enabled') {
            UserTask::end($task_id, true, json_encode(
                ['msg' => 'This subscription is disabled and therefore marked as read only.']
            ), true);
            return json(Tools::msg('0', '创建失败', '订阅状态被设置为 Disabled 或 Warned'));
        }

        UserTask::update($task_id, (++$progress / $steps), '正在检查订阅可用资源列表');
        $limits = AzureApi::getResourceSkusList($client, $account, $vm_location);
        $size_family = null;
        $single_size_core = (int) (AzureList::sizes()[$vm_size]['cpu'] ?? 1);
        foreach ($limits['value'] as $limit) {
            if ($limit['name'] === $vm_size) {
                if ($create_check === 1 && self::hasSkuRestrictionForLocation($limit, $vm_location)) {
                    $message = $vm_size . ' 当前在 ' . $vm_location . ' 不可用，请换一个规格或地区。Azure 原因：' . self::skuRestrictionMessage($limit);
                    UserTask::end($task_id, true, json_encode(['msg' => $message]), true);
                    return json(Tools::msg('0', '创建失败', $message));
                }
                $hyper_v_generations = $limit['capabilities']['4']['value'] ?? '';
                foreach ($limit['capabilities'] ?? [] as $capability) {
                    if (($capability['name'] ?? '') === 'HyperVGenerations') {
                        $hyper_v_generations = $capability['value'];
                    }
                    if (($capability['name'] ?? '') === 'vCPUs') {
                        $single_size_core = (int) $capability['value'];
                    }
                }
                if ($hyper_v_generations === 'V1') {
                    if (Str::contains($images[$vm_image]['sku'], 'gen2') || Str::contains($images[$vm_image]['sku'], 'g2')) {
                        UserTask::end($task_id, true, json_encode(
                            ['msg' => 'The virtual machine model is not compatible with the image.']
                        ), true);
                        return json(Tools::msg('0', '创建失败', '此规格虚拟机不可使用镜像列表中包含 gen2 关键词的选项'));
                    }
                }
                $size_family = $limit['family'] ?? null;
            }
        }

        // 同名资源组允许复用；只有 Azure 中已存在同名虚拟机时才阻断。
        UserTask::update($task_id, (++$progress / $steps), '正在检查同名虚拟机');
        $virtual_machines = AzureApi::readAzureVirtualMachinesList($account->id, $account->az_sub_id, $client);
        foreach ($virtual_machines as $virtual_machine) {
            foreach ($names as $name) {
                $resource_group_name = Str::lower($name . '_group');
                $params = explode('/', $virtual_machine['id']);
                $vm_resource_group = Str::lower($params['4'] ?? '');
                if (Str::lower($virtual_machine['name']) === Str::lower($name) && $vm_resource_group === $resource_group_name) {
                    $message = 'Azure 中已存在同名虚拟机，请修改虚拟机名称 ' . $name;
                    UserTask::end($task_id, true, json_encode(['msg' => $message]), true);
                    return json(Tools::msg('0', '创建失败', $message));
                }
            }
        }

        // 核心数检查
        UserTask::update($task_id, (++$progress / $steps), '正在检查配额');
        if ($create_check === 1) {
            try {
                $sizes = AzureList::sizes();
                $quotas = AzureApi::getQuota($account, $vm_location, $client);
                $cores_total = (int) ($sizes[$vm_size]['cpu'] ?? $single_size_core) * $vm_number;

                foreach ($quotas['value'] as $quota) {
                    if ($quota['properties']['name']['value'] === 'cores') {
                        $quota_usage = $quota['properties']['currentValue'];
                        $quota_limit = $quota['properties']['limit'];
                        $account->reg_capacity = 1;
                        $account->save();
                    }
                    if ($size_family !== null && $quota['properties']['name']['value'] === $size_family) {
                        $size_quota_usage = $quota['properties']['currentValue'];
                        $size_quota_limit = $quota['properties']['limit'];
                    }
                }

                if (isset($quota_usage) && $cores_total + $quota_usage > $quota_limit) {
                    UserTask::update($task_id, ($progress / $steps), '预检查提示订阅核心配额可能不足，继续以 Azure 实际创建结果为准');
                }
                if (isset($size_quota_usage) && $cores_total + $size_quota_usage > $size_quota_limit) {
                    UserTask::update($task_id, ($progress / $steps), '预检查提示规格族配额可能不足，继续以 Azure 实际创建结果为准');
                }
            } catch (\Exception $e) {
                // Azure quota pre-check can lag behind Portal availability. Continue and let ARM create return the final result.
            }
        }

        // return json(Tools::msg('0', '检查结果', '检查完成'));

        foreach ($names as $vm_name) {
            // default value
            $ipv6 = false;
            $security_group_id = '';
            // name settings
            $vm_ipv4_name = $vm_name . '_ipv4';
            $vm_ipv6_name = $vm_name . '_ipv6';
            $security_group_name = $vm_name . '_security';
            $vm_resource_group_name = $vm_name . '_group';
            $vm_virtual_network_name = $vm_name . '_vnet';

            $vm_config = [
                'vm_size' => $vm_size,
                'vm_disk_size' => $vm_disk_size,
                'vm_user' => $vm_user,
                'vm_passwd' => $vm_passwd,
                'vm_script' => $vm_script,
                'vm_ssh_key' => $vm_ssh_key,
            ];

            try {
                // 创建资源组
                sleep(1);
                UserTask::update($task_id, (++$progress / $steps), '创建或复用资源组 ' . $vm_resource_group_name);
                AzureApi::createAzureResourceGroup(
                    $client,
                    $account,
                    $vm_resource_group_name,
                    $vm_location
                );

                // 创建网络安全组
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建网络安全组');
                sleep(2);
                $security_group_id = AzureApi::createNetworkSecurityGroups(
                    $client,
                    $account,
                    $vm_resource_group_name,
                    $vm_location,
                    $security_group_name
                );

                // 创建公网ipv4地址
                sleep(2);
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建 ipv4 地址');
                $ipv4 = AzureApi::createAzurePublicNetworkIpv4(
                    $client,
                    $account,
                    $vm_ipv4_name,
                    $vm_resource_group_name,
                    $vm_location,
                    true
                );

                if ($create_ipv6) {
                    // 创建公网ipv6地址
                    UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建 ipv6 地址');
                    sleep(2);
                    $ipv6 = AzureApi::createAzurePublicNetworkIpv6(
                        $client,
                        $account,
                        $vm_ipv6_name,
                        $vm_resource_group_name,
                        $vm_location
                    );
                }

                // 创建虚拟网络
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建虚拟网络');
                AzureApi::createAzureVirtualNetwork(
                    $client,
                    $account,
                    $vm_virtual_network_name,
                    $vm_resource_group_name,
                    $vm_location,
                    $create_ipv6
                );

                // 创建子网
                sleep(3);
                UserTask::update($task_id, (++$progress / $steps), '在虚拟网络 ' . $vm_virtual_network_name . ' 中创建子网');
                $subnets = AzureApi::createAzureVirtualNetworkSubnets(
                    $client,
                    $account,
                    $vm_virtual_network_name,
                    $vm_resource_group_name,
                    $vm_location,
                    $create_ipv6
                );

                // 创建网络接口
                sleep(6);
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建网络接口');
                $interfaces = AzureApi::createAzureVirtualNetworkInterfaces(
                    $client,
                    $account,
                    $vm_name,
                    $ipv4,
                    $ipv6,
                    $subnets,
                    $vm_location,
                    $vm_size,
                    $create_ipv6,
                    $security_group_id
                );

                // 创建虚拟机
                sleep(2);
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建虚拟机');
                $vm_url = AzureApi::createAzureVm(
                    $client,
                    $account,
                    $vm_name,
                    $vm_config,
                    $vm_image,
                    $interfaces,
                    $vm_location
                );
            } catch (\Exception $e) {
                $error = Tools::exceptionMessage($e);
                UserTask::end($task_id, true, $error);
                return json(Tools::msg('0', '创建失败', $error));
            }
        }

        UserTask::update($task_id, (++$progress / $steps), '等待创建完成');

        // 直到最后一个创建的虚拟机运行状态变为 running 再将所创建的虚拟机加入到列表中
        $count = 0;
        do {
            sleep(2);
            ++$count;
            $vm_status = AzureApi::getAzureVirtualMachineStatus($account->id, $vm_url, $client);
            $status = $vm_status['statuses']['1']['code'] ?? 'null';
        } while ($status !== 'PowerState/running' && $count < 120);

        // 加载到虚拟机列表
        AzureApi::getAzureVirtualMachines($account->id, $client);

        // 同步解析
        if ((int) session('user_id') === (int) Config::obtain('ali_whitelist')) {
            if (Config::obtain('sync_immediately_after_creation')) {
                foreach ($names as $vm_name) {
                    $server = AzureServer::where('user_id', session('user_id'))
                        ->where('name', $vm_name)
                        ->order('id', 'desc')
                        ->limit(1)
                        ->find();
                    try {
                        Ali::createOrUpdate($server->name, $server->ip_address);
                    } catch (\Exception $e) {
                        // ...
                    }
                }
            }
        }

        // 将设置的备注应用
        $pointer = 0;
        foreach ($names as $name) {
            $server = AzureServer::where('user_id', session('user_id'))
                ->where('name', $name)
                ->order('id', 'desc')
                ->limit(1)
                ->find();
            $server->user_remark = $remarks[$pointer];
            $server->rule = $vm_traffic_rule;
            $server->save();
            $pointer += 1;
        }

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '创建结果', '创建成功'));
    }

    public function read($id)
    {
        $server = AzureServer::where('user_id', session('user_id'))->find($id);
        if ($server === null) {
            return View::fetch('../app/view/user/reject.html');
        }

        $vm_sizes = AzureList::sizes();
        $disk_sizes = AzureList::diskSizes();
        $disk_tiers = AzureList::diskTiers();
        $traffic_rules = ControlRule::where('user_id', session('user_id'))->select();

        if ($server->disk_details === null) {
            try {
                $disk_response = AzureApi::getDisks($server);
                $server->disk_details = json_encode($disk_response);
                $server->save();
            } catch (\Exception $e) {
                $server->disk_details = null;
            }
        }

        $vm_details = json_decode($server->vm_details, true);
        $disk_details = $server->disk_details === null ? null : json_decode($server->disk_details, true);
        $network_details = json_decode($server->network_details, true);
        $instance_details = json_decode($server->instance_details, true);
        $vm_disk_created = strtotime($instance_details['disks']['0']['statuses']['0']['time'] ?? 'now');
        $vm_disk_tier = is_array($disk_details) ? ($disk_details['properties']['tier'] ?? 'P4') : 'P4';

        $vm_dialog = json_encode($vm_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $disk_dialog = json_encode($disk_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $network_dialog = json_encode($network_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $instance_dialog = json_encode($instance_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        View::assign('server', $server);
        View::assign('vm_sizes', $vm_sizes);
        View::assign('disk_sizes', $disk_sizes);
        View::assign('disk_tiers', $disk_tiers);
        View::assign('vm_dialog', $vm_dialog);
        View::assign('vm_details', $vm_details);
        View::assign('disk_dialog', $disk_dialog);
        View::assign('vm_disk_tier', $vm_disk_tier);
        View::assign('disk_details', $disk_details);
        View::assign('traffic_rules', $traffic_rules);
        View::assign('network_dialog', $network_dialog);
        View::assign('vm_disk_created', $vm_disk_created);
        View::assign('network_details', $network_details);
        View::assign('instance_dialog', $instance_dialog);
        View::assign('instance_details', $instance_details);

        // 提取所有公网 IPv4 配置
        $network_ipv4_configs = [];
        $network_ipv6_config = null;
        if (isset($network_details['properties']['ipConfigurations'])) {
            foreach ($network_details['properties']['ipConfigurations'] as $config) {
                $is_v6 = isset($config['properties']['publicIPAddress']['properties']['publicIPAddressVersion'])
                    && $config['properties']['publicIPAddress']['properties']['publicIPAddressVersion'] === 'IPv6';
                if ($is_v6) {
                    $network_ipv6_config = $config;
                } elseif (isset($config['properties']['publicIPAddress']['properties']['ipAddress'])) {
                    $network_ipv4_configs[] = $config;
                }
            }
        }
        View::assign('network_ipv4_configs', $network_ipv4_configs);
        View::assign('network_ipv6_config', $network_ipv6_config);
        View::assign('proxies', $this->getProxies());
        return View::fetch('../app/view/user/azure/server/read.html');
    }

    public function delete($uuid)
    {
        AzureServer::where('vm_id', $uuid)->delete();

        return json(Tools::msg('1', '移出结果', '移出成功'));
    }

    public function destroy($uuid)
    {
        $server = AzureServer::where('vm_id', $uuid)->find();

        try {
            AzureApi::deleteAzureResourcesGroup($server->account_id, $server->at_subscription_id, $server->resource_group);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '销毁失败', $e->getMessage()));
        }

        $server->delete();

        return json(Tools::msg('1', '销毁结果', '已销毁此虚拟机'));
    }

    public function remark($uuid)
    {
        $remark = input('remark/s');
        if ($remark === '') {
            return json(Tools::msg('0', '修改结果', '备注不能为空'));
        }

        $server = AzureServer::where('vm_id', $uuid)->find();
        $server->user_remark = $remark;
        $server->save();

        return json(Tools::msg('1', '修改结果', '修改成功'));
    }

    public function resize($uuid)
    {
        $new_size = input('new_size/s');
        $server = AzureServer::where('user_id', session('user_id'))
            ->where('vm_id', $uuid)
            ->find();
        if ($server === null) {
            return json(Tools::msg('0', '变配失败', '服务器未找到'));
        }

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);
            AzureApi::virtualMachinesResize($new_size, $server->location, $server->account_id, $server->request_url, $client);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '变配失败', $e->getMessage()));
        }

        $log = new AzureServerResize();
        $log->user_id = session('user_id');
        $log->vm_id = $server->vm_id;
        $log->before_size = $server->vm_size;
        $log->after_size = $new_size;
        $log->created_at = time();
        $log->save();

        $server->vm_size = $new_size;
        $server->save();

        return json(Tools::msg('1', '变配结果', '变配成功'));
    }

    public function redisk($uuid)
    {
        $count = 0;
        $new_disk = input('new_disk/s');
        $task_uuid = input('task_uuid/s');
        //$new_tier = input('new_tier/s');
        $server = AzureServer::where('user_id', session('user_id'))
            ->where('vm_id', $uuid)
            ->find();
        if ($server === null) {
            return json(Tools::msg('0', '更换失败', '服务器未找到'));
        }
        $params = [
            'vm_name' => $server->name,
            'original_size' => $server->disk_size,
            'upgrade_size' => $new_disk,
        ];
        $task_id = UserTask::create(session('user_id'), '更换硬盘大小', $params, $task_uuid);

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);

            UserTask::update($task_id, (++$count / 4), '正在分离计算资源');
            AzureApi::virtualMachinesDeallocate($server->account_id, $server->request_url, $client);

            do {
                sleep(2);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url, $client);
                $status = $vm_status['statuses']['1']['code'] ?? 'null';
            } while ($status !== 'PowerState/deallocated');

            UserTask::update($task_id, (++$count / 4), '正在启动虚拟机');
            //AzureApi::virtualMachinesRedisk($new_disk, $new_tier, $server);
            AzureApi::virtualMachinesRedisk($new_disk, $server, $client);
            AzureApi::manageVirtualMachine('start', $server->account_id, $server->request_url, $client);

            do {
                sleep(2);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url, $client);
                $status = $vm_status['statuses']['1']['code'] ?? 'null';
            } while ($status !== 'PowerState/running');

            sleep(1);
            UserTask::update($task_id, (++$count / 4), '正在获取新的公网地址');
            $network_details = AzureApi::getAzureNetworkInterfacesDetails($server->account_id, $server->network_interfaces, $server->resource_group, $server->at_subscription_id, $client);

            // update details
            $origin_disk_size = $server->disk_size;
            $server->disk_size = $new_disk;
            $server->disk_details = json_encode(AzureApi::getDisks($server, $client));
            $server->network_details = json_encode($network_details);
            $server->ip_address = $network_details['properties']['ipConfigurations']['0']['properties']['publicIPAddress']['properties']['ipAddress'] ?? 'null';
            $server->save();

            // save change log
            $log = new AzureServerResize();
            $log->user_id = session('user_id');
            $log->vm_id = $server->vm_id;
            $log->before_size = $origin_disk_size;
            $log->after_size = $new_disk;
            $log->created_at = time();
            $log->save();
        } catch (\Exception $e) {
            $error = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $error = $e->getResponse()->getBody()->getContents();
            }
            UserTask::end($task_id, true, $error);
            return json(Tools::msg('0', '更换失败', $error));
        }

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '更换结果', '更换成功'));
    }

    public function status($action, $uuid)
    {
        $server = AzureServer::where('user_id', session('user_id'))
            ->where('vm_id', $uuid)
            ->find();
        if ($server === null) {
            return json(Tools::msg('0', '操作失败', '服务器未找到'));
        }

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);
            AzureApi::manageVirtualMachine($action, $server->account_id, $server->request_url, $client);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '操作失败', $e->getMessage()));
        }

        sleep(1);
        self::refresh($server->vm_id);

        return json(Tools::msg('1', '执行结果', '成功'));
    }

    public static function refresh($uuid)
    {
        $server = AzureServer::where('user_id', session('user_id'))
            ->where('vm_id', $uuid)
            ->find();
        if ($server === null) {
            return json(Tools::msg('0', '操作失败', '服务器未找到'));
        }

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);
            $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url, $client);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '操作失败', $e->getMessage()));
        }

        $server->status = $vm_status['statuses']['1']['code'] ?? 'null';
        $server->save();

        return json(Tools::msg('1', '执行结果', '成功'));
    }

    public function change($uuid)
    {
        $count = 0;
        $steps = 7;
        $task_uuid = input('task_uuid/s');
        $server = AzureServer::where('vm_id', $uuid)->find();
        if ($server === null || $server->user_id !== (int) session('user_id')) {
            return json(Tools::msg('0', '更换失败', '服务器未找到'));
        }
        $params = [
            'vm_name' => $server->name,
            'original_ip' => $server->ip_address,
        ];
        $task_id = UserTask::create(session('user_id'), '更换公网地址', $params, $task_uuid);

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);

            UserTask::update($task_id, (++$count / $steps), "正在检查 {$server->name} 归属订阅状态");
            $sub_info = AzureApi::getAzureSubscription($server->account_id, $client);
            if ($sub_info['value']['0']['state'] !== 'Enabled') {
                UserTask::end($task_id, true, json_encode(
                    ['msg' => 'This subscription is disabled and therefore marked as read only.']
                ), true);
                return json(Tools::msg('0', '更换失败', '订阅状态被设置为 Disabled 或 Warned'));
            }

            $account = Azure::find($server->account_id);
            $resource_group = $server->resource_group;

            UserTask::update($task_id, (++$count / $steps), '正在检查网络接口');
            $network_details = AzureApi::getAzureNetworkInterfacesDetails(
                $server->account_id,
                $server->network_interfaces,
                $resource_group,
                $server->at_subscription_id,
                $client
            );

            $security_group_id = $network_details['properties']['networkSecurityGroup']['id'] ?? '';
            if ($security_group_id === '') {
                UserTask::update($task_id, (++$count / $steps), '正在创建网络安全组');
                $security_group_id = AzureApi::createNetworkSecurityGroups(
                    $client,
                    $account,
                    $resource_group,
                    $server->location,
                    $server->name . '_security'
                );
            } else {
                ++$count;
            }

            UserTask::update($task_id, (++$count / $steps), '正在创建新的 IPv4 地址');
            $new_ip_id = AzureApi::createAzurePublicNetworkIpv4(
                $client,
                $account,
                Str::substr($server->name, 0, 54) . '_ip4c_' . date('ymdHis'),
                $resource_group,
                $server->location,
                true
            );

            UserTask::update($task_id, (++$count / $steps), '正在替换主 IPv4 地址');
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

            UserTask::update($task_id, (++$count / $steps), '正在删除旧 IPv4 地址');
            sleep(3);
            AzureApi::deleteAzureResourceById($client, $account, $old_ip_id);

            UserTask::update($task_id, (++$count / $steps), '正在获取更新后的网络信息');
            $network_details = AzureApi::getAzureNetworkInterfacesDetails(
                $server->account_id,
                $server->network_interfaces,
                $resource_group,
                $server->at_subscription_id,
                $client
            );
            $server->network_details = json_encode($network_details);
            $server->ip_address = $network_details['properties']['ipConfigurations']['0']['properties']['publicIPAddress']['properties']['ipAddress'] ?? 'null';
            $server->save();

            if ((int) session('user_id') === (int) Config::obtain('ali_whitelist')) {
                if (Config::obtain('sync_immediately_after_creation')) {
                    try {
                        Ali::createOrUpdate($server->name, $server->ip_address);
                    } catch (\Exception $e) {
                        // DNS sync failure should not roll back the IP change.
                    }
                }
            }

            UserTask::end($task_id, false);
            return json(Tools::msg('1', '更换结果', '更换成功'));
        } catch (\Exception $e) {
            if ($e->getMessage() !== null) {
                $error = $e->getMessage();
            } else {
                $error = $e->getResponse()->getBody()->getContents();
            }
            UserTask::end(
                $task_id,
                true,
                json_encode(['msg' => $error])
            );
            return json(Tools::msg('0', '更换失败', $error));
        }

        // 同步解析
        if ((int) session('user_id') === (int) Config::obtain('ali_whitelist')) {
            if (Config::obtain('sync_immediately_after_creation')) {
                try {
                    Ali::createOrUpdate($server->name, $server->ip_address);
                } catch (\Exception $e) {
                    // ...
                }
            }
        }

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '更换结果', '更换成功'));
    }

    public function addIpv4($uuid)
    {
        $count = 0;
        $steps = 8;
        $task_uuid = input('task_uuid/s');
        $server = AzureServer::where('user_id', session('user_id'))
            ->where('vm_id', $uuid)
            ->find();
        if ($server === null) {
            return json(Tools::msg('0', '增加失败', '服务器未找到'));
        }

        $params = [
            'vm_name' => $server->name,
        ];
        $task_id = UserTask::create(session('user_id'), '增加公网 IPv4 地址', $params, $task_uuid);

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);

            UserTask::update($task_id, (++$count / $steps), '正在检查订阅状态');
            $sub_info = AzureApi::getAzureSubscription($server->account_id, $client);
            if ($sub_info['value']['0']['state'] !== 'Enabled') {
                throw new \Exception('订阅状态被设置为 Disabled 或 Warned');
            }

            $account = Azure::find($server->account_id);
            $resource_group = $server->resource_group;
            $new_ip_name = Str::substr($server->name, 0, 54) . '_ip4a_' . date('ymdHis');

            $net_details = json_decode($server->network_details, true);
            $security_group_id = '';
            if (!isset($net_details['properties']['networkSecurityGroup']['id'])) {
                UserTask::update($task_id, (++$count / $steps), '正在创建网络安全组');
                $security_group_id = AzureApi::createNetworkSecurityGroups(
                    $client,
                    $account,
                    $resource_group,
                    $server->location,
                    $server->name . '_security'
                );
            } else {
                ++$count;
            }

            UserTask::update($task_id, (++$count / $steps), '正在创建新的 IPv4 地址');
            $new_ip_id = AzureApi::createAzurePublicNetworkIpv4(
                $client,
                $account,
                $new_ip_name,
                $resource_group,
                $server->location,
                true
            );

            UserTask::update($task_id, (++$count / $steps), '正在等待 IPv4 地址就绪');
            sleep(5);

            UserTask::update($task_id, (++$count / $steps), '正在将新 IPv4 地址绑定到网络接口');
            $nic_name = $server->network_interfaces;
            $ip_config_name = 'ipconfiguraion_v4_add_' . date('ymdHis');
            AzureApi::addNetworkInterfaceIpConfiguration(
                $client,
                $account,
                $resource_group,
                $nic_name,
                $new_ip_id,
                $ip_config_name,
                $server->location,
                $security_group_id
            );

            UserTask::update($task_id, (++$count / $steps), '正在获取更新后的网络信息');
            $network_details = AzureApi::getAzureNetworkInterfacesDetails(
                $server->account_id,
                $server->network_interfaces,
                $resource_group,
                $server->at_subscription_id,
                $client
            );
            $server->network_details = json_encode($network_details);
            $server->ip_address = $network_details['properties']['ipConfigurations']['0']['properties']['publicIPAddress']['properties']['ipAddress'] ?? $server->ip_address;
            $server->save();

            UserTask::update($task_id, (++$count / $steps), '正在重启虚拟机');
            AzureApi::manageVirtualMachine('restart', $server->account_id, $server->request_url, $client);

            UserTask::update($task_id, (++$count / $steps), '等待虚拟机恢复运行');
            $wait_count = 0;
            do {
                sleep(3);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url, $client);
                $status = $vm_status['statuses']['1']['code'] ?? 'PowerState/unknown';
                $wait_count++;
            } while ($status !== 'PowerState/running' && $wait_count < 40);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            UserTask::end($task_id, true, json_encode(['msg' => $error]));
            return json(Tools::msg('0', '增加失败', $error));
        }

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '增加结果', '增加成功'));
    }

    public function check($ipv4)
    {
        // http://4563.org/?p=368746

        /* try {
        $result = file_get_contents('https://api-v2.50network.com/modules/ipcheck/icmp?ipv4=' . $ipv4);
        $result = json_decode($result, true);
        $cn_net = ($result['firewall-enable'] == true) ? '<p>中国节点 -> <span style="color: green">正常</span>' : '中国节点 -> <span style="color: red">异常</span></p>';
        $intl_net = ($result['firewall-disable'] == true) ? '<p>外国节点 -> <span style="color: green">正常</span>' : '外国节点 -> <span style="color: red">异常</span></p>';

        return json(Tools::msg('1', '检查成功', $cn_net . $intl_net));
        } catch (\Exception $e) {
        return json(Tools::msg('0', '检查失败', $e->getMessage()));
        } */

        try {
            $client = ProxyController::createGuzzleClient();
            $response = $client->post('https://www.vps234.com/ipcheck/getdata/', [
                'form_params' => [
                    'idName' => 'itemblockid' . Tools::getUnixTimestamp(),
                    'ip' => $ipv4,
                ],
                'verify' => false,
            ]);
            $result = json_decode($response->getBody(), true);
            $r = $result['data']['data'];
            $text = vsprintf(
                '<p>国内ICMP <span style="float: right; color: %s">%s</span></p>
                <p>国内TCP <span style="float: right; color: %s">%s</span></p>
                <div class="mdui-typo"><hr /></div>
                <p>国外ICMP <span style="float: right; color: %s">%s</span></p>
                <p>国外TCP <span style="float: right; color: %s">%s</span></p>',
                [
                    $r['innerICMP'] ? 'green' : 'red',
                    $r['innerICMP'] ? '正常' : '异常',
                    $r['innerTCP'] ? 'green' : 'red',
                    $r['innerTCP'] ? '正常' : '异常',
                    $r['outICMP'] ? 'green' : 'red',
                    $r['outICMP'] ? '正常' : '异常',
                    $r['outTCP'] ? 'green' : 'red',
                    $r['outTCP'] ? '正常' : '异常',
                ]
            );
            return json(Tools::msg('1', '检查结果', $text));
        } catch (\Exception $e) {
            return json(Tools::msg('0', '检查失败', $e->getMessage()));
        }
    }

    public function sync($uuid)
    {
        if ((int) session('user_id') !== (int) Config::obtain('ali_whitelist')) {
            return json(Tools::msg('0', '同步失败', '你不在权限白名单中'));
        }
        $server = AzureServer::where('vm_id', $uuid)->find();

        try {
            Ali::createOrUpdate($server->name, $server->ip_address);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '同步失败', $e->getMessage()));
        }

        return json(Tools::msg('1', '同步结果', '同步成功'));
    }

    public static function processGeneralData($array, $convert = false)
    {
        $text = '';

        if ($convert) {
            foreach ($array as $data) {
                $date = date('d日H时', strtotime($data['timeStamp']));
                $text .= '["' . $date . '", ' . round(round($data['average'] ?? '0', 2) / 1048576) . '],';
            }
        } else {
            foreach ($array as $data) {
                $date = date('d日H时', strtotime($data['timeStamp']));
                $text .= '["' . $date . '", ' . round($data['average'] ?? '0', 2) . '],';
            }
        }

        return $text;
    }

    public static function processNetworkData($array, $total = false)
    {
        $text = '';
        $usage = 0;

        foreach ($array as $data) {
            $date = date('d日H时', strtotime($data['timeStamp']));
            $bytes = round(($data['total'] ?? '0') / 1000000000, 2);
            $text .= '["' . $date . '", ' . $bytes . '],';
            $usage += $bytes;
        }

        return $total === false ? $text : $usage;
    }

    public function chart($id)
    {
        $gap = (int) input('gap');
        $server = AzureServer::find($id);
        if ($server === null || $server->user_id !== (int) session('user_id')) {
            return View::fetch('../app/view/user/reject.html');
        }

        if ($gap === '') {
            $statistics = AzureApi::getVirtualMachineStatistics($server);
        } else {
            $timestamp = strtotime(Carbon::parse("+{$gap} days ago")->toDateTimeString());
            $start_time = date('Y-m-d\T 16:00:00\Z', $timestamp);
            $stop_time = date('Y-m-d\T 16:00:00\Z', $timestamp + 86400);
            $chart_day = date('Y-m-d', $timestamp + 86400);

            $statistics = AzureApi::getVirtualMachineStatistics($server, $start_time, $stop_time);
        }

        //dump($statistics['value']);

        foreach ($statistics['value'] as $key => $value) {
            if ($value['name']['value'] === 'Network In Total') {
                $network_in_total = $statistics['value'][$key]['timeseries']['0']['data'];
            }
            if ($value['name']['value'] === 'Network Out Total') {
                $network_out_total = $statistics['value'][$key]['timeseries']['0']['data'];
            }
            if ($value['name']['value'] === 'Percentage CPU') {
                $percentage_cpu = $statistics['value'][$key]['timeseries']['0']['data'];
            }
            if ($value['name']['value'] === 'CPU Credits Remaining') {
                $cpu_credits = $statistics['value'][$key]['timeseries']['0']['data'];
            }
            if ($value['name']['value'] === 'Available Memory Bytes') {
                $available_memory = $statistics['value'][$key]['timeseries']['0']['data'];
            }
        }

        $traffic_usage = Traffic::where('uuid', $server->vm_id)->order('id', 'desc')->select();
        $chart_day = $chart_day ?? null;

        $total_in_traffic_usage = 0;
        $total_out_traffic_usage = 0;
        foreach ($traffic_usage as $usage) {
            $total_in_traffic_usage += $usage->u;
            $total_out_traffic_usage += $usage->d;
        }

        View::assign([
            'server' => $server,
            'chart_day' => $chart_day,
            'count' => $traffic_usage->count(),
            'traffic_usage' => $traffic_usage,
            'total_in_traffic_usage' => $total_in_traffic_usage,
            'total_out_traffic_usage' => $total_out_traffic_usage,
            'cpu_credits_text' => self::processGeneralData($cpu_credits),
            'percentage_cpu_text' => self::processGeneralData($percentage_cpu),
            'network_in_total_text' => self::processNetworkData($network_in_total),
            'network_out_total_text' => self::processNetworkData($network_out_total),
            'network_in_traffic' => self::processNetworkData($network_in_total, true),
            'network_out_traffic' => self::processNetworkData($network_out_total, true),
            'available_memory_text' => self::processGeneralData($available_memory, true),
        ]);
        return View::fetch('../app/view/user/azure/server/chart.html');
    }

    public function search()
    {
        $user_id = session('user_id');
        $s_name = input('s_name/s');
        $s_mark = input('s_mark/s');
        $s_size = input('s_size/s');
        $s_public = input('s_public/s');
        $s_status = input('s_status/s');
        $s_location = input('s_location/s');

        $where = [];
        $where[] = ['user_id', '=', $user_id];
        ($s_name !== '') && $where[] = ['name', 'like', '%' . $s_name . '%'];
        ($s_mark !== '') && $where[] = ['user_remark', 'like', '%' . $s_mark . '%'];
        ($s_public !== '') && $where[] = ['ip_address', 'like', '%' . $s_public . '%'];
        ($s_size !== 'all') && $where[] = ['vm_size', '=', $s_size];
        ($s_status !== 'all') && $where[] = ['status', '=', $s_status];
        ($s_location !== 'all') && $where[] = ['location', '=', $s_location];

        $data = AzureServer::where($where)
            ->field('vm_id')
            ->select();

        // $sql = Db::getLastSql();

        return json(['result' => $data]);
    }

    public function available()
    {
        $location = input('location/s');
        $vm_account = input('vm_account/s');

        $set = [];
        $account = Azure::where('user_id', session('user_id'))->find($vm_account);
        if ($account === null) {
            return json(Tools::msg('0', '加载失败', 'Azure 账户不存在或不属于当前用户'));
        }

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);
            $limits = AzureApi::getResourceSkusList($client, $account, $location);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '加载失败', Tools::exceptionMessage($e)));
        }

        foreach ($limits['value'] as $limit) {
            if ($limit['resourceType'] === 'virtualMachines') {
                // 若虚拟机规格中包含关键字p 则代表是arm64处理器 与默认镜像不兼容 因此需要过滤掉
                if (!Str::contains($limit['name'], 'p') && !self::hasSkuRestrictionForLocation($limit, $location)) {
                    $cpu = self::getSkuCapability($limit, 'vCPUs', $limit['capabilities']['2']['value'] ?? '?');
                    $memory = self::getSkuCapability($limit, 'MemoryGB', $limit['capabilities']['5']['value'] ?? '?');
                    $size = [
                        'name' => $limit['name'],
                        'size_name' => $limit['name'] . ' => ' . $cpu . 'C_' . $memory . 'GB',
                    ];
                    array_push($set, $size);
                }
            }
        }

        return json($set);
    }

    public function price()
    {
        $vm_size = input('vm_size/s');
        $location = input('location/s');
        $vm_sku = str_replace('Standard_', '', $vm_size);

        try {
            $proxy_url = ProxyController::getProxyUrlFromInputOrDefault();
            $client = ProxyController::createGuzzleClient($proxy_url, [], false);
            $addr = "https://prices.azure.com/api/retail/prices?api-version=2021-10-01-preview";
            $query = "armRegionName eq '{$location}' and SkuName eq '{$vm_sku}' and priceType eq 'Consumption' and serviceName eq 'Virtual Machines' ";
            $url = $addr . '&' . http_build_query(['$filter' => $query]);
            $response = $client->request('GET', $url);
            $json_data = json_decode($response->getBody(), true);

            $prices = [];
            foreach ($json_data['Items'] as $item) {
                $prices[] = $item['retailPrice'];
            }
            return json([
                'prices' => $prices,
            ]);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getProxies()
    {
        return UserProxy::where('user_id', session('user_id'))
            ->where('enabled', 1)
            ->order('is_default', 'desc')
            ->order('id', 'desc')
            ->select();
    }

    private static function getSkuCapability(array $sku, string $name, $default = null)
    {
        foreach ($sku['capabilities'] ?? [] as $capability) {
            if (($capability['name'] ?? '') === $name) {
                return $capability['value'] ?? $default;
            }
        }

        return $default;
    }

    private static function hasSkuRestrictionForLocation(array $sku, string $location): bool
    {
        foreach ($sku['restrictions'] ?? [] as $restriction) {
            $values = array_map('strtolower', $restriction['values'] ?? []);
            $locations = array_map('strtolower', $restriction['restrictionInfo']['locations'] ?? []);
            $location = strtolower($location);

            if (empty($values) && empty($locations)) {
                return true;
            }
            if (in_array($location, $values, true) || in_array($location, $locations, true)) {
                return true;
            }
            if (($restriction['type'] ?? '') === 'Location') {
                return true;
            }
        }

        return false;
    }

    private static function skuRestrictionMessage(array $sku): string
    {
        $messages = [];
        foreach ($sku['restrictions'] ?? [] as $restriction) {
            $reason = $restriction['reasonCode'] ?? 'Restriction';
            $type = $restriction['type'] ?? 'Unknown';
            $messages[] = $reason . ' / ' . $type;
        }

        return empty($messages) ? 'SkuNotAvailable' : implode('; ', $messages);
    }
}
