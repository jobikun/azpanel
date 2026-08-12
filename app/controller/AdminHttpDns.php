<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AlibabaHttpDnsZone;
use think\facade\View;

class AdminHttpDns extends AdminBase
{
    public function index() { View::assign(['zones' => [], 'error' => '']); return View::fetch('../app/view/admin/httpdns/index.html'); }
    public function zones() { try { return json(['status' => '1', 'zones' => AlibabaHttpDnsZone::zones()]); } catch (\Throwable $e) { return $this->failure($e); } }
    public function connection() { try { return json(['status' => '1', 'connection' => AlibabaHttpDnsZone::connectionInfo()]); } catch (\Throwable $e) { return $this->failure($e); } }
    public function zone() { try { return json(['status' => '1', 'zone' => AlibabaHttpDnsZone::zone($this->required('zone_id'))]); } catch (\Throwable $e) { return $this->failure($e); } }
    public function addZone() { try { $result = AlibabaHttpDnsZone::addZone($this->required('zone_name'), input('proxy_pattern/s', 'zone')); return json(['status' => '1', 'title' => 'Success', 'content' => 'Zone created', 'zone_id' => $result['ZoneId'] ?? '']); } catch (\Throwable $e) { return $this->failure($e); } }
    public function updateZone() { try { AlibabaHttpDnsZone::updateZone($this->required('zone_id'), input('remark/s', ''), input('proxy_pattern/s', 'zone')); return json(Tools::msg('1', 'Success', 'Zone settings updated')); } catch (\Throwable $e) { return $this->failure($e); } }
    public function scope() { try { $ids = preg_split('/[\s,;]+/', trim(input('account_ids/s', '')), -1, PREG_SPLIT_NO_EMPTY) ?: []; AlibabaHttpDnsZone::updateEffectiveScope($this->required('zone_id'), $ids); return json(Tools::msg('1', 'Success', 'Effective Account IDs updated')); } catch (\Throwable $e) { return $this->failure($e); } }
    public function deleteZone() { try { AlibabaHttpDnsZone::deleteZone($this->required('zone_id')); return json(Tools::msg('1', 'Success', 'Zone deleted')); } catch (\Throwable $e) { return $this->failure($e); } }
    public function records() { try { return json(['status' => '1', 'records' => AlibabaHttpDnsZone::records($this->required('zone_id'))]); } catch (\Throwable $e) { return $this->failure($e); } }
    public function saveRecord() { try { AlibabaHttpDnsZone::addRecord($this->required('zone_id'), $this->recordInput()); return json(Tools::msg('1', 'Success', 'HTTPDNS record added')); } catch (\Throwable $e) { return $this->failure($e); } }
    public function updateRecord() { try { AlibabaHttpDnsZone::updateRecord($this->required('record_id'), $this->recordInput()); return json(Tools::msg('1', 'Success', 'HTTPDNS record updated')); } catch (\Throwable $e) { return $this->failure($e); } }
    public function status() { try { AlibabaHttpDnsZone::setStatus($this->required('record_id'), input('status/s')); return json(Tools::msg('1', 'Success', 'Record status updated')); } catch (\Throwable $e) { return $this->failure($e); } }
    public function deleteRecord() { try { AlibabaHttpDnsZone::deleteRecord($this->required('record_id')); return json(Tools::msg('1', 'Success', 'HTTPDNS record deleted')); } catch (\Throwable $e) { return $this->failure($e); } }
    private function recordInput(): array { return ['rr' => input('rr/s'), 'type' => input('type/s'), 'value' => input('value/s'), 'ttl' => input('ttl/d', 60), 'line' => input('line/s', 'default'), 'weight' => input('weight/d', 1), 'priority' => input('priority/d', 1), 'remark' => input('remark/s', '')]; }
    private function required(string $name): string { $value = trim((string) input($name . '/s')); if ($value === '') { throw new \InvalidArgumentException($name . ' is required'); } return $value; }
    private function failure(\Throwable $e) { return json(Tools::msg('0', 'Alibaba Cloud request failed', $e->getMessage())); }
}
