<?php

namespace app\controller;

use app\model\UserProxy;
use GuzzleHttp\Client;

class ProxyController extends UserBase
{
    public static function normalizeProxyProtocol(string $protocol): string
    {
        $protocol = strtolower(trim($protocol));
        if (in_array($protocol, ['http', 'https'], true)) {
            return 'http';
        }
        if (in_array($protocol, ['socks5', 'socks5h', 'socks'], true)) {
            return 'socks5';
        }

        return 'socks5';
    }

    public static function normalizeProxySourceType(string $source_type): string
    {
        return strtolower(trim($source_type)) === 'api' ? 'api' : 'manual';
    }

    public static function normalizeProxyRecords($proxies)
    {
        foreach ($proxies as $proxy) {
            $proxy->protocol = self::normalizeProxyProtocol((string) ($proxy->protocol ?? 'socks5'));
            $proxy->source_type = self::normalizeProxySourceType((string) ($proxy->source_type ?? 'manual'));
            $proxy->api_url = (string) ($proxy->api_url ?? '');
        }

        return $proxies;
    }

    public static function createProxyUrl(
        string $protocol,
        string $addr,
        int $port,
        string $user = '',
        string $passwd = ''
    ): string {
        $protocol = self::normalizeProxyProtocol($protocol);
        $addr = trim($addr);
        $user = trim($user);

        if ($addr === '' || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('代理服务器参数不正确');
        }

        if (strpos($addr, ':') !== false && $addr[0] !== '[') {
            $addr = '[' . $addr . ']';
        }

        $auth = '';
        if ($user !== '' || $passwd !== '') {
            $auth = rawurlencode($user);
            if ($passwd !== '') {
                $auth .= ':' . rawurlencode($passwd);
            }
            $auth .= '@';
        }

        $scheme = $protocol === 'http' ? 'http' : 'socks5h';
        return "{$scheme}://{$auth}{$addr}:{$port}";
    }

    public static function createSocks5ProxyUrl(string $addr, int $port, string $user = '', string $passwd = ''): string
    {
        return self::createProxyUrl('socks5', $addr, $port, $user, $passwd);
    }

    public static function createSocks5ProxyUrlFromInput(): string
    {
        return self::createProxyUrlFromInput();
    }

    public static function createProxyUrlFromInput(): string
    {
        return self::createProxyUrl(
            (string) input('proxy_protocol/s', 'socks5'),
            (string) input('socks5_address/s', ''),
            (int) input('socks5_port/d', 0),
            (string) input('socks5_user/s', ''),
            (string) input('socks5_passwd/s', '')
        );
    }

    public static function getDefaultProxy(?int $user_id = null): ?UserProxy
    {
        $user_id = $user_id ?? (int) session('user_id');
        if ($user_id <= 0) {
            return null;
        }

        $proxy = UserProxy::where('user_id', $user_id)
            ->where('enabled', 1)
            ->where('is_default', 1)
            ->order('id', 'desc')
            ->find();

        if ($proxy !== null) {
            return $proxy;
        }

        return UserProxy::where('user_id', $user_id)
            ->where('enabled', 1)
            ->order('id', 'desc')
            ->find();
    }

    public static function createSocks5ProxyUrlFromRecord(UserProxy $proxy): string
    {
        return self::createProxyUrlFromRecord($proxy);
    }

    public static function createProxyUrlFromRecord(UserProxy $proxy): string
    {
        if (self::normalizeProxySourceType((string) ($proxy->source_type ?? 'manual')) === 'api') {
            $api_proxy = self::fetchProxyFromApi(
                (string) ($proxy->api_url ?? ''),
                (string) ($proxy->protocol ?? 'socks5')
            );

            return self::createProxyUrl(
                $api_proxy['protocol'],
                $api_proxy['address'],
                (int) $api_proxy['port'],
                $api_proxy['username'],
                $api_proxy['password']
            );
        }

        return self::createProxyUrl(
            (string) ($proxy->protocol ?? 'socks5'),
            $proxy->address,
            (int) $proxy->port,
            $proxy->username ?? '',
            $proxy->password ?? ''
        );
    }

