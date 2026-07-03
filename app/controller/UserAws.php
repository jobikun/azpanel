<?php
namespace app\controller;

use app\controller\AwsApi;
use app\controller\AwsList;
use app\controller\UserTask;
use app\model\Aws;
use think\facade\View;

class UserAws extends UserBase
{
    public function index()
    {
        $accounts = Aws::where('user_id', session('user_id'))
            ->order('id', 'desc')
            ->select();

        View::assign([
            'total' => $accounts->count(),
            'accounts' => $accounts,
        ]);

        return View::fetch('../app/view/user/aws/index.html');
    }

    public function searchAccount()
    {
        $s_name = input('s_name/s', '');
        $s_mark = input('s_mark/s', '');
        $s_status = input('s_status/d', 'all');

        $condition = [];
        $condition[] = ['user_id', '=', session('user_id')];
        ($s_mark !== '') && $condition[] = ['mark', $s_mark];
        ($s_name !== '') && $condition[] = ['email', 'like', '%' . $s_name . '%'];
        ($s_status !== 'all') && $condition[] = ['disable', '=', $s_status];

        $data = Aws::where($condition)
            ->field('id')
            ->select();

        return json(['result' => $data]);
    }

    public function create()
    {
        $notes = Aws::where('user_id', session('user_id'))
            ->field('mark')
            ->select()
            ->toArray();

        View::assign([
            'notes' => $notes,
            'regions' => AwsList::instanceRegion(),
            'proxies' => ProxyController::getProxyOptionsForUser(),
        ]);

        return View::fetch('../app/view/user/aws/create.html');
    }

    public static function awsCertificateVerify(array $params): bool
    {
        $email = trim($params[0] ?? '');
        $aws_ak = trim($params[2] ?? '');
        $aws_sk = trim($params[3] ?? '');

        if ($email === '') {
            throw new \Exception("邮箱不能为空");
        }

        if (!Tools::emailCheck($email)) {
            throw new \Exception("不是有效的邮箱：{$email}");
        }

        if (strlen($aws_ak) !== 20) {
            throw new \Exception("Access Key 长度不符要求：{$aws_ak}");
        }

        if (strlen($aws_sk) !== 40) {
            throw new \Exception("Secret Key 长度不符要求：{$aws_sk}");
        }

        return true;
    }

    private static function normalizeRegions($regions): array
    {
        if (!is_array($regions)) {
            $regions = [];
        }

        $regions = array_values(array_filter(array_map('trim', $regions), function ($value) {
            return $value !== '';
        }));

        if (empty($regions)) {
            $regions = ['ap-northeast-1'];
        }

        if (!in_array('ap-northeast-1', $regions, true)) {
            $regions[] = 'ap-northeast-1';
        }

        return array_values(array_unique($regions));
    }

    private static function normalizeQuota($quota): array
    {
        if (!is_array($quota)) {
            return ['ap-northeast-1' => 'null'];
        }

        if (!array_key_exists('ap-northeast-1', $quota)) {
            $quota['ap-northeast-1'] = 'null';
        }

        return $quota;
    }

