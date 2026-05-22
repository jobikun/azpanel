<?php

namespace app\controller;

use GuzzleHttp\Client;

class LinodeApi
{
    private Client $client;

    public function __construct(string $token, ?string $proxy_url = null)
    {
        $options = [
            'base_uri' => 'https://api.linode.com/v4/',
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . trim($token),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        if ($proxy_url !== null) {
            $options['proxy'] = $proxy_url;
        }

        $this->client = new Client($options);
    }

    public function request(string $method, string $uri, array $json = []): array
    {
        $options = [];
        if ($json !== []) {
            $options['json'] = $json;
        }

        $response = $this->client->request($method, ltrim($uri, '/'), $options);
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        return json_decode($body, true) ?: [];
    }

    public function profile(): array
    {
        return $this->request('GET', 'profile');
    }

    public function listInstances(): array
    {
        return $this->request('GET', 'linode/instances');
    }

    public function createInstance(array $params): array
    {
        return $this->request('POST', 'linode/instances', $params);
    }

    public function deleteInstance(int $id): array
    {
        return $this->request('DELETE', 'linode/instances/' . $id);
    }

    public function action(int $id, string $action): array
    {
        return $this->request('POST', 'linode/instances/' . $id . '/' . $action);
    }
}
