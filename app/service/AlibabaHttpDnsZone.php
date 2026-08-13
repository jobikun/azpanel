<?php
declare(strict_types=1);

namespace app\service;

use AlibabaCloud\Client\AlibabaCloud;
use app\controller\ProxyController;
use app\model\AlibabaAccount;

class AlibabaHttpDnsZone
{
    private const VERSION = '2015-01-09';
    private const ENDPOINT = 'alidns.aliyuncs.com';
    private const SCHEME = 'https';
    private array $recordCache = [];

    public function __construct(private AlibabaAccount $account)
    {
    }

    public function connectionInfo(): array
    {
        $zones = $this->zones();
        return ['endpoint' => self::SCHEME . '://' . self::ENDPOINT, 'proxy' => ProxyController::getProxyLabelForAccountZh($this->account), 'zone_count' => count($zones)];
    }

    public function dohAccessInfo(): array
    {
        $storedEndpoint = self::normalizeDohEndpoint((string) ($this->account->doh_endpoint ?? ''));
        $response = [];
        $lookupError = null;
        try {
            $response = $this->call('DescribePdnsUserInfo', ['Lang' => 'en']);
        } catch (\Throwable $primaryError) {
            try {
                $response = $this->call('DescribeDohUserInfo', ['Lang' => 'en']);
            } catch (\Throwable) {
                $lookupError = $primaryError;
            }
        }

        $userInfo = is_array($response['UserInfo'] ?? null) ? $response['UserInfo'] : [];
        $configurationId = trim((string) ($userInfo['PdnsId'] ?? $response['PdnsId'] ?? ''));
        if ($configurationId === '' && $storedEndpoint !== '') {
            $configurationId = self::configurationIdFromEndpoint($storedEndpoint);
        }
        if ($configurationId === '' || !preg_match('/^[a-z0-9]+$/i', $configurationId)) {
            if ($lookupError !== null && $storedEndpoint === '') { throw $lookupError; }
            throw new \RuntimeException('阿里云接口没有返回 HTTPDNS 专属配置 ID，请确认 HTTPDNS 服务已经开通');
        }

        // The public API currently returns PdnsId but not the random suffix of an
        // encrypted endpoint. Keep detecting a complete endpoint so newer API
        // responses can be used automatically without another panel update.
        $endpoint = $storedEndpoint !== '' ? $storedEndpoint : self::findDohEndpoint($response);
        $source = $storedEndpoint !== '' ? 'stored' : ($endpoint === '' ? 'generated' : 'api');
        if ($endpoint === '') {
            $endpoint = 'https://' . strtolower($configurationId) . '.alidns.com/dns-query';
        }

        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $shortHost = strtolower($configurationId) . '.alidns.com';

        return [
            'url' => $endpoint,
            'configuration_id' => $configurationId,
            'address_type' => $host !== '' && $host !== $shortHost ? 'encrypted' : 'short',
            'source' => $source,
            'configuration_match' => $host === $shortHost || preg_match('/^' . preg_quote(strtolower($configurationId), '/') . '-[a-z0-9-]+\.alidns\.com$/', $host) === 1,
            'service_state' => strtoupper(trim((string) ($userInfo['State'] ?? 'UNKNOWN'))),
            'security_type' => strtoupper(trim((string) ($userInfo['AvailableAccessSecurityType'] ?? 'UNKNOWN'))),
            'available_service' => trim((string) ($userInfo['AvailableService'] ?? '')),
            'lookup_available' => $lookupError === null,
        ];
    }

    public function zones(): array
    {
        return $this->allPages('ListRecursionZones', [], ['RecursionZones.RecursionZone', 'Zones.Zone', 'RecursionZones', 'Zones']);
    }

    public function zone(string $zoneId): array { return $this->call('DescribeRecursionZone', ['ZoneId' => $zoneId]); }

    public function addZone(string $name, string $proxyPattern): array
    {
        if (!filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) { throw new \InvalidArgumentException('权威域名格式不正确'); }
        return $this->call('AddRecursionZone', ['ZoneName' => strtolower($name), 'ProxyPattern' => self::proxyPattern($proxyPattern), 'ClientToken' => self::token()]);
    }

