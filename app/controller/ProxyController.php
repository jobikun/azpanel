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

    public static function normalizeProxyRecords($proxies)
    {
        foreach ($proxies as $proxy) {
            $proxy->protocol = self::normalizeProxyProtocol((string) ($proxy->protocol ?? 'socks5'));
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
