<?php

namespace app\controller;

use app\model\UserProxy as UserProxyModel;
use think\facade\View;

class UserProxy extends UserBase
{
    public function index()
    {
        $proxies = UserProxyModel::where('user_id', session('user_id'))
            ->order('id', 'desc')
            ->select();

        View::assign([
            'proxies' => $proxies,
            'total' => $proxies->count(),
        ]);

        return View::fetch('../app/view/user/proxy/index.html');
    }

    public function save()
    {
        try {
            $this->validateInput();

            if (input('is_default/d') === 1) {
                UserProxyModel::where('user_id', session('user_id'))->update(['is_default' => 0]);
            }

            $proxy = new UserProxyModel();
            $proxy->user_id = session('user_id');
            $proxy->name = trim(input('name/s'));
            $proxy->address = trim(input('address/s'));
            $proxy->port = input('port/d');
            $proxy->username = trim(input('username/s', ''));
            $proxy->password = trim(input('password/s', ''));
            $proxy->enabled = input('enabled/d', 1);
            $proxy->is_default = input('is_default/d', 0);
            $proxy->created_at = time();
            $proxy->updated_at = time();
            $proxy->save();

            return json(Tools::msg('1', '保存成功', '代理已保存'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '保存失败', $e->getMessage()));
        }
    }

    public function update($id)
    {
        try {
            $proxy = UserProxyModel::where('user_id', session('user_id'))->find($id);
            if ($proxy === null) {
                throw new \RuntimeException('Proxy not found.');
            }

            if (input('action/s') === 'default') {
                UserProxyModel::where('user_id', session('user_id'))->update(['is_default' => 0]);
                $proxy->is_default = 1;
                $proxy->enabled = 1;
                $proxy->updated_at = time();
                $proxy->save();
                return json(Tools::msg('1', '设置成功', '默认代理已更新'));
            }

            if (input('action/s') === 'toggle') {
                $proxy->enabled = $proxy->enabled ? 0 : 1;
                $proxy->updated_at = time();
                $proxy->save();
                return json(Tools::msg('1', '切换成功', '代理状态已更新'));
            }

            $this->validateInput();
            if (input('is_default/d') === 1) {
                UserProxyModel::where('user_id', session('user_id'))->update(['is_default' => 0]);
            }

            $proxy->name = trim(input('name/s'));
            $proxy->address = trim(input('address/s'));
            $proxy->port = input('port/d');
            $proxy->username = trim(input('username/s', ''));
            $proxy->password = trim(input('password/s', ''));
            $proxy->enabled = input('enabled/d', 1);
            $proxy->is_default = input('is_default/d', 0);
            $proxy->updated_at = time();
            $proxy->save();

            return json(Tools::msg('1', '保存成功', '代理已更新'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '保存失败', $e->getMessage()));
        }
    }

    public function delete($id)
    {
        try {
            $proxy = UserProxyModel::where('user_id', session('user_id'))->find($id);
            if ($proxy === null) {
                throw new \RuntimeException('Proxy not found.');
            }

            $proxy->delete();
            return json(Tools::msg('1', '删除成功', '代理已删除'));
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '删除失败', $e->getMessage()));
        }
    }

    private function validateInput(): void
    {
        ProxyController::createSocks5ProxyUrl(
            input('address/s'),
            input('port/d'),
            input('username/s', ''),
            input('password/s', '')
        );

        if (trim(input('name/s')) === '') {
            throw new \InvalidArgumentException('代理名称不能为空');
        }
    }
}