    private static function formatProxyLabel(UserProxy $proxy, string $prefix): string
    {
        $name = trim((string) ($proxy->name ?? ''));
        $address = trim((string) ($proxy->address ?? ''));
        $port = (int) ($proxy->port ?? 0);
        $label = $name !== '' ? $name : ('#' . $proxy->id);
        $protocol = strtoupper(self::normalizeProxyProtocol((string) ($proxy->protocol ?? 'socks5')));
        $source_type = self::normalizeProxySourceType((string) ($proxy->source_type ?? 'manual'));

        if ($source_type === 'api') {
            return $prefix . ': ' . $label . ' [' . $protocol . ' API]';
        }

        return $prefix . ': ' . $label . ' [' . $protocol . '] (' . $address . ':' . $port . ')';
    }

    public static function getProxyLabelFromInput(?int $user_id = null): string
    {
        $proxy_mode = trim((string) input('proxy_mode/s', ''));
        $proxy_id = input('proxy_id/d', 0);
        $user_id = $user_id ?? (int) session('user_id');

        if ($proxy_mode === 'none' || ($proxy_mode === '' && $proxy_id <= 0)) {
            return 'No proxy';
        }

        $manual_enabled = $proxy_mode === 'manual' || input('socks5_switch') === 'true' || input('socks5_switch') === true;
        if ($manual_enabled) {
            $address = trim((string) input('socks5_address/s', ''));
            $port = (int) input('socks5_port/d', 0);
            $protocol = strtoupper(self::normalizeProxyProtocol((string) input('proxy_protocol/s', 'socks5')));

            return $address !== '' && $port > 0 ? ('Manual proxy [' . $protocol . ']: ' . $address . ':' . $port) : 'Manual proxy (missing address)';
        }

        if ($proxy_id > 0 || str_starts_with($proxy_mode, 'pool:')) {
            if ($proxy_id <= 0) {
                $proxy_id = (int) substr($proxy_mode, 5);
            }

            $proxy = UserProxy::where('user_id', $user_id)
                ->where('enabled', 1)
                ->find($proxy_id);

            return $proxy === null ? ('Proxy pool #' . $proxy_id . ' (unavailable)') : self::formatProxyLabel($proxy, 'Proxy pool');
        }

        if ($proxy_mode === 'default') {
            $proxy = self::getDefaultProxy($user_id);
            return $proxy === null ? 'Default proxy pool (no available proxy)' : self::formatProxyLabel($proxy, 'Default proxy pool');
        }

        return 'No proxy';
    }

    public static function getDefaultProxyUrl(?int $user_id = null): ?string
    {
        $proxy = self::getDefaultProxy($user_id);
        if ($proxy === null) {
            return null;
        }

        return self::createProxyUrlFromRecord($proxy);
    }

    /**
     * 根据账号记录解析其绑定的代理 URL。
     * proxy_id 约定：0=不使用代理，-1=跟随默认代理，正数=具体 user_proxy.id
     */
    public static function getProxyUrlForAccount($account): ?string
    {
        $proxy_id = (int) ($account->proxy_id ?? 0);
        $user_id = (int) ($account->user_id ?? session('user_id'));

        if ($proxy_id === 0) {
            return null; // 不使用代理
        }

        if ($proxy_id === -1) {
            return self::getDefaultProxyUrl($user_id); // 跟随默认代理
        }

        $proxy = UserProxy::where('user_id', $user_id)
            ->where('enabled', 1)
            ->find($proxy_id);
        if ($proxy === null) {
            throw new \InvalidArgumentException('账号绑定的代理不存在或已被禁用');
        }

        return self::createProxyUrlFromRecord($proxy);
    }

    /**
     * 账号绑定代理的可读标签（用于创建记录等展示）。
     */
    public static function getProxyLabelForAccount($account): string
    {
        $proxy_id = (int) ($account->proxy_id ?? 0);
        $user_id = (int) ($account->user_id ?? session('user_id'));

        if ($proxy_id === 0) {
            return 'No proxy';
        }

        if ($proxy_id === -1) {
            $proxy = self::getDefaultProxy($user_id);
            return $proxy === null ? 'Default proxy pool (no available proxy)' : self::formatProxyLabel($proxy, 'Default proxy pool');
        }

        $proxy = UserProxy::where('user_id', $user_id)
            ->where('enabled', 1)
            ->find($proxy_id);

        return $proxy === null ? ('Proxy #' . $proxy_id . ' (unavailable)') : self::formatProxyLabel($proxy, 'Proxy');
    }

    /**
     * 提供给账号添加/编辑表单的代理下拉列表（当前用户启用的代理）。
     */
    public static function getProxyOptionsForUser(?int $user_id = null)
    {
        $user_id = $user_id ?? (int) session('user_id');
        $proxies = UserProxy::where('user_id', $user_id)
            ->where('enabled', 1)
            ->order('id', 'desc')
            ->select();

        return self::normalizeProxyRecords($proxies);
    }

