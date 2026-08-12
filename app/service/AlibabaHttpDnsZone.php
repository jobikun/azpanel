<?php
declare(strict_types=1);

namespace app\service;

use AlibabaCloud\Client\AlibabaCloud;
use app\controller\ProxyController;
use app\model\Config;

/** Alibaba Cloud international HTTPDNS Authoritative Zone API. */
class AlibabaHttpDnsZone
{
    private const VERSION = '2015-01-09';
    private const ENDPOINT = 'alidns.aliyuncs.com';

    public static function zones(): array
    {
        $response = self::call('ListRecursionZones', ['PageNumber' => 1, 'PageSize' => 100]);
        return self::items($response, ['RecursionZones.RecursionZone', 'Zones.Zone', 'RecursionZones', 'Zones']);
    }

    public static function connectionInfo(): array
    {
        $configs = Config::group('resolv');
        $binding = self::proxyBinding($configs);
        $zones = self::zones();

        return [
            'endpoint' => self::ENDPOINT,
            'proxy' => ProxyController::getProxyLabelForAccount($binding),
            'proxy_enabled' => (int) $binding->proxy_id !== 0,
            'zone_count' => count($zones),
        ];
    }

    public static function zone(string $zoneId): array
    {
        return self::call('DescribeRecursionZone', ['ZoneId' => $zoneId]);
    }

    public static function addZone(string $name, string $proxyPattern): array
    {
        if (!filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException('Invalid zone name');
        }
        return self::call('AddRecursionZone', ['ZoneName' => strtolower($name), 'ProxyPattern' => self::proxyPattern($proxyPattern), 'ClientToken' => self::token()]);
    }

    public static function updateZone(string $zoneId, string $remark, string $proxyPattern): void
    {
        self::call('UpdateRecursionZoneRemark', ['ZoneId' => $zoneId, 'Remark' => $remark, 'ClientToken' => self::token()]);
        self::call('UpdateRecursionZoneProxyPattern', ['ZoneId' => $zoneId, 'ProxyPattern' => self::proxyPattern($proxyPattern), 'ClientToken' => self::token()]);
    }

    public static function updateEffectiveScope(string $zoneId, array $accountIds): array
    {
        $query = ['ZoneId' => $zoneId, 'ClientToken' => self::token()];
        if ($accountIds !== []) { $query['EffectiveScopes.1.EffectiveType'] = 'account'; }
        foreach (array_values(array_unique(array_filter(array_map('trim', $accountIds)))) as $index => $accountId) {
            if (!preg_match('/^[0-9]+$/', $accountId)) {
                throw new \InvalidArgumentException('HTTPDNS Account ID must contain digits only');
            }
            $query['EffectiveScopes.1.Scope.' . ($index + 1)] = $accountId;
        }
        return self::call('UpdateRecursionZoneEffectiveScope', $query);
    }

    public static function deleteZone(string $zoneId): array
    {
        return self::call('DeleteRecursionZone', ['ZoneId' => $zoneId]);
    }

    public static function records(string $zoneId): array
    {
        $response = self::call('ListRecursionRecords', ['ZoneId' => $zoneId, 'PageNumber' => 1, 'PageSize' => 100]);
        return self::items($response, ['Records.Record', 'RecursionRecords.RecursionRecord', 'Records', 'RecursionRecords']);
    }

    public static function addRecord(string $zoneId, array $record): array
    {
        return self::call('AddRecursionRecord', self::recordParameters($record) + ['ZoneId' => $zoneId, 'Remark' => trim((string) ($record['remark'] ?? '')), 'ClientToken' => self::token()]);
    }

    public static function updateRecord(string $recordId, array $record): array
    {
        $result = self::call('UpdateRecursionRecord', self::recordParameters($record) + ['RecordId' => $recordId, 'ClientToken' => self::token()]);
        self::call('UpdateRecursionRecordRemark', ['RecordId' => $recordId, 'Remark' => trim((string) ($record['remark'] ?? '')), 'ClientToken' => self::token()]);
        return $result;
    }

    public static function setStatus(string $recordId, string $status): array
    {
        return self::call('UpdateRecursionRecordEnableStatus', ['RecordId' => $recordId, 'EnableStatus' => strtolower($status) === 'enable' ? 'enable' : 'disable', 'ClientToken' => self::token()]);
    }

    public static function deleteRecord(string $recordId): array
    {
        return self::call('DeleteRecursionRecord', ['RecordId' => $recordId]);
    }

    private static function recordParameters(array $record): array
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        if (!in_array($type, ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'SRV'], true)) {
            throw new \InvalidArgumentException('Unsupported DNS record type');
        }
        $rr = trim((string) ($record['rr'] ?? ''));
        $value = trim((string) ($record['value'] ?? ''));
        $ttl = (int) ($record['ttl'] ?? 60);
        if ($rr === '' || $value === '' || !in_array($ttl, [5, 30, 60, 3600, 43200, 86400], true)) {
            throw new \InvalidArgumentException('Invalid host record, value, or TTL');
        }
        $parameters = ['Rr' => $rr, 'Type' => $type, 'Value' => $value, 'Ttl' => $ttl, 'RequestSource' => trim((string) ($record['line'] ?? 'default')) ?: 'default', 'Weight' => max(1, min(100, (int) ($record['weight'] ?? 1)))];
        if ($type === 'MX') { $parameters['Priority'] = max(1, min(99, (int) ($record['priority'] ?? 1))); }
        return $parameters;
    }

    private static function call(string $action, array $query): array
    {
        $configs = Config::group('resolv');
        $accessKey = trim((string) ($configs['ali_ak'] ?? ''));
        $secret = trim((string) ($configs['ali_sk'] ?? ''));
        if ($accessKey === '' || $secret === '') { throw new \RuntimeException('Configure Alibaba Cloud AccessKey ID and Secret in DNS settings first'); }
        $client = AlibabaCloud::accessKeyClient($accessKey, $secret)->regionId('cn-hangzhou')->connectTimeout(15)->timeout(60);
        $proxyUrl = ProxyController::getProxyUrlForAccount(self::proxyBinding($configs));
        if ($proxyUrl !== null) { $client->proxy($proxyUrl); }
        $client->asDefaultClient();
        return AlibabaCloud::rpc()->product('Alidns')->version(self::VERSION)->action($action)->method('POST')->host(self::ENDPOINT)->options(['query' => $query])->request()->toArray();
    }

    private static function items(array $response, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $response;
            foreach (explode('.', $path) as $part) { if (!is_array($value) || !array_key_exists($part, $value)) { $value = null; break; } $value = $value[$part]; }
            if (is_array($value)) { return self::isList($value) ? $value : [$value]; }
        }
        return [];
    }

    private static function proxyPattern(string $value): string { return strtolower($value) === 'record' ? 'record' : 'zone'; }
    private static function token(): string { return bin2hex(random_bytes(16)); }
    private static function isList(array $value): bool { return $value === [] || array_keys($value) === range(0, count($value) - 1); }
    private static function proxyBinding(array $configs): object
    {
        $ownerId = (int) ($configs['ali_httpdns_proxy_user_id'] ?? 0);
        return (object) ['proxy_id' => (int) ($configs['ali_httpdns_proxy_id'] ?? 0), 'user_id' => $ownerId > 0 ? $ownerId : (int) session('user_id')];
    }
}