    public function save()
    {
        $add_mode = input('add_mode/s', 'single');
        $regions = self::normalizeRegions(input('regions/a', []));

        $email = trim(input('email/s', ''));
        $passwd = trim(input('passwd/s', ''));
        $aws_ak = trim(input('aws_ak/s', ''));
        $aws_sk = trim(input('aws_sk/s', ''));
        $user_mark = trim(input('user_mark/s', ''));
        $batch_addition = trim(input('batch_addition/s', ''));
        $remark_filling = trim(input('remark_filling/s', 'input'));

        $user_id = (int) session('user_id');
        $proxy_id = ProxyController::normalizeBoundProxyId(input('proxy_id'), $user_id);

        try {
            // 用绑定的代理来做创建时的配额检测，保证添加阶段就走指定代理
            $proxy_url = ProxyController::getProxyUrlForAccount((object) ['proxy_id' => $proxy_id, 'user_id' => $user_id]);

            if ($add_mode === 'single') {
                $batch_addition = $email . PHP_EOL . $passwd . PHP_EOL . $aws_ak . PHP_EOL . $aws_sk;
            }

            $accounts = preg_split('/\r\n|\r|\n/', $batch_addition);
            $accounts = array_map('trim', $accounts);

            if (count($accounts) % 4 !== 0) {
                throw new \Exception("内容与数量不匹配，请按 邮箱、密码、AK、SK 四行为一组填写");
            }

            $array = [];
            $pointer = 0;

            while ($pointer < count($accounts)) {
                $email = $accounts[$pointer++] ?? '';
                $passwd = $accounts[$pointer++] ?? '';
                $aws_ak = $accounts[$pointer++] ?? '';
                $aws_sk = $accounts[$pointer++] ?? '';

                self::awsCertificateVerify([
                    $email,
                    $passwd,
                    $aws_ak,
                    $aws_sk,
                ]);

                $quota = [];

                foreach ($regions as $region) {
                    try {
                        $quota[$region] = AwsApi::getQuota($region, $aws_ak, $aws_sk, $proxy_url);
                    } catch (\Throwable $e) {
                        $quota[$region] = 'null';
                    }
                }

                $quota = self::normalizeQuota($quota);

                $array[] = [
                    'email' => $email,
                    'passwd' => $passwd,
                    'ak' => $aws_ak,
                    'sk' => $aws_sk,
                    'mark' => $remark_filling === 'input' ? $user_mark : $remark_filling,
                    'quota' => json_encode($quota, JSON_UNESCAPED_UNICODE),
                    'disable' => ($quota['ap-northeast-1'] ?? 'null') === 'null' ? 1 : 0,
                    'proxy_id' => $proxy_id,
                    'user_id' => session('user_id'),
                    'created_at' => time(),
                ];
            }

            Aws::insertAll($array);

            return json(Tools::msg('1', '保存结果', '保存成功'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '保存失败', $e->getMessage()));
        }
    }

    public function read($id)
    {
        $account = Aws::where('user_id', session('user_id'))->find($id);

        if ($account === null) {
            return View::fetch('../app/view/user/reject.html');
        }

        if (input('action/s') === 'queryQuota') {
            return json(AwsApi::getQuota(input('region/s'), $account->ak, $account->sk));
        }

        if (input('action/s') === 'regionStatus') {
            try {
                $proxy_url = ProxyController::getProxyUrlForAccount($account);
                $client = AwsApi::createAccountClient($account->ak, $account->sk, $proxy_url);
                $status = AwsApi::getRegionOptStatus($client, input('region/s'));

                return json(Tools::msg('1', '区域状态', self::translateRegionOptStatus($status)));
            } catch (\Throwable $e) {
                return json(Tools::msg('0', '查询失败', $e->getMessage()));
            }
        }

        if (input('action/s') === 'enableRegion') {
            try {
                $region = input('region/s');

                if (!in_array($region, AwsList::optInRegions(), true)) {
                    throw new \Exception("该区域无需申请开通");
                }

                $proxy_url = ProxyController::getProxyUrlForAccount($account);
                $client = AwsApi::createAccountClient($account->ak, $account->sk, $proxy_url);
                $status = AwsApi::getRegionOptStatus($client, $region);

                if ($status === 'ENABLED' || $status === 'ENABLED_BY_DEFAULT') {
                    return json(Tools::msg('1', '开通结果', '该区域已开通，无需重复申请'));
                }

                if ($status === 'ENABLING') {
                    return json(Tools::msg('1', '开通结果', '该区域正在开通中，请耐心等待生效'));
                }

                if ($status === 'DISABLING') {
                    return json(Tools::msg('0', '开通失败', '该区域正在停用中，请等待停用完成后再申请'));
                }

                AwsApi::enableRegion($client, $region);

                return json(Tools::msg('1', '开通结果', '已提交开通申请，通常几分钟内生效，可通过「状态」按钮查询进度'));
            } catch (\Throwable $e) {
                return json(Tools::msg('0', '开通失败', $e->getMessage()));
            }
        }

        View::assign([
            'count' => 0,
            'account' => $account,
            'locations' => AwsList::instanceRegion(),
            'opt_in_regions' => AwsList::optInRegions(),
        ]);

        return View::fetch('../app/view/user/aws/read.html');
    }

    private static function translateRegionOptStatus(string $status): string
    {
        $map = [
            'ENABLED' => '已开通',
            'ENABLED_BY_DEFAULT' => '默认开通',
            'DISABLED' => '未开通',
            'ENABLING' => '开通中',
            'DISABLING' => '停用中',
        ];

        return $map[$status] ?? $status;
    }

