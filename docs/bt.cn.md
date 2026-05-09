本文以 `Debian 11` 为例

## 安装宝塔面板
在 [https://www.bt.cn/bbs/thread-19376-1-1.html](https://www.bt.cn/bbs/thread-19376-1-1.html) 找到安装脚本并执行

## 绕过宝塔强制登录（可跳过）
感谢 @skyover
来源：[https://hostloc.com/thread-978318-1-1.html](https://hostloc.com/thread-978318-1-1.html)

用官方脚本安装完成后，再执行下面的命令降级

```
cd /root
wget http://download.bt.cn/install/update/LinuxPanel-7.7.0.zip
unzip LinuxPanel-7.7.0.zip
cd panel
bash update.sh
```
## 添加A解析
前往域名控制面板，添加 A 记录，记录指向服务器 IP。此步骤略

## 添加网站

选择 `lnmp` 套件版本

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt1.png)

安装完成后，点击网站，点击添加站点

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt2.png)

改成你的域名

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt3.png)

添加完成后，点击设置

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt4.png)

设置伪静态

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt5.png)

申请 `ssl`

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt6.png)

开启强制 `https`

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt7.png)

点击软件商店，搜索 `php` ，找到安装的版本，点击设置

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt14.png)

点击禁用函数，将 `putenv` 从列表中移除，还有一个 `proc` 开头的函数，也从列表中移除（图里标错了，就一个 `proc` 开头的函数）

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt15.png)

然后点击网站，在网站列表，点击进入网站目录

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt9.png)

全选后删除

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt10.png)

点击终端

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt11.png)

填写服务器登录密码

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt12.png)

复制如下命令，然后在 `ssh` 页面右键粘贴执行
```
yum -y install git
apt-get -y install git
git clone https://github.com/azpanel/azpanel.git .
cp database/azure.sql /www/backup/database
cp database/config.sql /www/backup/database
chmod 755 -R *
chown www -R *
composer install
```

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt13.png)

这是正常的执行结果

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt16.png)

点击网站，点击设置

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt4.png)

根据图示操作

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt8.png)

点击数据库，点击添加数据库

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt17.png)

填写数据库名称，然后复制生成的密码

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt18.png)

点击导入

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt19.png)

按图示操作，先后顺序不要错

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt20.png)

点击文件，进入网站根目录，找到 ` .example.env`

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt22.png)

重命名为 `.env`

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt24.png)

编辑文件，填写数据库信息

- 建议将文件开头的 `APP_DEBUG = true` 改为  `APP_DEBUG = false`

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt25.png)

点击终端

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt26.png)

执行添加管理员账户的命令，注意修改成你想要设置的邮箱和密码

```
php think createAdmin --email admin@azpanel.net --passwd loginpasswd
```

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt27.png)

导入其他数据库
```
php think migrate:run
php think seed:run
```

## 登录后台

访问域名，输入账户密码，点击登录

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt28.png)

登录成功

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt29.png)

后台首页

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt30.png)

## 添加定时任务
注意修改网站目录，不要照搬

以下定时任务设置一天执行一次（每天零点）
```
php /home/wwwroot/azpanel/think tools --action statisticsTraffic
```
以下定时任务设置一小时执行一次
```
php /home/wwwroot/azpanel/think autoRefreshAccount
php /home/wwwroot/azpanel/think closeTimeoutTask
php /home/wwwroot/azpanel/think trafficControlStop
```
以下定时任务设置每五分钟执行一次
```
php /home/wwwroot/azpanel/think trafficControlStart
```

![](https://raw.githubusercontent.com/azpanel/azpanel/master/images/bt31.png)