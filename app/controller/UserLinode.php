<?php

namespace app\controller;

use app\model\Linode;
use app\model\UserProxy;
use think\facade\Db;
use think\facade\View;

class UserLinode extends UserBase
{
    public function index()
    {
        $this->ensureProxyColumn();
        $accounts = Linode::where('user_id', session('user_id'))
            ->order('id', 'desc')
            ->select();

        foreach ($accounts as $account) {
            $account->proxy_label = ProxyController::getProxyLabelForAccount($account);
        }

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
            $has_proxy_column = $this->ensureProxyColumn();
            $label = trim(input('label/s'));
            $token = trim(input('token/s'));
            if ($label === '' || $token === '') {
                throw new \InvalidArgumentException('Label and token are required.');
            }

            // 绑定代理：0=不使用，-1=跟随默认，正数=具体代理；验证请求也走绑定的代理
            $proxy_id = ProxyController::resolveBoundProxyIdFromInput();
            $bound = (object) ['proxy_id' => $proxy_id, 'user_id' => (int) session('user_id')];
            $api = new LinodeApi($token, ProxyController::getProxyUrlForAccount($bound));
            $profile = $api->profile();

            $account = new Linode();
            $account->user_id = session('user_id');
            $account->label = $label;
            $account->token = $token;
            if ($has_proxy_column) {
                $account->proxy_id = $proxy_id;
            }
            $account->email = $profile['email'] ?? '';
            $account->username = $profile['username'] ?? '';
            $account->mark = trim(input('mark/s', ''));
            $account->disable = 0;
            $account->created_at = time();
            $account->updated_at = time();
            $account->save();

            return json(Tools::msg('1', '保存成功', 'Linode 账户已保存<br>绑定代理: ' . ProxyController::getProxyLabelForAccount($bound)));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '保存失败', $e->getMessage()));
        }
    }

    public function update($id)
    {
        try {
            $has_proxy_column = $this->ensureProxyColumn();
            $account = Linode::where('user_id', session('user_id'))->find($id);
            if ($account === null) {
                throw new \RuntimeException('Linode account not found.');
            }

            if (input('action/s') === 'refresh') {
                $api = new LinodeApi($account->token, ProxyController::getProxyUrlForAccount($account));
                $profile = $api->profile();
                $account->email = $profile['email'] ?? '';
                $account->username = $profile['username'] ?? '';
                $account->disable = 0;
                $account->updated_at = time();
                $account->save();

                return json(Tools::msg('1', '刷新成功', '账户状态已更新'));
            }

            if (input('action/s') === 'bind_proxy') {
                if (! $has_proxy_column) {
                    throw new \RuntimeException('数据库缺少 linode.proxy_id 字段，请先执行迁移后再绑定代理。');
                }
                $account->proxy_id = ProxyController::resolveBoundProxyIdFromInput();
                $account->updated_at = time();
                $account->save();

                return json(Tools::msg('1', '绑定成功', '绑定代理已更新为：' . ProxyController::getProxyLabelForAccount($account)));
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

    private function ensureProxyColumn(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        try {
            $columns = Db::query("SHOW COLUMNS FROM `linode` LIKE 'proxy_id'");
            if (empty($columns)) {
                Db::execute("ALTER TABLE `linode` ADD COLUMN `proxy_id` int NOT NULL DEFAULT 0 COMMENT 'Bound proxy id (0=none, -1=default pool)' AFTER `token`");
            }
            $available = true;
        } catch (\Throwable $e) {
            // 数据库驱动不支持时由迁移文件补齐
            $available = false;
        }

        return $available;
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
