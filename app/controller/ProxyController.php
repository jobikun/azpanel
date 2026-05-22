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

        return "socks5://{$auth}{$addr}:{$port}";
    }

    public static function createSocks5ProxyUrlFromInput(): string
    {
        return self::createSocks5ProxyUrl(
            input('socks5_address/s'),
            input('socks5_port/d'),
            input('socks5_user/s'),
            input('socks5_passwd/s')
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
        if (input('socks5_switch') === 'true') {
            return self::createSocks5ProxyUrlFromInput();
        }

        return self::getDefaultProxyUrl($user_id);
    }

    public static function createGuzzleOptions(string $proxy_url): array
    {
        return [
            'proxy' => $proxy_url,
            'timeout' => 5,
            'connect_timeout' => 5,
        ];
    }

    public static function createAwsHttpOptions(string $proxy_url): array
    {
        return [
            'proxy' => $proxy_url,
            'connect_timeout' => 5,
        ];
    }

    public static function createGuzzleClient(?string $proxy_url = null, array $options = []): Client
    {
        $proxy_url = $proxy_url ?? self::getDefaultProxyUrl();
        if ($proxy_url !== null) {
            $options = array_replace_recursive($options, self::createGuzzleOptions($proxy_url));
        }

        return new Client($options);
    }

    public function test()
    {
        try {
            $proxy_id = input('proxy_id/d', 0);
            if ($proxy_id > 0) {
                $proxy = UserProxy::where('user_id', session('user_id'))->find($proxy_id);
                if ($proxy === null) {
                    throw new \InvalidArgumentException('Proxy not found.');
                }
                $proxy_url = self::createSocks5ProxyUrlFromRecord($proxy);
            } else {
                $proxy_url = self::createSocks5ProxyUrlFromInput();
            }

            $client = new Client(self::createGuzzleOptions($proxy_url));
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
