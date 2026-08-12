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
        $query = ['ZoneId' => $zoneId, 'ClientToken' => self::token()];
        if ($accountIds !== []) { $query['EffectiveScopes.1.EffectiveType'] = 'account'; }
        foreach (array_values(array_unique(array_filter(array_map('trim', $accountIds)))) as $index => $accountId) {
            if (!preg_match('/^[0-9]+$/', $accountId)) { throw new \InvalidArgumentException('HTTPDNS Account ID 只能包含数字'); }
            $query['EffectiveScopes.1.Scope.' . ($index + 1)] = $accountId;
        }
        return $this->call('UpdateRecursionZoneEffectiveScope', $query);
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