    public function updateZone(string $zoneId, string $remark, string $proxyPattern): void
    {
        $current = $this->zone($zoneId);
        if (trim((string) ($current['Remark'] ?? '')) !== trim($remark)) {
            $this->call('UpdateRecursionZoneRemark', ['ZoneId' => $zoneId, 'Remark' => $remark, 'ClientToken' => self::token()]);
        }
        $targetPattern = self::proxyPattern($proxyPattern);
        if (strtolower((string) ($current['ProxyPattern'] ?? 'zone')) !== $targetPattern) {
            $this->call('UpdateRecursionZoneProxyPattern', ['ZoneId' => $zoneId, 'ProxyPattern' => $targetPattern, 'ClientToken' => self::token()]);
        }
    }

    public function updateEffectiveScope(string $zoneId, array $accountIds): array
    {
        $query = self::effectiveScopeParameters($zoneId, $accountIds) + ['ClientToken' => self::token()];
        return $this->call('UpdateRecursionZoneEffectiveScope', $query);
    }

    public function effectiveAccountIds(string $zoneId): array
    {
        return self::accountIdsFromZone($this->zone($zoneId));
    }

    public function deleteZone(string $zoneId): array { return $this->call('DeleteRecursionZone', ['ZoneId' => $zoneId, 'ClientToken' => self::token()]); }

    public function records(string $zoneId): array
    {
        if (!array_key_exists($zoneId, $this->recordCache)) {
            $this->recordCache[$zoneId] = $this->allPages('ListRecursionRecords', ['ZoneId' => $zoneId], ['Records.Record', 'RecursionRecords.RecursionRecord', 'Records', 'RecursionRecords']);
        }
        return $this->recordCache[$zoneId];
    }

    public function addRecord(string $zoneId, array $record): array
    {
        $result = $this->call('AddRecursionRecord', self::recordParameters($record) + ['ZoneId' => $zoneId, 'ClientToken' => self::token()]);
        $recordId = trim((string) ($result['RecordId'] ?? ''));
        $remark = trim((string) ($record['remark'] ?? ''));
        if ($recordId !== '' && $remark !== '') {
            $this->call('UpdateRecursionRecordRemark', ['RecordId' => $recordId, 'Remark' => $remark, 'ClientToken' => self::token()]);
        }
        $this->setWeightStatus($zoneId, $record);
        return $result;
    }

    public function updateRecord(string $recordId, string $zoneId, array $record): array
    {
        $current = $this->record($recordId, $zoneId);
        $parameters = self::recordParameters($record);
        $result = ['RecordId' => $recordId, 'Unchanged' => true];
        if (!self::sameRecord($current, $parameters)) {
            $result = $this->call('UpdateRecursionRecord', $parameters + ['RecordId' => $recordId, 'ClientToken' => self::token()]);
        }
        $remark = trim((string) ($record['remark'] ?? ''));
        if (trim((string) ($current['Remark'] ?? '')) !== $remark) {
            $this->call('UpdateRecursionRecordRemark', ['RecordId' => $recordId, 'Remark' => $remark, 'ClientToken' => self::token()]);
        }
        $this->setWeightStatus($zoneId, $record);
        return $result;
    }

