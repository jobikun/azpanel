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
        $has_api_columns = $this->ensureApiColumns();
        $proxies = UserProxyModel::where('user_id', session('user_id'))
            ->order('id', 'desc')
            ->select();

        foreach ($proxies as $proxy) {
            $proxy->protocol = $has_protocol
                ? ProxyController::normalizeProxyProtocol((string) ($proxy->protocol ?? 'socks5'))
                : 'socks5';
            $proxy->source_type = $has_api_columns
                ? ProxyController::normalizeProxySourceType((string) ($proxy->source_type ?? 'manual'))
                : 'manual';
            $proxy->api_url = $has_api_columns ? (string) ($proxy->api_url ?? '') : '';
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
            $has_api_columns = $this->ensureApiColumns();
            $this->validateInput();
            $this->assertProtocolColumnAvailable($has_protocol);
            $this->assertApiColumnsAvailable($has_api_columns);

            if (input('is_default/d') === 1) {
                UserProxyModel::where('user_id', session('user_id'))->update(['is_default' => 0]);
            }

            $source_type = ProxyController::normalizeProxySourceType((string) input('source_type/s', 'manual'));
            $proxy = new UserProxyModel();
            $proxy->user_id = session('user_id');
            $proxy->name = trim(input('name/s'));
            if ($has_protocol) {
                $proxy->protocol = ProxyController::normalizeProxyProtocol((string) input('protocol/s', 'socks5'));
            }
            if ($has_api_columns) {
                $proxy->source_type = $source_type;
                $proxy->api_url = $source_type === 'api' ? trim(input('api_url/s', '')) : '';
            }
            $proxy->address = $source_type === 'api' ? '' : trim(input('address/s'));
            $proxy->port = $source_type === 'api' ? 0 : input('port/d');
            $proxy->username = $source_type === 'api' ? '' : trim(input('username/s', ''));
            $proxy->password = $source_type === 'api' ? '' : trim(input('password/s', ''));
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
            $has_api_columns = $this->ensureApiColumns();
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
            $this->assertApiColumnsAvailable($has_api_columns);
            if (input('is_default/d') === 1) {
                UserProxyModel::where('user_id', session('user_id'))->update(['is_default' => 0]);
            }

            $source_type = ProxyController::normalizeProxySourceType((string) input('source_type/s', 'manual'));
            $proxy->name = trim(input('name/s'));
            if ($has_protocol) {
                $proxy->protocol = ProxyController::normalizeProxyProtocol((string) input('protocol/s', 'socks5'));
            }
            if ($has_api_columns) {
                $proxy->source_type = $source_type;
                $proxy->api_url = $source_type === 'api' ? trim(input('api_url/s', '')) : '';
            }
            $proxy->address = $source_type === 'api' ? '' : trim(input('address/s'));
            $proxy->port = $source_type === 'api' ? 0 : input('port/d');
            $proxy->username = $source_type === 'api' ? '' : trim(input('username/s', ''));
            $proxy->password = $source_type === 'api' ? '' : trim(input('password/s', ''));
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
        if (trim(input('name/s')) === '') {
            throw new \InvalidArgumentException('代理名称不能为空');
        }

        $source_type = ProxyController::normalizeProxySourceType((string) input('source_type/s', 'manual'));
        if ($source_type === 'api') {
            $api_url = trim(input('api_url/s', ''));
            if (!filter_var($api_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $api_url)) {
                throw new \InvalidArgumentException('代理 API URL 必须是 http 或 https 地址');
            }
            return;
        }

        ProxyController::createProxyUrl(
            input('protocol/s', 'socks5'),
            input('address/s'),
            input('port/d'),
            input('username/s', ''),
            input('password/s', '')
        );
    }

    private function assertProtocolColumnAvailable(bool $has_protocol): void
    {
        $protocol = ProxyController::normalizeProxyProtocol((string) input('protocol/s', 'socks5'));
        if (! $has_protocol && $protocol !== 'socks5') {
            throw new \RuntimeException('数据库缺少 user_proxy.protocol 字段，请先执行迁移或手动添加该字段后再保存 HTTP 代理。');
        }
    }

    private function assertApiColumnsAvailable(bool $has_api_columns): void
    {
        $source_type = ProxyController::normalizeProxySourceType((string) input('source_type/s', 'manual'));
        if (! $has_api_columns && $source_type === 'api') {
            throw new \RuntimeException('数据库缺少 user_proxy.source_type/api_url 字段，请先执行迁移或手动添加字段后再保存 API 代理。');
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

    private function ensureApiColumns(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        try {
            $source_columns = Db::query("SHOW COLUMNS FROM `user_proxy` LIKE 'source_type'");
            if (empty($source_columns)) {
                Db::execute("ALTER TABLE `user_proxy` ADD COLUMN `source_type` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'Proxy source type' AFTER `protocol`");
            }

            $api_columns = Db::query("SHOW COLUMNS FROM `user_proxy` LIKE 'api_url'");
            if (empty($api_columns)) {
                Db::execute("ALTER TABLE `user_proxy` ADD COLUMN `api_url` text NULL COMMENT 'Proxy API URL' AFTER `source_type`");
            }
            $available = true;
        } catch (\Throwable $e) {
            $available = false;
        }

        return $available;
    }

    public function testApi()
    {
        try {
            $proxy = ProxyController::fetchProxyFromApi(
                trim(input('api_url/s', '')),
                (string) input('protocol/s', 'socks5')
            );
            $proxy_url = ProxyController::createProxyUrl(
                $proxy['protocol'],
                $proxy['address'],
                (int) $proxy['port'],
                $proxy['username'],
                $proxy['password']
            );
            $client = ProxyController::createGuzzleClient($proxy_url, [
                'timeout' => 8,
                'connect_timeout' => 5,
            ]);
            $response = $client->request('GET', 'https://myip.ipip.net');

            return json([
                'status' => true,
                'msg' => 'API 返回代理：' . $proxy['address'] . ':' . $proxy['port'] . "\n" . $response->getBody()->getContents(),
            ]);
        } catch (\Throwable $e) {
            return json([
                'status' => false,
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
