<?php
declare(strict_types=1);

namespace app\controller;

use app\model\AlibabaAccount;
use app\service\AlibabaHttpDnsZone;
use think\facade\View;

class UserAlibaba extends UserBase
{
    public function index()
    {
        $accounts = AlibabaAccount::where('user_id', session('user_id'))->order('id', 'desc')->select();
        foreach ($accounts as $account) { $account->proxy_label = ProxyController::getProxyLabelForAccountZh($account); }
        View::assign(['accounts' => $accounts]);
        return View::fetch('../app/view/user/alibaba/index.html');
    }

    public function create()
    {
        View::assign('proxies', ProxyController::getProxyOptionsForUser());
        return View::fetch('../app/view/user/alibaba/create.html');
    }

    public function save()
    {
        try {
            $account = new AlibabaAccount();
            $account->user_id = session('user_id');
            $this->fillAccount($account);
            (new AlibabaHttpDnsZone($account))->zones();
            $account->created_at = time(); $account->updated_at = time(); $account->save();
            return json(Tools::msg('1', '保存成功', '阿里云国际账户已添加'));
        } catch (\Throwable $e) { return $this->failure($e); }
    }

    public function edit($id)
    {
        try {
            $account = $this->account((int) $id);
            View::assign(['account' => $account, 'proxies' => ProxyController::getProxyOptionsForUser()]);
            return View::fetch('../app/view/user/alibaba/edit.html');
        } catch (\Throwable) {
            return redirect('/user/alibaba');
        }
    }

    public function update($id)
    {
        try {
            $account = $this->account((int) $id); $this->fillAccount($account); (new AlibabaHttpDnsZone($account))->zones();
            $account->updated_at = time(); $account->save();
            return json(Tools::msg('1', '保存成功', '账户信息已更新'));
        } catch (\Throwable $e) { return $this->failure($e); }
    }

    public function delete($id)
    {
        try { $this->account((int) $id)->delete(); return json(Tools::msg('1', '删除成功', '账户已从面板删除，云端资源不受影响')); }
        catch (\Throwable $e) { return $this->failure($e); }
    }

    public function manage($id)
    {
        try {
            $account = $this->account((int) $id);
        } catch (\Throwable) {
            return redirect('/user/alibaba');
        }
        View::assign(['account' => $account]);
        return View::fetch('../app/view/user/alibaba/httpdns.html');
    }

    public function zones($id) { return $this->api((int) $id, fn($api) => ['zones' => $api->zones()]); }
    public function connection($id) { return $this->api((int) $id, fn($api) => ['connection' => $api->connectionInfo()]); }
    public function zone($id) { return $this->api((int) $id, fn($api) => ['zone' => $api->zone($this->required('zone_id'))]); }
    public function addZone($id) { return $this->api((int) $id, fn($api) => ['zone_id' => ($api->addZone($this->required('zone_name'), input('proxy_pattern/s', 'zone')))['ZoneId'] ?? '']); }
    public function updateZone($id) { return $this->api((int) $id, function ($api) { $api->updateZone($this->required('zone_id'), input('remark/s', ''), input('proxy_pattern/s', 'zone')); return []; }); }
    public function scope($id) { return $this->api((int) $id, function ($api) { $ids = preg_split('/[\s,;]+/', trim(input('account_ids/s', '')), -1, PREG_SPLIT_NO_EMPTY) ?: []; $api->updateEffectiveScope($this->required('zone_id'), $ids); return []; }); }
    public function deleteZone($id) { return $this->api((int) $id, function ($api) { $api->deleteZone($this->required('zone_id')); return []; }); }
    public function records($id) { return $this->api((int) $id, fn($api) => ['records' => $api->records($this->required('zone_id'))]); }
    public function saveRecord($id) { return $this->api((int) $id, function ($api) { $zoneId = $this->required('zone_id'); $records = $this->recordInputs(); foreach ($records as $record) { $api->addRecord($zoneId, $record); } return ['content' => '已添加 ' . count($records) . ' 条记录值']; }); }
    public function updateRecord($id) { return $this->api((int) $id, function ($api) { $zoneId = $this->required('zone_id'); $records = $this->recordInputs(); foreach ($records as $record) { $recordId = trim((string) ($record['record_id'] ?? '')); if ($recordId === '') { $api->addRecord($zoneId, $record); } else { $api->updateRecord($recordId, $zoneId, $record); } } foreach ($this->removedRecordIds() as $recordId) { $api->deleteRecord($recordId, $zoneId); } return ['content' => '记录集合已保存']; }); }
    public function recordStatus($id) { return $this->api((int) $id, function ($api) { $api->setStatus($this->required('record_id'), input('status/s'), $this->required('zone_id')); return []; }); }
    public function deleteRecord($id) { return $this->api((int) $id, function ($api) { $api->deleteRecord($this->required('record_id'), $this->required('zone_id')); return []; }); }