    /**
     * 把表单提交的绑定代理值规范化为可落库的 proxy_id。
     * 允许 0(不用) / -1(默认) / 归属当前用户且启用的具体代理 id；非法值按 0 处理。
     */
    public static function normalizeBoundProxyId($raw, ?int $user_id = null): int
    {
        $proxy_id = (int) $raw;
        $user_id = $user_id ?? (int) session('user_id');

        if ($proxy_id === 0 || $proxy_id === -1) {
            return $proxy_id;
        }

        if ($proxy_id < 0) {
            return 0;
        }

        $exists = UserProxy::where('user_id', $user_id)
            ->where('enabled', 1)
            ->find($proxy_id);

        return $exists === null ? 0 : $proxy_id;
    }

    public static function getProxyUrlFromInputOrDefault(?int $user_id = null): ?string
    {
        $proxy_mode = trim((string) input('proxy_mode/s', ''));
        $proxy_id = input('proxy_id/d', 0);
        $user_id = $user_id ?? (int) session('user_id');

        if ($proxy_mode === 'none' || ($proxy_mode === '' && $proxy_id <= 0)) {
            return null;
        }

        $manual_enabled = $proxy_mode === 'manual' || input('socks5_switch') === 'true' || input('socks5_switch') === true;
        if ($manual_enabled && trim((string) input('socks5_address/s', '')) !== '') {
            return self::createProxyUrlFromInput();
        }

        if ($proxy_id > 0 || str_starts_with($proxy_mode, 'pool:')) {
            if ($proxy_id <= 0) {
                $proxy_id = (int) substr($proxy_mode, 5);
            }

            $proxy = UserProxy::where('user_id', $user_id)
                ->where('enabled', 1)
                ->find($proxy_id);
            if ($proxy === null) {
                throw new \InvalidArgumentException('Selected proxy was not found or disabled.');
            }

            return self::createProxyUrlFromRecord($proxy);
        }

        if ($proxy_mode === 'default') {
            return self::getDefaultProxyUrl($user_id);
        }

        return null;
    }

    public static function createGuzzleOptions(?string $proxy_url = null, int $timeout = 60, int $connect_timeout = 15): array
    {
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => $connect_timeout,
        ];

        if ($proxy_url !== null) {
            $options['proxy'] = $proxy_url;
        }

