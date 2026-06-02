<?php

namespace app\controller;

use app\model\UserProxy;
use GuzzleHttp\Client;

class ProxyController extends UserBase
{
    public static function createSocks5ProxyUrl(string $addr, int $port, string $user = '', string $passwd = ''): string
    {
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

        return "socks5h://{$auth}{$addr}:{$port}";
    }

    public static function createSocks5ProxyUrlFromInput(): string
    {
        return self::createSocks5ProxyUrl(
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
        return self::createSocks5ProxyUrl(
            $proxy->address,
            (int) $proxy->port,
            $proxy->username ?? '',
            $proxy->password ?? ''
        );
    }

    public static function getDefaultProxyUrl(?int $user_id = null): ?string
    {
        $proxy = self::getDefaultProxy($user_id);
        if ($proxy === null) {
            return null;
        }

        return self::createSocks5ProxyUrlFromRecord($proxy);
    }

    public static function getProxyUrlFromInputOrDefault(?int $user_id = null): ?string
    {
        $proxy_mode = trim((string) input('proxy_mode/s', ''));
        $proxy_id = input('proxy_id/d', 0);
        $user_id = $user_id ?? (int) session('user_id');

        if ($proxy_mode === '' || $proxy_mode === 'none') {
            return null;
        }

        $manual_enabled = $proxy_mode === 'manual' || input('socks5_switch') === 'true' || input('socks5_switch') === true;
        if ($manual_enabled && trim((string) input('socks5_address/s', '')) !== '') {
            return self::createSocks5ProxyUrlFromInput();
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

            return self::createSocks5ProxyUrlFromRecord($proxy);
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
                $proxy_url = self::createSocks5ProxyUrlFromInput();
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