    public function setStatus(string $recordId, string $status, string $zoneId = ''): array
    {
        if ($zoneId !== '') { $this->record($recordId, $zoneId); }
        $target = strtolower($status) === 'enable' ? 'enable' : 'disable';
        try {
            return $this->call('UpdateRecursionRecordEnableStatus', ['RecordId' => $recordId, 'EnableStatus' => $target, 'ClientToken' => self::token()]);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'PdnsRecord.SameEnableStatus') !== false) {
                return ['RecordId' => $recordId, 'EnableStatus' => $target, 'Unchanged' => true];
            }
            throw $e;
        }
    }

    public function deleteRecord(string $recordId, string $zoneId = ''): array
    {
        if ($zoneId !== '') { $this->record($recordId, $zoneId); }
        return $this->call('DeleteRecursionRecord', ['RecordId' => $recordId, 'ClientToken' => self::token()]);
    }

    private function setWeightStatus(string $zoneId, array $record): void
    {
        $weightStatus = strtolower(trim((string) ($record['weight_status'] ?? 'keep')));
        if (!in_array($weightStatus, ['enable', 'disable'], true)) { return; }
        try {
            $this->call('UpdateRecursionRecordWeightEnableStatus', [
                'ZoneId' => $zoneId,
                'Rr' => trim((string) ($record['rr'] ?? '')),
                'Type' => strtoupper(trim((string) ($record['type'] ?? ''))),
                'RequestSource' => trim((string) ($record['line'] ?? 'default')) ?: 'default',
                'EnableStatus' => $weightStatus,
                'ClientToken' => self::token(),
            ]);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'SameWeightEnableStatus') === false
                && stripos($e->getMessage(), 'SameEnableStatus') === false) {
                throw $e;
            }
        }
    }

    private static function recordParameters(array $record): array
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        if (!in_array($type, ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'SRV'], true)) { throw new \InvalidArgumentException('不支持该解析记录类型'); }
        $rr = trim((string) ($record['rr'] ?? '')); $value = trim((string) ($record['value'] ?? '')); $ttl = (int) ($record['ttl'] ?? 60);
        if ($rr === '' || $value === '' || !in_array($ttl, [5, 30, 60, 3600, 43200, 86400], true)) { throw new \InvalidArgumentException('主机记录、记录值或 TTL 不正确'); }
        $parameters = ['Rr' => $rr, 'Type' => $type, 'Value' => $value, 'Ttl' => $ttl, 'RequestSource' => trim((string) ($record['line'] ?? 'default')) ?: 'default', 'Weight' => max(1, min(100, (int) ($record['weight'] ?? 1)))];
        if ($type === 'MX') { $parameters['Priority'] = max(1, min(99, (int) ($record['priority'] ?? 1))); }
        return $parameters;
    }

    private function call(string $action, array $query): array
    {
        $accessKey = trim((string) $this->account->access_key_id); $secret = trim((string) $this->account->access_key_secret);
        if ($accessKey === '' || $secret === '') { throw new \RuntimeException('阿里云访问密钥不能为空'); }
        $client = AlibabaCloud::accessKeyClient($accessKey, $secret)->regionId('cn-hangzhou')->connectTimeout(15)->timeout(60);
        $proxyUrl = ProxyController::getProxyUrlForAccount($this->account);
        if ($proxyUrl !== null) { $client->proxy($proxyUrl); }
        $client->asDefaultClient();
        return AlibabaCloud::rpc()
            ->product('Alidns')
            ->scheme(self::SCHEME)
            ->version(self::VERSION)
            ->action($action)
            ->method('POST')
            ->host(self::ENDPOINT)
            ->options(['query' => $query])
            ->request()
            ->toArray();
    }

    private function allPages(string $action, array $query, array $paths): array
    {
        $items = [];
        for ($page = 1; $page <= 1000; $page++) {
            $response = $this->call($action, $query + ['PageNumber' => $page, 'PageSize' => 100]);
            $pageItems = self::items($response, $paths);
            $items = array_merge($items, $pageItems);
            $totalPages = max(1, (int) ($response['TotalPages'] ?? 1));
            if ($page >= $totalPages || count($pageItems) < 100) { break; }
        }
        return $items;
    }

    private function record(string $recordId, string $zoneId): array
    {
        foreach ($this->records($zoneId) as $record) {
            if ((string) ($record['RecordId'] ?? '') === $recordId) { return $record; }
        }
        throw new \InvalidArgumentException('解析记录不存在，或不属于当前权威域名');
    }

    private static function items(array $response, array $paths): array
    {
        foreach ($paths as $path) { $value = $response; foreach (explode('.', $path) as $part) { if (!is_array($value) || !array_key_exists($part, $value)) { $value = null; break; } $value = $value[$part]; } if (is_array($value)) { return self::isList($value) ? $value : [$value]; } }
        return [];
    }

    private static function effectiveScopeParameters(string $zoneId, array $accountIds): array
    {
        $zoneId = trim($zoneId);
        if ($zoneId === '') { throw new \InvalidArgumentException('权威域名 ID 不能为空'); }

        $normalizedIds = [];
        foreach ($accountIds as $accountId) {
            $accountId = trim((string) $accountId);
            if ($accountId === '') { continue; }
            if (!preg_match('/^[0-9]+$/', $accountId)) {
                throw new \InvalidArgumentException('HTTPDNS Account ID 只能包含数字');
            }
            $normalizedIds[$accountId] = $accountId;
        }

        $query = ['ZoneId' => $zoneId];
        if ($normalizedIds === []) { return $query; }
        $query['EffectiveScopes.1.EffectiveType'] = 'account';
        foreach (array_values($normalizedIds) as $index => $accountId) {
            $query['EffectiveScopes.1.Scope.' . ($index + 1)] = $accountId;
        }
        return $query;
    }

    private static function accountIdsFromZone(array $zone): array
    {
        if (is_array($zone['RecursionZone'] ?? null)) { $zone = $zone['RecursionZone']; }
        $scopes = $zone['EffectiveScopes']['EffectiveScope'] ?? [];
        if (!is_array($scopes)) { return []; }
        if (!self::isList($scopes)) { $scopes = [$scopes]; }

        $accountIds = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope) || strtolower((string) ($scope['EffectiveType'] ?? 'account')) !== 'account') { continue; }
            $values = $scope['Scopes']['Scope'] ?? $scope['Scope'] ?? [];
            if (!is_array($values)) { $values = [$values]; }
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value !== '') { $accountIds[$value] = $value; }
            }
        }
        return array_values($accountIds);
    }

    private static function findDohEndpoint(array $response): string
    {
        foreach ($response as $value) {
            if (is_array($value)) {
                $endpoint = self::findDohEndpoint($value);
                if ($endpoint !== '') { return $endpoint; }
                continue;
            }
            if (!is_string($value)) { continue; }
            if (preg_match('~https://([a-z0-9-]+\.alidns\.com)/dns-query(?:[?#][^\s]*)?~i', trim($value), $matches)) {
                return 'https://' . strtolower($matches[1]) . '/dns-query';
            }
            if (preg_match('~^([a-z0-9-]+\.alidns\.com)(?:/dns-query)?$~i', trim($value), $matches)) {
                return 'https://' . strtolower($matches[1]) . '/dns-query';
            }
        }
        return '';
    }

    private static function normalizeDohEndpoint(string $value): string
    {
        $value = trim($value);
        if ($value === '') { return ''; }
        if (!preg_match('~^https://([a-z0-9-]+\.alidns\.com)/dns-query/?$~i', $value, $matches)) { return ''; }
        return 'https://' . strtolower($matches[1]) . '/dns-query';
    }

    private static function configurationIdFromEndpoint(string $endpoint): string
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (preg_match('/^([a-z0-9]+)(?:-[a-z0-9-]+)?\.alidns\.com$/i', $host, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private static function proxyPattern(string $value): string { return strtolower($value) === 'record' ? 'record' : 'zone'; }
    private static function sameRecord(array $current, array $target): bool
    {
        $fields = ['Rr', 'Type', 'Value', 'Ttl', 'RequestSource', 'Weight'];
        if (array_key_exists('Priority', $target)) { $fields[] = 'Priority'; }
        foreach ($fields as $field) {
            if ((string) ($current[$field] ?? '') !== (string) ($target[$field] ?? '')) { return false; }
        }
        return true;
    }
    private static function token(): string { return bin2hex(random_bytes(16)); }
    private static function isList(array $value): bool { return $value === [] || array_keys($value) === range(0, count($value) - 1); }
}
