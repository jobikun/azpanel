<?php

namespace app\controller;

use app\model\Linode;
use app\model\User;
use think\facade\View;

class UserLinodeServer extends UserBase
{
    public function index()
    {
        $accounts = Linode::where('user_id', session('user_id'))
            ->where('disable', 0)
            ->order('id', 'desc')
            ->select();

        View::assign('accounts', $accounts);
        return View::fetch('../app/view/user/linode/server/index.html');
    }

    public function create()
    {
        $designated_id = input('id/d', 0);
        $query = Linode::where('user_id', session('user_id'))->where('disable', 0);
        if ($designated_id > 0) {
            $query->where('id', $designated_id);
        }

        $accounts = $query->order('id', 'desc')->select();

        $user = User::find(session('user_id'));
        $personalise = json_decode($user->personalise, true);

        View::assign([
            'accounts' => $accounts,
            'personalise' => $personalise,
            'regions' => LinodeList::regions(),
            'types' => LinodeList::types(),
            'images' => LinodeList::images(),
        ]);

        return View::fetch('../app/view/user/linode/server/create.html');
    }

    public function read($id)
    {
        try {
            $account = $this->getAccount((int) $id);
            $api = new LinodeApi($account->token, ProxyController::getDefaultProxyUrl());
            return json($api->listInstances());
        } catch (\Throwable $e) {
            return json([
                'ret' => 0,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    public function save()
    {
        try {
            $account = $this->getAccount(input('account_id/d'));
            $names = array_values(array_filter(array_map('trim', explode(',', input('label/s')))));
            if ($names === []) {
                throw new \InvalidArgumentException('Instance label is required.');
            }

            $root_pass = input('root_pass/s');
            if (strlen($root_pass) < 8) {
                throw new \InvalidArgumentException('Root password must be at least 8 characters.');
            }

            $api = new LinodeApi($account->token, ProxyController::getDefaultProxyUrl());
            $created = [];

            foreach ($names as $name) {
                $params = [
                    'label' => $name . '-' . date('YmdHis'),
                    'region' => input('region/s'),
                    'type' => input('type/s'),
                    'image' => input('image/s'),
                    'root_pass' => $root_pass,
                    'booted' => true,
                    'private_ip' => input('private_ip/s') === 'true',
                ];

                $tags = array_values(array_filter(array_map('trim', explode(',', input('tags/s', '')))));
                if ($tags !== []) {
                    $params['tags'] = $tags;
                }

                $created[] = $api->createInstance($params);
            }

            return json(Tools::msg('1', '创建成功', '已创建 ' . count($created) . ' 台 Linode'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '创建失败', $e->getMessage()));
        }
    }

    public function update($id)
    {
        try {
            $account = $this->getAccount((int) $id);
            $action = input('action/s');
            $instances = input('instances/a', []);
            $allowed = [
                'boot' => 'boot',
                'start' => 'boot',
                'shutdown' => 'shutdown',
                'stop' => 'shutdown',
                'reboot' => 'reboot',
            ];

            if (!isset($allowed[$action])) {
                throw new \InvalidArgumentException('Unsupported Linode action: ' . $action);
            }
            if ($instances === []) {
                throw new \InvalidArgumentException('No Linode instances selected.');
            }

            $api = new LinodeApi($account->token, ProxyController::getDefaultProxyUrl());
            foreach ($instances as $instance_id) {
                $api->action((int) $instance_id, $allowed[$action]);
            }

            return json(Tools::msg('1', '操作成功', 'Linode 操作已提交'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '操作失败', $e->getMessage()));
        }
    }

    public function delete($id)
    {
        try {
            $account = $this->getAccount(input('account_id/d'));
            $api = new LinodeApi($account->token, ProxyController::getDefaultProxyUrl());
            $api->deleteInstance((int) $id);

            return json(Tools::msg('1', '删除成功', 'Linode 已删除'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '删除失败', $e->getMessage()));
        }
    }

    private function getAccount(int $id): Linode
    {
        $account = Linode::where('user_id', session('user_id'))->find($id);
        if ($account === null) {
            throw new \RuntimeException('Linode account not found.');
        }

        return $account;
    }
}