    public function edit($id)
    {
        $account = Aws::where('user_id', session('user_id'))->find($id);

        if ($account === null) {
            return View::fetch('../app/view/user/reject.html');
        }

        View::assign([
            'account' => $account,
            'proxies' => ProxyController::getProxyOptionsForUser(),
        ]);

        return View::fetch('../app/view/user/aws/edit.html');
    }

    public function update($id)
    {
        try {
            $account = Aws::where('user_id', session('user_id'))->find($id);

            if ($account === null) {
                throw new \Exception("账户不存在");
            }

            if (input('action/s') === 'refresh') {
                $quota = [];
                $aws_account_quota = json_decode($account['quota'], true);

                $aws_account_quota = self::normalizeQuota($aws_account_quota);

                $proxy_url = ProxyController::getProxyUrlForAccount($account);
                foreach ($aws_account_quota as $key => $value) {
                    try {
                        $quota[$key] = AwsApi::getQuota($key, $account->ak, $account->sk, $proxy_url);
                    } catch (\Throwable $e) {
                        $quota[$key] = 'null';
                    }
                }

                $quota = self::normalizeQuota($quota);

                $account->quota = json_encode($quota, JSON_UNESCAPED_UNICODE);
                $account->disable = ($quota['ap-northeast-1'] ?? 'null') === 'null' ? 1 : 0;
                $account->save();

                return json(Tools::msg('1', '刷新结果', '刷新成功'));
            }

            if (input('action/s') === 'refreshAll') {
                $count = 0;
                $task_uuid = input('task_uuid/s');
                $accounts = Aws::where('user_id', session('user_id'))->select();
                $task_id = UserTask::create(session('user_id'), '刷新AWS账户订阅状态', [], $task_uuid);

                foreach ($accounts as $account) {
                    try {
                        $count++;
                        UserTask::update($task_id, $count / max($accounts->count(), 1), '正在刷新 ' . $account->email);

                        $quota = [];
                        $aws_account_quota = json_decode($account['quota'], true);
                        $aws_account_quota = self::normalizeQuota($aws_account_quota);

                        $proxy_url = ProxyController::getProxyUrlForAccount($account);
                        foreach ($aws_account_quota as $key => $value) {
                            try {
                                $quota[$key] = AwsApi::getQuota($key, $account->ak, $account->sk, $proxy_url);
                            } catch (\Throwable $e) {
                                $quota[$key] = 'null';
                            }
                        }

                        $quota = self::normalizeQuota($quota);

                        $account->quota = json_encode($quota, JSON_UNESCAPED_UNICODE);
                        $account->disable = ($quota['ap-northeast-1'] ?? 'null') === 'null' ? 1 : 0;
                        $account->save();
                    } catch (\Throwable $e) {
                        UserTask::end($task_id, true, $e->getMessage());
                        return json(Tools::msg('0', '刷新失败', $e->getMessage()));
                    }
                }

                UserTask::end($task_id, false);

                return json(Tools::msg('1', '刷新结果', '刷新成功'));
            }

            $account->email = trim(input('email/s', ''));
            $account->passwd = trim(input('passwd/s', ''));
            $account->mark = trim(input('mark/s', ''));
            $account->ak = trim(input('ak/s', ''));
            $account->sk = trim(input('sk/s', ''));
            $account->proxy_id = ProxyController::normalizeBoundProxyId(input('proxy_id', $account->proxy_id), (int) $account->user_id);

            self::awsCertificateVerify([
                $account->email,
                $account->passwd,
                $account->ak,
                $account->sk,
            ]);

            $account->save();

            return json(Tools::msg('1', '修改结果', '修改成功'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '修改失败', $e->getMessage()));
        }
    }

    public function delete($id)
    {
        try {
            if ($id === '0') {
                Aws::where('user_id', session('user_id'))
                    ->where('disable', 1)
                    ->delete();
            } else {
                $account = Aws::where('user_id', session('user_id'))->find($id);

                if ($account === null) {
                    throw new \Exception("账户不存在");
                }

                $account->delete();
            }

            return json(Tools::msg('1', '删除结果', '删除成功'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '删除失败', $e->getMessage()));
        }
    }
}