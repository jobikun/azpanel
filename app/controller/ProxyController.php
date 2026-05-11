<?php

namespace app\controller;

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

    public function test()
    {
        try {
            $client = new Client(self::createGuzzleOptions(self::createSocks5ProxyUrlFromInput()));
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
