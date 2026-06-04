<?php

namespace app\controller;

use app\model\Linode;
use app\model\UserProxy;
use think\facade\View;

class UserLinode extends UserBase
{
    public function index()
    {
        $accounts = Linode::where('user_id', session('user_id'))
            ->order('id', 'desc')
            ->select();

        View::assign([
            'accounts' => $accounts,
            'proxies' => $this->getProxies(),
            'total' => $accounts->count(),
        ]);

        return View::fetch('../app/view/user/linode/index.html');
    }

    public function create()
    {
        View::assign([
            'proxies' => $this->getProxies(),
        ]);
        return View::fetch('../app/view/user/linode/create.html');
    }

    public function save()
    {
        try {
            $label = trim(input('label/s'));
            $token = trim(input('token/s'));
            if ($label === '' || $token === '') {
                throw new \InvalidArgumentException('Label and token are required.');
            }

            $api = new LinodeApi($token, ProxyController::getProxyUrlFromInputOrDefault());
            $profile = $api->profile();

            $account = new Linode();
            $account->user_id = session('user_id');
            $account->label = $label;
            $account->token = $token;
            $account->email = $profile['email'] ?? '';
            $account->username = $profile['username'] ?? '';
            $account->mark = trim(input('mark/s', ''));
            $account->disable = 0;
            $account->created_at = time();
            $account->updated_at = time();
            $account->save();

            return json(Tools::msg('1', '保存成功', 'Linode 账户已保存'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '保存失败', $e->getMessage()));
        }
    }

    public function update($id)
    {
        try {
            $account = Linode::where('user_id', session('user_id'))->find($id);
            if ($account === null) {
                throw new \RuntimeException('Linode account not found.');
            }

            if (input('action/s') === 'refresh') {
                $api = new LinodeApi($account->token, ProxyController::getProxyUrlFromInputOrDefault());
                $profile = $api->profile();
                $account->email = $profile['email'] ?? '';
                $account->username = $profile['username'] ?? '';
                $account->disable = 0;
                $account->updated_at = time();
                $account->save();

                return json(Tools::msg('1', '刷新成功', '账户状态已更新'));
            }

            $account->label = trim(input('label/s'));
            $account->token = trim(input('token/s'));
            $account->mark = trim(input('mark/s', ''));
            $account->updated_at = time();
            $account->save();

            return json(Tools::msg('1', '保存成功', '账户已更新'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '保存失败', $e->getMessage()));
        }
    }

    public function delete($id)
    {
        try {
            $account = Linode::where('user_id', session('user_id'))->find($id);
            if ($account === null) {
                throw new \RuntimeException('Linode account not found.');
            }
            $account->delete();
            return json(Tools::msg('1', '删除成功', '账户已删除'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '删除失败', $e->getMessage()));
        }
    }

    private function getProxies()
    {
        $proxies = UserProxy::where('user_id', session('user_id'))
            ->where('enabled', 1)
            ->order('is_default', 'desc')
            ->order('id', 'desc')
            ->select();

        return ProxyController::normalizeProxyRecords($proxies);
    }
}
