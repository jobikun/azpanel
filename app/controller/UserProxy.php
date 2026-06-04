<?php

namespace app\controller;

use app\model\UserProxy as UserProxyModel;
use think\facade\Db;
use think\facade\View;

class UserProxy extends UserBase
{
    public function index()
    {
        $has_protocol = $this->ensureProtocolColumn();
        $proxies = UserProxyModel::where('user_id', session('user_id'))
            ->order('id', 'desc')
            ->select();

        foreach ($proxies as $proxy) {
            $proxy->protocol = $has_protocol
                ? ProxyController::normalizeProxyProtocol((string) ($proxy->protocol ?? 'socks5'))
                : 'socks5';
        }

        View::assign([
            'proxies' => $proxies,
            'total' => $proxies->count(),
        ]);

        return View::fetch('../app/view/user/proxy/index.html');
    }

    public function save()
    {
        try {
            $has_protocol = $this->ensureProtocolColumn();
            $this->validateInput();
            $this->assertProtocolColumnAvailable($has_protocol);

            if (input('is_default/d') === 1) {
                UserProxyModel::where('user_id', session('user_id'))->update(['is_default' => 0]);
            }

            $proxy = new UserProxyModel();
            $proxy->user_id = session('user_id');
            $proxy->name = trim(input('name/s'));
            if ($has_protocol) {
                $proxy->protocol = ProxyController::normalizeProxyProtocol((string) input('protocol/s', 'socks5'));
            }
            $proxy->address = trim(input('address/s'));
            $proxy->port = input('port/d');
            $proxy->username = trim(input('username/s', ''));
            $proxy->password = trim(input('password/s', ''));
            $proxy->enabled = input('enabled/d', 1);
            $proxy->is_default = input('is_default/d', 0);
            $proxy->created_at = time();
            $proxy->updated_at = time();
            $proxy->save();

            return json(Tools::msg('1', '保存成功', '代理已保存'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '保存失败', $e->getMessage()));
        }
    }

    public function update($id)
    {
        try {
            $has_protocol = $this->ensureProtocolColumn();
            $proxy = UserProxyModel::where('user_id', session('user_id'))->find($id);
            if ($proxy === null) {
                throw new \RuntimeException('Proxy not found.');
            }

            if (input('action/s') === 'default') {
                UserProxyModel::where('user_id', session('user_id'))->update(['is_default' => 0]);
                $proxy->is_default = 1;
                $proxy->enabled = 1;
                $proxy->updated_at = time();
                $proxy->save();
                return json(Tools::msg('1', '设置成功', '默认代理已更新'));
            }

            if (input('action/s') === 'toggle') {
                $proxy->enabled = $proxy->enabled ? 0 : 1;
                $proxy->updated_at = time();
                $proxy->save();
                return json(Tools::msg('1', '切换成功', '代理状态已更新'));
            }

            $this->validateInput();
            $this->assertProtocolColumnAvailable($has_protocol);
            if (input('is_default/d') === 1) {
                UserProxyModel::where('user_id', session('user_id'))->update(['is_default' => 0]);
            }

            $proxy->name = trim(input('name/s'));
            if ($has_protocol) {
                $proxy->protocol = ProxyController::normalizeProxyProtocol((string) input('protocol/s', 'socks5'));
            }
            $proxy->address = trim(input('address/s'));
            $proxy->port = input('port/d');
            $proxy->username = trim(input('username/s', ''));
            $proxy->password = trim(input('password/s', ''));
            $proxy->enabled = input('enabled/d', 1);
            $proxy->is_default = input('is_default/d', 0);
            $proxy->updated_at = time();
            $proxy->save();

            return json(Tools::msg('1', '保存成功', '代理已更新'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '保存失败', $e->getMessage()));
        }
    }

    public function delete($id)
    {
        try {
            $proxy = UserProxyModel::where('user_id', session('user_id'))->find($id);
            if ($proxy === null) {
                throw new \RuntimeException('Proxy not found.');
            }

            $proxy->delete();
            return json(Tools::msg('1', '删除成功', '代理已删除'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '删除失败', $e->getMessage()));
        }
    }

    private function validateInput(): void
    {
        ProxyController::createProxyUrl(
            input('protocol/s', 'socks5'),
            input('address/s'),
            input('port/d'),
            input('username/s', ''),
            input('password/s', '')
        );

        if (trim(input('name/s')) === '') {
            throw new \InvalidArgumentException('代理名称不能为空');
        }
    }

    private function assertProtocolColumnAvailable(bool $has_protocol): void
    {
        $protocol = ProxyController::normalizeProxyProtocol((string) input('protocol/s', 'socks5'));
        if (! $has_protocol && $protocol !== 'socks5') {
            throw new \RuntimeException('数据库缺少 user_proxy.protocol 字段，请先执行迁移或手动添加该字段后再保存 HTTP 代理。');
        }
    }

    private function ensureProtocolColumn(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        try {
            $columns = Db::query("SHOW COLUMNS FROM `user_proxy` LIKE 'protocol'");
            if (empty($columns)) {
                Db::execute("ALTER TABLE `user_proxy` ADD COLUMN `protocol` varchar(16) NOT NULL DEFAULT 'socks5' COMMENT 'Proxy protocol' AFTER `name`");
            }
            $available = true;
        } catch (\Throwable $e) {
            // If the database driver cannot run SHOW/ALTER here, migration can add the column.
            $available = false;
        }

        return $available;
    }
}