    private function api(int $id, callable $callback)
    {
        try { $data = $callback(new AlibabaHttpDnsZone($this->account($id))); return json(array_merge(['status' => '1', 'title' => '操作成功', 'content' => '阿里云操作已完成'], $data)); }
        catch (\Throwable $e) { return $this->failure($e); }
    }

    private function fillAccount(AlibabaAccount $account): void
    {
        $name = trim(input('name/s')); $ak = trim(input('access_key_id/s')); $sk = trim(input('access_key_secret/s'));
        if ($sk === '' && $account->id) { $sk = (string) $account->access_key_secret; }
        if ($name === '' || $ak === '' || $sk === '') { throw new \InvalidArgumentException('名称、AccessKey ID 和 Secret 均不能为空'); }
        $account->name = $name; $account->access_key_id = $ak; $account->access_key_secret = $sk;
        $account->proxy_id = ProxyController::normalizeBoundProxyId(input('proxy_id', 0), (int) $account->user_id); $account->remark = trim(input('remark/s', ''));
    }

    private function account(int $id): AlibabaAccount
    {
        $account = AlibabaAccount::where('user_id', session('user_id'))->find($id);
        if ($account === null) { throw new \RuntimeException('阿里云账户不存在或无权访问'); }
        return $account;
    }

    private function recordInput(): array { return ['rr' => input('rr/s'), 'type' => input('type/s'), 'value' => input('value/s'), 'ttl' => input('ttl/d', 60), 'line' => input('line/s', 'default'), 'weight' => input('weight/d', 1), 'weight_status' => input('weight_status/s', 'keep'), 'priority' => input('priority/d', 1), 'remark' => input('remark/s', '')]; }
    private function recordInputs(): array
    {
        $raw = trim((string) input('records/s', ''));
        if ($raw === '') { return [$this->recordInput()]; }
        $rows = json_decode($raw, true);
        if (!is_array($rows) || $rows === [] || count($rows) > 100) { throw new \InvalidArgumentException('记录值集合格式不正确，单次最多保存 100 条'); }
        $common = ['rr' => input('rr/s'), 'type' => input('type/s'), 'ttl' => input('ttl/d', 60), 'line' => input('line/s', 'default'), 'weight_status' => input('weight_status/s', 'keep'), 'priority' => input('priority/d', 1)];
        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('记录值集合格式不正确'); }
            $records[] = array_merge($common, [
                'record_id' => trim((string) ($row['record_id'] ?? '')),
                'value' => trim((string) ($row['value'] ?? '')),
                'weight' => (int) ($row['weight'] ?? 1),
                'remark' => trim((string) ($row['remark'] ?? '')),
            ]);
        }
        return $records;
    }
    private function removedRecordIds(): array
    {
        $raw = trim((string) input('removed_record_ids/s', ''));
        if ($raw === '') { return []; }
        $ids = json_decode($raw, true);
        if (!is_array($ids)) { throw new \InvalidArgumentException('待删除记录 ID 格式不正确'); }
        return array_values(array_unique(array_filter(array_map(fn($id) => trim((string) $id), $ids))));
    }
    private function required(string $name): string { $value = trim((string) input($name . '/s')); if ($value === '') { throw new \InvalidArgumentException($name . ' 不能为空'); } return $value; }
    private function failure(\Throwable $e)
    {
        $message = $e->getMessage();
        if (stripos($message, 'PdnsRecord.SameEnableStatus') !== false) {
            $message = '解析记录已经处于目标状态，无需重复修改';
        } elseif (stripos($message, 'InvalidProtocol.NeedSsl') !== false) {
            $message = '阿里云接口要求使用 HTTPS，请更新面板代码后重试';
        } elseif (preg_match('/(?:Forbidden\.RAM|NoPermission|Forbidden|Unauthorized)/i', $message)) {
            $message = 'RAM 权限不足。请给这个访问密钥所属的 RAM 用户绑定 AliyunPubDNSFullAccess（pubdns:*）';
        } elseif (preg_match('/InvalidAccessKeyId|SignatureDoesNotMatch/i', $message)) {
            $message = '访问密钥 ID 或 Secret 不正确，请重新检查';
        } elseif (preg_match('/Throttl|TooManyRequests/i', $message)) {
            $message = '阿里云接口请求过于频繁，请稍后重试';
        }
        return json(Tools::msg('0', '阿里云请求失败', $message));
    }
}
