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
            $proxy->api_key = $has_api_columns ? (string) ($proxy->api_key ?? '') : '';
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
                $proxy->api_key = $source_type === 'api' ? trim(input('api_key/s', '')) : '';
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
                $proxy->api_key = $source_type === 'api' ? trim(input('api_key/s', '')) : '';
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
            $api_key = trim(input('api_key/s', ''));
            if ($api_url === '' && $api_key !== '') {
                return; // 仅填 API Key 时默认使用 Webshare 列表接口
            }
            if (!filter_var($api_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $api_url)) {
                throw new \InvalidArgumentException('代理 API URL 必须是 http 或 https 地址（Webshare 可只填 API Key）');
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

            $key_columns = Db::query("SHOW COLUMNS FROM `user_proxy` LIKE 'api_key'");
            if (empty($key_columns)) {
                Db::execute("ALTER TABLE `user_proxy` ADD COLUMN `api_key` varchar(255) NOT NULL DEFAULT '' COMMENT 'Proxy API key' AFTER `api_url`");
            }
            $available = true;
        } catch (\Throwable $e) {
            $available = false;
        }

        return $available;
    }

    /**
     * 批量导入代理：每行一条，支持 curl 命令、URL、user:pass@host:port、
     * host:port:user:pass、host:port 等格式。
     */
    public function importProxies()
    {
        try {
            $has_protocol = $this->ensureProtocolColumn();
            $has_api_columns = $this->ensureApiColumns();

            $content = (string) input('content/s', '');
            $lines = preg_split('/[\r\n]+/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($lines === []) {
                throw new \InvalidArgumentException('导入内容不能为空');
            }
            if (count($lines) > 500) {
                throw new \InvalidArgumentException('单次最多导入 500 行');
            }

            $default_protocol = ProxyController::normalizeProxyProtocol((string) input('protocol/s', 'http'));
            $name_prefix = trim(input('name_prefix/s', ''));
            $enabled = input('enabled/d', 1) ? 1 : 0;

            $parsed_list = [];
            $failed = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                $parsed = ProxyController::parseProxyImportLine($line, $default_protocol);
                if ($parsed === null) {
                    $failed[] = $line;
                    continue;
                }

                try {
                    // 复用地址/端口校验
                    ProxyController::createProxyUrl(
                        $parsed['protocol'],
                        $parsed['address'],
                        (int) $parsed['port'],
                        $parsed['username'],
                        $parsed['password']
                    );
                } catch (\Throwable $e) {
                    $failed[] = $line;
                    continue;
                }

                $parsed_list[] = $parsed;
            }

            if (! $has_protocol) {
                foreach ($parsed_list as $parsed) {
                    if ($parsed['protocol'] !== 'socks5') {
                        throw new \RuntimeException('数据库缺少 user_proxy.protocol 字段，请先执行迁移后再导入 HTTP 代理。');
                    }
                }
            }

            [$imported, $duplicated] = $this->insertProxies($parsed_list, $name_prefix, $enabled, $has_protocol, $has_api_columns);

            $detail = '成功导入 ' . $imported . ' 条';
            if ($duplicated > 0) {
                $detail .= '，跳过重复 ' . $duplicated . ' 条';
            }
            if ($failed !== []) {
                $samples = array_map(
                    static fn ($line) => htmlspecialchars(mb_substr($line, 0, 80), ENT_QUOTES),
                    array_slice($failed, 0, 5)
                );
                $detail .= '，无法解析 ' . count($failed) . ' 行：<br>' . implode('<br>', $samples);
            }

            return json(Tools::msg($imported > 0 ? '1' : '0', '导入完成', $detail));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '导入失败', $e->getMessage()));
        }
    }

    /**
     * 一键导入 Webshare 账户下的全部代理（https://apidocs.webshare.io/）。
     */
    public function importWebshare()
    {
        try {
            $has_protocol = $this->ensureProtocolColumn();
            $has_api_columns = $this->ensureApiColumns();

            $protocol = ProxyController::normalizeProxyProtocol((string) input('protocol/s', 'http'));
            if (! $has_protocol && $protocol !== 'socks5') {
                throw new \RuntimeException('数据库缺少 user_proxy.protocol 字段，请先执行迁移后再导入 HTTP 代理。');
            }

            $name_prefix = trim(input('name_prefix/s', 'Webshare'));
            $enabled = input('enabled/d', 1) ? 1 : 0;
            $list = ProxyController::fetchWebshareProxies(trim(input('api_key/s', '')));
            if ($list === []) {
                throw new \RuntimeException('Webshare 账户中没有可用代理');
            }

            $parsed_list = [];
            foreach ($list as $item) {
                $parsed_list[] = [
                    'protocol' => $protocol,
                    'address' => $item['address'],
                    'port' => $item['port'],
                    'username' => $item['username'],
                    'password' => $item['password'],
                    'country' => $item['country'],
                ];
            }

            [$imported, $duplicated] = $this->insertProxies($parsed_list, $name_prefix, $enabled, $has_protocol, $has_api_columns);

            $detail = 'Webshare 共返回 ' . count($list) . ' 条代理，成功导入 ' . $imported . ' 条';
            if ($duplicated > 0) {
                $detail .= '，跳过重复 ' . $duplicated . ' 条';
            }

            return json(Tools::msg($imported > 0 ? '1' : '0', '导入完成', $detail));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '导入失败', $e->getMessage()));
        }
    }

    /**
     * 批量写入代理（对已有记录和本批内数据去重），返回 [导入数, 重复数]。
     */
    private function insertProxies(array $parsed_list, string $name_prefix, int $enabled, bool $has_protocol, bool $has_api_columns): array
    {
        $seen = [];
        $existing = UserProxyModel::where('user_id', session('user_id'))->select();
        foreach ($existing as $item) {
            $key = implode('|', [
                ProxyController::normalizeProxyProtocol((string) ($item->protocol ?? 'socks5')),
                (string) $item->address,
                (int) $item->port,
                (string) ($item->username ?? ''),
            ]);
            $seen[$key] = true;
        }

        $imported = 0;
        $duplicated = 0;
        foreach ($parsed_list as $parsed) {
            $key = implode('|', [
                $parsed['protocol'],
                $parsed['address'],
                (int) $parsed['port'],
                $parsed['username'],
            ]);
            if (isset($seen[$key])) {
                $duplicated++;
                continue;
            }
            $seen[$key] = true;

            $name = $parsed['address'] . ':' . $parsed['port'];
            if (!empty($parsed['country'])) {
                $name = $parsed['country'] . '-' . $name;
            }
            if ($name_prefix !== '') {
                $name = $name_prefix . '-' . $name;
            }

            $proxy = new UserProxyModel();
            $proxy->user_id = session('user_id');
            $proxy->name = $name;
            if ($has_protocol) {
                $proxy->protocol = $parsed['protocol'];
            }
            if ($has_api_columns) {
                $proxy->source_type = 'manual';
                $proxy->api_url = '';
                $proxy->api_key = '';
            }
            $proxy->address = $parsed['address'];
            $proxy->port = (int) $parsed['port'];
            $proxy->username = $parsed['username'];
            $proxy->password = $parsed['password'];
            $proxy->enabled = $enabled;
            $proxy->is_default = 0;
            $proxy->created_at = time();
            $proxy->updated_at = time();
            $proxy->save();
            $imported++;
        }

        return [$imported, $duplicated];
    }

    public function testApi()
    {
        try {
            $proxy = ProxyController::fetchProxyFromApi(
                trim(input('api_url/s', '')),
                (string) input('protocol/s', 'socks5'),
                trim(input('api_key/s', ''))
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