        return $options;
    }

    public static function createAwsHttpOptions(string $proxy_url): array
    {
        return [
            'proxy' => $proxy_url,
            'connect_timeout' => 5,
        ];
    }

    public static function createGuzzleClient(?string $proxy_url = null, array $options = [], bool $use_default_proxy = false): Client
    {
        if ($proxy_url === null && $use_default_proxy) {
            $proxy_url = self::getDefaultProxyUrl();
        }

        return new Client(array_replace_recursive(self::createGuzzleOptions($proxy_url), $options));
    }

    public static function fetchProxyFromApi(string $api_url, string $default_protocol = 'socks5'): array
    {
        $api_url = trim($api_url);
        if (!filter_var($api_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $api_url)) {
            throw new \InvalidArgumentException('代理 API URL 必须是 http 或 https 地址');
        }

        $client = new Client([
            'timeout' => 15,
            'connect_timeout' => 8,
            'http_errors' => false,
        ]);
        $response = $client->request('GET', $api_url);
        $status = (int) $response->getStatusCode();
        $body = trim((string) $response->getBody());
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('代理 API 请求失败，HTTP ' . $status);
        }

        $candidates = self::extractProxyCandidates($body);
        foreach ($candidates as $candidate) {
            $proxy = self::parseProxyEndpoint($candidate, $default_protocol);
            if ($proxy !== null) {
                return $proxy;
            }
        }

        throw new \RuntimeException('代理 API 没有返回可解析的代理地址');
    }

    private static function extractProxyCandidates(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded) && !empty($decoded['error'])) {
                throw new \RuntimeException((string) ($decoded['message'] ?? '代理 API 返回错误'));
            }

            $items = self::collectProxyStrings($decoded);
            if (!empty($items)) {
                return $items;
            }
        }

        return preg_split('/[\r\n,]+/', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function collectProxyStrings($value): array
    {
        $items = [];
        if (is_string($value)) {
            $items[] = $value;
            return $items;
        }

        if (!is_array($value)) {
            return $items;
        }

        $host = self::firstArrayValue($value, ['host', 'ip', 'address', 'server']);
        $port = self::firstArrayValue($value, ['port']);
        if ($host !== null && $port !== null) {
            $user = self::firstArrayValue($value, ['username', 'user', 'login']);
            $pass = self::firstArrayValue($value, ['password', 'pass', 'passwd']);
            $protocol = self::firstArrayValue($value, ['protocol', 'scheme', 'type']);
            $auth = $user !== null ? rawurlencode((string) $user) . ($pass !== null ? ':' . rawurlencode((string) $pass) : '') . '@' : '';
            $scheme = $protocol !== null ? self::normalizeProxyProtocol((string) $protocol) . '://' : '';
            $items[] = $scheme . $auth . $host . ':' . $port;
        }

        foreach ($value as $entry) {
            $items = array_merge($items, self::collectProxyStrings($entry));
        }

        return $items;
    }

    private static function firstArrayValue(array $value, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($value[$key]) && $value[$key] !== '') {
                return $value[$key];
            }
        }

        return null;
    }

    private static function parseProxyEndpoint(string $raw, string $default_protocol): ?array
    {
        $raw = trim($raw, " \t\n\r\0\x0B\"'");
        if ($raw === '') {
            return null;
        }

        $protocol = self::normalizeProxyProtocol($default_protocol);
        $username = '';
        $password = '';

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $raw)) {
            $url = parse_url($raw);
            if (!is_array($url) || empty($url['host']) || empty($url['port'])) {
                return null;
            }

            return [
                'protocol' => self::normalizeProxyProtocol((string) ($url['scheme'] ?? $protocol)),
                'address' => (string) $url['host'],
                'port' => (int) $url['port'],
                'username' => isset($url['user']) ? rawurldecode((string) $url['user']) : '',
                'password' => isset($url['pass']) ? rawurldecode((string) $url['pass']) : '',
            ];
        }

        if (strpos($raw, '@') !== false) {
            [$auth, $host_port] = explode('@', $raw, 2);
            [$username, $password] = array_pad(explode(':', $auth, 2), 2, '');
            $parts = self::splitHostPort($host_port);
            if ($parts === null) {
                return null;
            }

            return [
                'protocol' => $protocol,
                'address' => $parts['address'],
                'port' => $parts['port'],
                'username' => rawurldecode($username),
                'password' => rawurldecode($password),
            ];
        }

        $parts = explode(':', $raw);
        if (count($parts) >= 4 && is_numeric($parts[1])) {
            return [
                'protocol' => $protocol,
                'address' => trim($parts[0]),
                'port' => (int) $parts[1],
                'username' => trim($parts[2]),
                'password' => trim(implode(':', array_slice($parts, 3))),
            ];
        }

        $host_port = self::splitHostPort($raw);
        if ($host_port === null) {
            return null;
        }

        return [
            'protocol' => $protocol,
            'address' => $host_port['address'],
            'port' => $host_port['port'],
            'username' => '',
            'password' => '',
        ];
    }

    private static function splitHostPort(string $value): ?array
    {
        $value = trim($value);
        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $value, $matches)) {
            return [
                'address' => $matches[1],
                'port' => (int) $matches[2],
            ];
        }

        $pos = strrpos($value, ':');
        if ($pos === false) {
            return null;
        }

        $address = trim(substr($value, 0, $pos));
        $port = (int) substr($value, $pos + 1);
        if ($address === '' || $port < 1 || $port > 65535) {
            return null;
        }

        return [
            'address' => $address,
            'port' => $port,
        ];
    }

    public function test()
    {
        try {
            $proxy_id = input('proxy_id/d', 0);
            $proxy_mode = input('proxy_mode/s', '');
            if ($proxy_id > 0 || $proxy_mode !== '') {
                $proxy_url = self::getProxyUrlFromInputOrDefault();
            } else {
                $proxy_url = self::createProxyUrlFromInput();
            }

            $client = new Client(self::createGuzzleOptions($proxy_url, 5, 5));
            $response = $client->request('GET', 'https://myip.ipip.net');
            $statusCode = (int) $response->getStatusCode();

            return json([
                'status' => $statusCode !== 204 ? true : false,
                'msg' => $response->getBody()->getContents(),
            ]);
        } catch (\Exception $e) {
            return json([
                'status' => false,
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
