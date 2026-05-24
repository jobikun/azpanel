# Xboard API List

Source: https://github.com/cedar2025/Xboard, branch master, downloaded on 2026-05-23.

Total routes: 226

Notes:
- API base prefixes are defined in `app/Providers/RouteServiceProvider.php`: `/api/v1` and `/api/v2`.
- `{secure_path}` is dynamic: `admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))`.
- `{subscribe_path}` is dynamic and defaults to `s`.
- `ANY` means Laravel `any()`; `GET|POST` means Laravel `match(['get','post'])`.

## WEB

| Method | Path | Controller@Action | Middleware | Source |
|---|---|---|---|---|
| GET | `/{subscribe_path}/{token}` | `App\Http\Controllers\V1\Client\ClientController@subscribe` | web, client | routes/web.php:90 |
| GET | `/{secure_path}` | `Closure@admin dashboard` | web | routes/web.php:77 |
| GET | `/` | `Closure@frontend dashboard` | web | routes/web.php:22 |

## V1

| Method | Path | Controller@Action | Middleware | Source |
|---|---|---|---|---|
| GET | `/api/v1/client/subscribe` | `App\Http\Controllers\V1\Client\ClientController@subscribe` | api, client | app/Http/Routes/V1/ClientRoute.php:17 |
| GET | `/api/v1/client/app/getConfig` | `App\Http\Controllers\V1\Client\AppController@getConfig` | api, client | app/Http/Routes/V1/ClientRoute.php:19 |
| GET | `/api/v1/client/app/getVersion` | `App\Http\Controllers\V1\Client\AppController@getVersion` | api, client | app/Http/Routes/V1/ClientRoute.php:20 |
| GET | `/api/v1/guest/plan/fetch` | `App\Http\Controllers\V1\Guest\PlanController@fetch` | api | app/Http/Routes/V1/GuestRoute.php:18 |
| POST | `/api/v1/guest/telegram/webhook` | `App\Http\Controllers\V1\Guest\TelegramController@webhook` | api | app/Http/Routes/V1/GuestRoute.php:20 |
| GET|POST | `/api/v1/guest/payment/notify/{method}/{uuid}` | `App\Http\Controllers\V1\Guest\PaymentController@notify` | api | app/Http/Routes/V1/GuestRoute.php:22 |
| GET | `/api/v1/guest/comm/config` | `App\Http\Controllers\V1\Guest\CommController@config` | api | app/Http/Routes/V1/GuestRoute.php:24 |
| POST | `/api/v1/passport/auth/register` | `App\Http\Controllers\V1\Passport\AuthController@register` | api | app/Http/Routes/V1/PassportRoute.php:16 |
| POST | `/api/v1/passport/auth/login` | `App\Http\Controllers\V1\Passport\AuthController@login` | api | app/Http/Routes/V1/PassportRoute.php:17 |
| GET | `/api/v1/passport/auth/token2Login` | `App\Http\Controllers\V1\Passport\AuthController@token2Login` | api | app/Http/Routes/V1/PassportRoute.php:18 |
| POST | `/api/v1/passport/auth/forget` | `App\Http\Controllers\V1\Passport\AuthController@forget` | api | app/Http/Routes/V1/PassportRoute.php:19 |
| POST | `/api/v1/passport/auth/getQuickLoginUrl` | `App\Http\Controllers\V1\Passport\AuthController@getQuickLoginUrl` | api | app/Http/Routes/V1/PassportRoute.php:20 |
| POST | `/api/v1/passport/auth/loginWithMailLink` | `App\Http\Controllers\V1\Passport\AuthController@loginWithMailLink` | api | app/Http/Routes/V1/PassportRoute.php:21 |
| POST | `/api/v1/passport/comm/sendEmailVerify` | `App\Http\Controllers\V1\Passport\CommController@sendEmailVerify` | api | app/Http/Routes/V1/PassportRoute.php:23 |
| POST | `/api/v1/passport/comm/pv` | `App\Http\Controllers\V1\Passport\CommController@pv` | api | app/Http/Routes/V1/PassportRoute.php:24 |
| GET | `/api/v1/server/UniProxy/config` | `App\Http\Controllers\V1\Server\UniProxyController@config` | api, server | app/Http/Routes/V1/ServerRoute.php:21 |
| GET | `/api/v1/server/UniProxy/user` | `App\Http\Controllers\V1\Server\UniProxyController@user` | api, server | app/Http/Routes/V1/ServerRoute.php:22 |
| POST | `/api/v1/server/UniProxy/push` | `App\Http\Controllers\V1\Server\UniProxyController@push` | api, server | app/Http/Routes/V1/ServerRoute.php:23 |
| POST | `/api/v1/server/UniProxy/alive` | `App\Http\Controllers\V1\Server\UniProxyController@alive` | api, server | app/Http/Routes/V1/ServerRoute.php:24 |
| GET | `/api/v1/server/UniProxy/alivelist` | `App\Http\Controllers\V1\Server\UniProxyController@alivelist` | api, server | app/Http/Routes/V1/ServerRoute.php:25 |
| POST | `/api/v1/server/UniProxy/status` | `App\Http\Controllers\V1\Server\UniProxyController@status` | api, server | app/Http/Routes/V1/ServerRoute.php:26 |
| GET | `/api/v1/server/ShadowsocksTidalab/user` | `App\Http\Controllers\V1\Server\ShadowsocksTidalabController@user` | api, server:shadowsocks | app/Http/Routes/V1/ServerRoute.php:32 |
| POST | `/api/v1/server/ShadowsocksTidalab/submit` | `App\Http\Controllers\V1\Server\ShadowsocksTidalabController@submit` | api, server:shadowsocks | app/Http/Routes/V1/ServerRoute.php:33 |
| GET | `/api/v1/server/TrojanTidalab/config` | `App\Http\Controllers\V1\Server\TrojanTidalabController@config` | api, server:trojan | app/Http/Routes/V1/ServerRoute.php:39 |
| GET | `/api/v1/server/TrojanTidalab/user` | `App\Http\Controllers\V1\Server\TrojanTidalabController@user` | api, server:trojan | app/Http/Routes/V1/ServerRoute.php:40 |
| POST | `/api/v1/server/TrojanTidalab/submit` | `App\Http\Controllers\V1\Server\TrojanTidalabController@submit` | api, server:trojan | app/Http/Routes/V1/ServerRoute.php:41 |
| GET | `/api/v1/user/resetSecurity` | `App\Http\Controllers\V1\User\UserController@resetSecurity` | api, user | app/Http/Routes/V1/UserRoute.php:28 |
| GET | `/api/v1/user/info` | `App\Http\Controllers\V1\User\UserController@info` | api, user | app/Http/Routes/V1/UserRoute.php:29 |
| POST | `/api/v1/user/changePassword` | `App\Http\Controllers\V1\User\UserController@changePassword` | api, user | app/Http/Routes/V1/UserRoute.php:30 |
| POST | `/api/v1/user/update` | `App\Http\Controllers\V1\User\UserController@update` | api, user | app/Http/Routes/V1/UserRoute.php:31 |
| GET | `/api/v1/user/getSubscribe` | `App\Http\Controllers\V1\User\UserController@getSubscribe` | api, user | app/Http/Routes/V1/UserRoute.php:32 |
| GET | `/api/v1/user/getStat` | `App\Http\Controllers\V1\User\UserController@getStat` | api, user | app/Http/Routes/V1/UserRoute.php:33 |
| GET | `/api/v1/user/checkLogin` | `App\Http\Controllers\V1\User\UserController@checkLogin` | api, user | app/Http/Routes/V1/UserRoute.php:34 |
| POST | `/api/v1/user/transfer` | `App\Http\Controllers\V1\User\UserController@transfer` | api, user | app/Http/Routes/V1/UserRoute.php:35 |
| POST | `/api/v1/user/getQuickLoginUrl` | `App\Http\Controllers\V1\User\UserController@getQuickLoginUrl` | api, user | app/Http/Routes/V1/UserRoute.php:36 |
| GET | `/api/v1/user/getActiveSession` | `App\Http\Controllers\V1\User\UserController@getActiveSession` | api, user | app/Http/Routes/V1/UserRoute.php:37 |
| POST | `/api/v1/user/removeActiveSession` | `App\Http\Controllers\V1\User\UserController@removeActiveSession` | api, user | app/Http/Routes/V1/UserRoute.php:38 |
| POST | `/api/v1/user/order/save` | `App\Http\Controllers\V1\User\OrderController@save` | api, user | app/Http/Routes/V1/UserRoute.php:40 |
| POST | `/api/v1/user/order/checkout` | `App\Http\Controllers\V1\User\OrderController@checkout` | api, user | app/Http/Routes/V1/UserRoute.php:41 |
| GET | `/api/v1/user/order/check` | `App\Http\Controllers\V1\User\OrderController@check` | api, user | app/Http/Routes/V1/UserRoute.php:42 |
| GET | `/api/v1/user/order/detail` | `App\Http\Controllers\V1\User\OrderController@detail` | api, user | app/Http/Routes/V1/UserRoute.php:43 |
| GET | `/api/v1/user/order/fetch` | `App\Http\Controllers\V1\User\OrderController@fetch` | api, user | app/Http/Routes/V1/UserRoute.php:44 |
| GET | `/api/v1/user/order/getPaymentMethod` | `App\Http\Controllers\V1\User\OrderController@getPaymentMethod` | api, user | app/Http/Routes/V1/UserRoute.php:45 |
| POST | `/api/v1/user/order/cancel` | `App\Http\Controllers\V1\User\OrderController@cancel` | api, user | app/Http/Routes/V1/UserRoute.php:46 |
| GET | `/api/v1/user/plan/fetch` | `App\Http\Controllers\V1\User\PlanController@fetch` | api, user | app/Http/Routes/V1/UserRoute.php:48 |
| GET | `/api/v1/user/invite/save` | `App\Http\Controllers\V1\User\InviteController@save` | api, user | app/Http/Routes/V1/UserRoute.php:50 |
| GET | `/api/v1/user/invite/fetch` | `App\Http\Controllers\V1\User\InviteController@fetch` | api, user | app/Http/Routes/V1/UserRoute.php:51 |
| GET | `/api/v1/user/invite/details` | `App\Http\Controllers\V1\User\InviteController@details` | api, user | app/Http/Routes/V1/UserRoute.php:52 |
| GET | `/api/v1/user/notice/fetch` | `App\Http\Controllers\V1\User\NoticeController@fetch` | api, user | app/Http/Routes/V1/UserRoute.php:54 |
| POST | `/api/v1/user/ticket/reply` | `App\Http\Controllers\V1\User\TicketController@reply` | api, user | app/Http/Routes/V1/UserRoute.php:56 |
| POST | `/api/v1/user/ticket/close` | `App\Http\Controllers\V1\User\TicketController@close` | api, user | app/Http/Routes/V1/UserRoute.php:57 |
| POST | `/api/v1/user/ticket/save` | `App\Http\Controllers\V1\User\TicketController@save` | api, user | app/Http/Routes/V1/UserRoute.php:58 |
| GET | `/api/v1/user/ticket/fetch` | `App\Http\Controllers\V1\User\TicketController@fetch` | api, user | app/Http/Routes/V1/UserRoute.php:59 |
| POST | `/api/v1/user/ticket/withdraw` | `App\Http\Controllers\V1\User\TicketController@withdraw` | api, user | app/Http/Routes/V1/UserRoute.php:60 |
| GET | `/api/v1/user/server/fetch` | `App\Http\Controllers\V1\User\ServerController@fetch` | api, user | app/Http/Routes/V1/UserRoute.php:62 |
| POST | `/api/v1/user/coupon/check` | `App\Http\Controllers\V1\User\CouponController@check` | api, user | app/Http/Routes/V1/UserRoute.php:64 |
| POST | `/api/v1/user/gift-card/check` | `App\Http\Controllers\V1\User\GiftCardController@check` | api, user | app/Http/Routes/V1/UserRoute.php:66 |
| POST | `/api/v1/user/gift-card/redeem` | `App\Http\Controllers\V1\User\GiftCardController@redeem` | api, user | app/Http/Routes/V1/UserRoute.php:67 |
| GET | `/api/v1/user/gift-card/history` | `App\Http\Controllers\V1\User\GiftCardController@history` | api, user | app/Http/Routes/V1/UserRoute.php:68 |
| GET | `/api/v1/user/gift-card/detail` | `App\Http\Controllers\V1\User\GiftCardController@detail` | api, user | app/Http/Routes/V1/UserRoute.php:69 |
| GET | `/api/v1/user/gift-card/types` | `App\Http\Controllers\V1\User\GiftCardController@types` | api, user | app/Http/Routes/V1/UserRoute.php:70 |
| GET | `/api/v1/user/telegram/getBotInfo` | `App\Http\Controllers\V1\User\TelegramController@getBotInfo` | api, user | app/Http/Routes/V1/UserRoute.php:72 |
| GET | `/api/v1/user/comm/config` | `App\Http\Controllers\V1\User\CommController@config` | api, user | app/Http/Routes/V1/UserRoute.php:74 |
| POST | `/api/v1/user/comm/getStripePublicKey` | `App\Http\Controllers\V1\User\CommController@getStripePublicKey` | api, user | app/Http/Routes/V1/UserRoute.php:75 |
| GET | `/api/v1/user/knowledge/fetch` | `App\Http\Controllers\V1\User\KnowledgeController@fetch` | api, user | app/Http/Routes/V1/UserRoute.php:77 |
| GET | `/api/v1/user/knowledge/getCategory` | `App\Http\Controllers\V1\User\KnowledgeController@getCategory` | api, user | app/Http/Routes/V1/UserRoute.php:78 |
| GET | `/api/v1/user/stat/getTrafficLog` | `App\Http\Controllers\V1\User\StatController@getTrafficLog` | api, user | app/Http/Routes/V1/UserRoute.php:80 |

## V2

| Method | Path | Controller@Action | Middleware | Source |
|---|---|---|---|---|
| GET | `/api/v2/{secure_path}/config/fetch` | `App\Http\Controllers\V2\Admin\ConfigController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:37 |
| POST | `/api/v2/{secure_path}/config/save` | `App\Http\Controllers\V2\Admin\ConfigController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:38 |
| GET | `/api/v2/{secure_path}/config/getEmailTemplate` | `App\Http\Controllers\V2\Admin\ConfigController@getEmailTemplate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:39 |
| GET | `/api/v2/{secure_path}/config/getThemeTemplate` | `App\Http\Controllers\V2\Admin\ConfigController@getThemeTemplate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:40 |
| POST | `/api/v2/{secure_path}/config/setTelegramWebhook` | `App\Http\Controllers\V2\Admin\ConfigController@setTelegramWebhook` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:41 |
| POST | `/api/v2/{secure_path}/config/testSendMail` | `App\Http\Controllers\V2\Admin\ConfigController@testSendMail` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:42 |
| GET | `/api/v2/{secure_path}/mail/template/list` | `App\Http\Controllers\V2\Admin\MailTemplateController@list` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:49 |
| GET | `/api/v2/{secure_path}/mail/template/get` | `App\Http\Controllers\V2\Admin\MailTemplateController@get` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:50 |
| POST | `/api/v2/{secure_path}/mail/template/save` | `App\Http\Controllers\V2\Admin\MailTemplateController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:51 |
| POST | `/api/v2/{secure_path}/mail/template/reset` | `App\Http\Controllers\V2\Admin\MailTemplateController@reset` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:52 |
| POST | `/api/v2/{secure_path}/mail/template/test` | `App\Http\Controllers\V2\Admin\MailTemplateController@test` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:53 |
| GET | `/api/v2/{secure_path}/plan/fetch` | `App\Http\Controllers\V2\Admin\PlanController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:60 |
| POST | `/api/v2/{secure_path}/plan/save` | `App\Http\Controllers\V2\Admin\PlanController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:61 |
| POST | `/api/v2/{secure_path}/plan/drop` | `App\Http\Controllers\V2\Admin\PlanController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:62 |
| POST | `/api/v2/{secure_path}/plan/update` | `App\Http\Controllers\V2\Admin\PlanController@update` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:63 |
| POST | `/api/v2/{secure_path}/plan/sort` | `App\Http\Controllers\V2\Admin\PlanController@sort` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:64 |
| GET | `/api/v2/{secure_path}/server/group/fetch` | `App\Http\Controllers\V2\Admin\Server\GroupController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:71 |
| POST | `/api/v2/{secure_path}/server/group/save` | `App\Http\Controllers\V2\Admin\Server\GroupController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:72 |
| POST | `/api/v2/{secure_path}/server/group/drop` | `App\Http\Controllers\V2\Admin\Server\GroupController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:73 |
| GET | `/api/v2/{secure_path}/server/route/fetch` | `App\Http\Controllers\V2\Admin\Server\RouteController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:78 |
| POST | `/api/v2/{secure_path}/server/route/save` | `App\Http\Controllers\V2\Admin\Server\RouteController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:79 |
| POST | `/api/v2/{secure_path}/server/route/drop` | `App\Http\Controllers\V2\Admin\Server\RouteController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:80 |
| GET | `/api/v2/{secure_path}/server/manage/getNodes` | `App\Http\Controllers\V2\Admin\Server\ManageController@getNodes` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:86 |
| POST | `/api/v2/{secure_path}/server/manage/update` | `App\Http\Controllers\V2\Admin\Server\ManageController@update` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:87 |
| POST | `/api/v2/{secure_path}/server/manage/save` | `App\Http\Controllers\V2\Admin\Server\ManageController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:88 |
| POST | `/api/v2/{secure_path}/server/manage/drop` | `App\Http\Controllers\V2\Admin\Server\ManageController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:89 |
| POST | `/api/v2/{secure_path}/server/manage/copy` | `App\Http\Controllers\V2\Admin\Server\ManageController@copy` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:90 |
| POST | `/api/v2/{secure_path}/server/manage/sort` | `App\Http\Controllers\V2\Admin\Server\ManageController@sort` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:91 |
| POST | `/api/v2/{secure_path}/server/manage/batchDelete` | `App\Http\Controllers\V2\Admin\Server\ManageController@batchDelete` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:92 |
| POST | `/api/v2/{secure_path}/server/manage/batchUpdate` | `App\Http\Controllers\V2\Admin\Server\ManageController@batchUpdate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:93 |
| POST | `/api/v2/{secure_path}/server/manage/resetTraffic` | `App\Http\Controllers\V2\Admin\Server\ManageController@resetTraffic` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:94 |
| POST | `/api/v2/{secure_path}/server/manage/batchResetTraffic` | `App\Http\Controllers\V2\Admin\Server\ManageController@batchResetTraffic` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:95 |
| GET | `/api/v2/{secure_path}/server/manage/generateEchKey` | `App\Http\Controllers\V2\Admin\Server\ManageController@generateEchKey` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:96 |
| GET | `/api/v2/{secure_path}/server/machine/fetch` | `App\Http\Controllers\V2\Admin\Server\MachineController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:103 |
| POST | `/api/v2/{secure_path}/server/machine/save` | `App\Http\Controllers\V2\Admin\Server\MachineController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:104 |
| POST | `/api/v2/{secure_path}/server/machine/drop` | `App\Http\Controllers\V2\Admin\Server\MachineController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:105 |
| POST | `/api/v2/{secure_path}/server/machine/resetToken` | `App\Http\Controllers\V2\Admin\Server\MachineController@resetToken` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:106 |
| GET | `/api/v2/{secure_path}/server/machine/getToken` | `App\Http\Controllers\V2\Admin\Server\MachineController@getToken` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:107 |
| GET | `/api/v2/{secure_path}/server/machine/installCommand` | `App\Http\Controllers\V2\Admin\Server\MachineController@installCommand` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:108 |
| GET | `/api/v2/{secure_path}/server/machine/nodes` | `App\Http\Controllers\V2\Admin\Server\MachineController@nodes` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:109 |
| GET | `/api/v2/{secure_path}/server/machine/history` | `App\Http\Controllers\V2\Admin\Server\MachineController@history` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:110 |
| ANY | `/api/v2/{secure_path}/order/fetch` | `App\Http\Controllers\V2\Admin\OrderController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:117 |
| POST | `/api/v2/{secure_path}/order/update` | `App\Http\Controllers\V2\Admin\OrderController@update` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:118 |
| POST | `/api/v2/{secure_path}/order/assign` | `App\Http\Controllers\V2\Admin\OrderController@assign` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:119 |
| POST | `/api/v2/{secure_path}/order/paid` | `App\Http\Controllers\V2\Admin\OrderController@paid` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:120 |
| POST | `/api/v2/{secure_path}/order/cancel` | `App\Http\Controllers\V2\Admin\OrderController@cancel` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:121 |
| POST | `/api/v2/{secure_path}/order/detail` | `App\Http\Controllers\V2\Admin\OrderController@detail` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:122 |
| ANY | `/api/v2/{secure_path}/user/fetch` | `App\Http\Controllers\V2\Admin\UserController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:129 |
| POST | `/api/v2/{secure_path}/user/update` | `App\Http\Controllers\V2\Admin\UserController@update` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:130 |
| GET | `/api/v2/{secure_path}/user/getUserInfoById` | `App\Http\Controllers\V2\Admin\UserController@getUserInfoById` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:131 |
| POST | `/api/v2/{secure_path}/user/generate` | `App\Http\Controllers\V2\Admin\UserController@generate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:132 |
| POST | `/api/v2/{secure_path}/user/dumpCSV` | `App\Http\Controllers\V2\Admin\UserController@dumpCSV` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:133 |
| POST | `/api/v2/{secure_path}/user/sendMail` | `App\Http\Controllers\V2\Admin\UserController@sendMail` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:134 |
| POST | `/api/v2/{secure_path}/user/ban` | `App\Http\Controllers\V2\Admin\UserController@ban` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:135 |
| POST | `/api/v2/{secure_path}/user/resetSecret` | `App\Http\Controllers\V2\Admin\UserController@resetSecret` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:136 |
| POST | `/api/v2/{secure_path}/user/setInviteUser` | `App\Http\Controllers\V2\Admin\UserController@setInviteUser` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:137 |
| POST | `/api/v2/{secure_path}/user/destroy` | `App\Http\Controllers\V2\Admin\UserController@destroy` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:138 |
| GET | `/api/v2/{secure_path}/stat/getOverride` | `App\Http\Controllers\V2\Admin\StatController@getOverride` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:145 |
| GET | `/api/v2/{secure_path}/stat/getStats` | `App\Http\Controllers\V2\Admin\StatController@getStats` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:146 |
| GET | `/api/v2/{secure_path}/stat/getServerLastRank` | `App\Http\Controllers\V2\Admin\StatController@getServerLastRank` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:147 |
| GET | `/api/v2/{secure_path}/stat/getServerYesterdayRank` | `App\Http\Controllers\V2\Admin\StatController@getServerYesterdayRank` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:148 |
| GET | `/api/v2/{secure_path}/stat/getOrder` | `App\Http\Controllers\V2\Admin\StatController@getOrder` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:149 |
| ANY | `/api/v2/{secure_path}/stat/getStatUser` | `App\Http\Controllers\V2\Admin\StatController@getStatUser` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:150 |
| GET | `/api/v2/{secure_path}/stat/getRanking` | `App\Http\Controllers\V2\Admin\StatController@getRanking` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:151 |
| GET | `/api/v2/{secure_path}/stat/getStatRecord` | `App\Http\Controllers\V2\Admin\StatController@getStatRecord` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:152 |
| GET | `/api/v2/{secure_path}/stat/getTrafficRank` | `App\Http\Controllers\V2\Admin\StatController@getTrafficRank` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:153 |
| GET | `/api/v2/{secure_path}/notice/fetch` | `App\Http\Controllers\V2\Admin\NoticeController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:160 |
| POST | `/api/v2/{secure_path}/notice/save` | `App\Http\Controllers\V2\Admin\NoticeController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:161 |
| POST | `/api/v2/{secure_path}/notice/update` | `App\Http\Controllers\V2\Admin\NoticeController@update` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:162 |
| POST | `/api/v2/{secure_path}/notice/drop` | `App\Http\Controllers\V2\Admin\NoticeController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:163 |
| POST | `/api/v2/{secure_path}/notice/show` | `App\Http\Controllers\V2\Admin\NoticeController@show` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:164 |
| POST | `/api/v2/{secure_path}/notice/sort` | `App\Http\Controllers\V2\Admin\NoticeController@sort` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:165 |
| ANY | `/api/v2/{secure_path}/ticket/fetch` | `App\Http\Controllers\V2\Admin\TicketController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:172 |
| POST | `/api/v2/{secure_path}/ticket/reply` | `App\Http\Controllers\V2\Admin\TicketController@reply` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:173 |
| POST | `/api/v2/{secure_path}/ticket/close` | `App\Http\Controllers\V2\Admin\TicketController@close` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:174 |
| ANY | `/api/v2/{secure_path}/coupon/fetch` | `App\Http\Controllers\V2\Admin\CouponController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:181 |
| POST | `/api/v2/{secure_path}/coupon/generate` | `App\Http\Controllers\V2\Admin\CouponController@generate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:182 |
| POST | `/api/v2/{secure_path}/coupon/drop` | `App\Http\Controllers\V2\Admin\CouponController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:183 |
| POST | `/api/v2/{secure_path}/coupon/show` | `App\Http\Controllers\V2\Admin\CouponController@show` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:184 |
| POST | `/api/v2/{secure_path}/coupon/update` | `App\Http\Controllers\V2\Admin\CouponController@update` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:185 |
| ANY | `/api/v2/{secure_path}/gift-card/templates` | `App\Http\Controllers\V2\Admin\GiftCardController@templates` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:193 |
| POST | `/api/v2/{secure_path}/gift-card/create-template` | `App\Http\Controllers\V2\Admin\GiftCardController@createTemplate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:194 |
| POST | `/api/v2/{secure_path}/gift-card/update-template` | `App\Http\Controllers\V2\Admin\GiftCardController@updateTemplate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:195 |
| POST | `/api/v2/{secure_path}/gift-card/delete-template` | `App\Http\Controllers\V2\Admin\GiftCardController@deleteTemplate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:196 |
| POST | `/api/v2/{secure_path}/gift-card/generate-codes` | `App\Http\Controllers\V2\Admin\GiftCardController@generateCodes` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:199 |
| ANY | `/api/v2/{secure_path}/gift-card/codes` | `App\Http\Controllers\V2\Admin\GiftCardController@codes` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:200 |
| POST | `/api/v2/{secure_path}/gift-card/toggle-code` | `App\Http\Controllers\V2\Admin\GiftCardController@toggleCode` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:201 |
| GET | `/api/v2/{secure_path}/gift-card/export-codes` | `App\Http\Controllers\V2\Admin\GiftCardController@exportCodes` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:202 |
| POST | `/api/v2/{secure_path}/gift-card/update-code` | `App\Http\Controllers\V2\Admin\GiftCardController@updateCode` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:203 |
| POST | `/api/v2/{secure_path}/gift-card/delete-code` | `App\Http\Controllers\V2\Admin\GiftCardController@deleteCode` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:204 |
| ANY | `/api/v2/{secure_path}/gift-card/usages` | `App\Http\Controllers\V2\Admin\GiftCardController@usages` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:207 |
| ANY | `/api/v2/{secure_path}/gift-card/statistics` | `App\Http\Controllers\V2\Admin\GiftCardController@statistics` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:210 |
| GET | `/api/v2/{secure_path}/gift-card/types` | `App\Http\Controllers\V2\Admin\GiftCardController@types` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:211 |
| GET | `/api/v2/{secure_path}/knowledge/fetch` | `App\Http\Controllers\V2\Admin\KnowledgeController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:218 |
| GET | `/api/v2/{secure_path}/knowledge/getCategory` | `App\Http\Controllers\V2\Admin\KnowledgeController@getCategory` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:219 |
| POST | `/api/v2/{secure_path}/knowledge/save` | `App\Http\Controllers\V2\Admin\KnowledgeController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:220 |
| POST | `/api/v2/{secure_path}/knowledge/show` | `App\Http\Controllers\V2\Admin\KnowledgeController@show` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:221 |
| POST | `/api/v2/{secure_path}/knowledge/drop` | `App\Http\Controllers\V2\Admin\KnowledgeController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:222 |
| POST | `/api/v2/{secure_path}/knowledge/sort` | `App\Http\Controllers\V2\Admin\KnowledgeController@sort` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:223 |
| GET | `/api/v2/{secure_path}/payment/fetch` | `App\Http\Controllers\V2\Admin\PaymentController@fetch` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:230 |
| GET | `/api/v2/{secure_path}/payment/getPaymentMethods` | `App\Http\Controllers\V2\Admin\PaymentController@getPaymentMethods` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:231 |
| POST | `/api/v2/{secure_path}/payment/getPaymentForm` | `App\Http\Controllers\V2\Admin\PaymentController@getPaymentForm` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:232 |
| POST | `/api/v2/{secure_path}/payment/save` | `App\Http\Controllers\V2\Admin\PaymentController@save` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:233 |
| POST | `/api/v2/{secure_path}/payment/drop` | `App\Http\Controllers\V2\Admin\PaymentController@drop` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:234 |
| POST | `/api/v2/{secure_path}/payment/show` | `App\Http\Controllers\V2\Admin\PaymentController@show` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:235 |
| POST | `/api/v2/{secure_path}/payment/sort` | `App\Http\Controllers\V2\Admin\PaymentController@sort` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:236 |
| GET | `/api/v2/{secure_path}/system/getSystemStatus` | `App\Http\Controllers\V2\Admin\SystemController@getSystemStatus` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:243 |
| GET | `/api/v2/{secure_path}/system/getQueueStats` | `App\Http\Controllers\V2\Admin\SystemController@getQueueStats` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:244 |
| GET | `/api/v2/{secure_path}/system/getQueueWorkload` | `App\Http\Controllers\V2\Admin\SystemController@getQueueWorkload` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:245 |
| GET | `/api/v2/{secure_path}/system/getQueueMasters` | `\\Laravel\\Horizon\\Http\\Controllers\\MasterSupervisorController@index` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:246 |
| GET | `/api/v2/{secure_path}/system/getHorizonFailedJobs` | `App\Http\Controllers\V2\Admin\SystemController@getHorizonFailedJobs` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:247 |
| ANY | `/api/v2/{secure_path}/system/getAuditLog` | `App\Http\Controllers\V2\Admin\SystemController@getAuditLog` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:248 |
| GET | `/api/v2/{secure_path}/update/check` | `UpdateController@checkUpdate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:255 |
| POST | `/api/v2/{secure_path}/update/execute` | `UpdateController@executeUpdate` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:256 |
| GET | `/api/v2/{secure_path}/update/theme/getThemes` | `App\Http\Controllers\V2\Admin\ThemeController@getThemes` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:263 |
| POST | `/api/v2/{secure_path}/update/theme/upload` | `App\Http\Controllers\V2\Admin\ThemeController@upload` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:264 |
| POST | `/api/v2/{secure_path}/update/theme/delete` | `App\Http\Controllers\V2\Admin\ThemeController@delete` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:265 |
| POST | `/api/v2/{secure_path}/update/theme/saveThemeConfig` | `App\Http\Controllers\V2\Admin\ThemeController@saveThemeConfig` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:266 |
| POST | `/api/v2/{secure_path}/update/theme/getThemeConfig` | `App\Http\Controllers\V2\Admin\ThemeController@getThemeConfig` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:267 |
| GET | `/api/v2/{secure_path}/update/plugin/types` | `PluginController@types` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:274 |
| GET | `/api/v2/{secure_path}/update/plugin/getPlugins` | `PluginController@index` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:275 |
| POST | `/api/v2/{secure_path}/update/plugin/upload` | `PluginController@upload` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:276 |
| POST | `/api/v2/{secure_path}/update/plugin/delete` | `PluginController@delete` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:277 |
| POST | `/api/v2/{secure_path}/update/plugin/install` | `PluginController@install` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:278 |
| POST | `/api/v2/{secure_path}/update/plugin/uninstall` | `PluginController@uninstall` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:279 |
| POST | `/api/v2/{secure_path}/update/plugin/enable` | `PluginController@enable` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:280 |
| POST | `/api/v2/{secure_path}/update/plugin/disable` | `PluginController@disable` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:281 |
| GET | `/api/v2/{secure_path}/update/plugin/config` | `PluginController@getConfig` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:282 |
| POST | `/api/v2/{secure_path}/update/plugin/config` | `PluginController@updateConfig` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:283 |
| POST | `/api/v2/{secure_path}/update/plugin/upgrade` | `PluginController@upgrade` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:284 |
| GET | `/api/v2/{secure_path}/update/traffic-reset/logs` | `App\Http\Controllers\V2\Admin\TrafficResetController@logs` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:291 |
| GET | `/api/v2/{secure_path}/update/traffic-reset/stats` | `App\Http\Controllers\V2\Admin\TrafficResetController@stats` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:292 |
| GET | `/api/v2/{secure_path}/update/traffic-reset/user/{userId}/history` | `App\Http\Controllers\V2\Admin\TrafficResetController@userHistory` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:293 |
| POST | `/api/v2/{secure_path}/update/traffic-reset/reset-user` | `App\Http\Controllers\V2\Admin\TrafficResetController@resetUser` | api, admin, log | app/Http/Routes/V2/AdminRoute.php:294 |
| GET | `/api/v2/client/app/getConfig` | `App\Http\Controllers\V2\Client\AppController@getConfig` | api, client | app/Http/Routes/V2/ClientRoute.php:16 |
| GET | `/api/v2/client/app/getVersion` | `App\Http\Controllers\V2\Client\AppController@getVersion` | api, client | app/Http/Routes/V2/ClientRoute.php:17 |
| POST | `/api/v2/passport/auth/register` | `App\Http\Controllers\V1\Passport\AuthController@register` | api | app/Http/Routes/V2/PassportRoute.php:16 |
| POST | `/api/v2/passport/auth/login` | `App\Http\Controllers\V1\Passport\AuthController@login` | api | app/Http/Routes/V2/PassportRoute.php:17 |
| GET | `/api/v2/passport/auth/token2Login` | `App\Http\Controllers\V1\Passport\AuthController@token2Login` | api | app/Http/Routes/V2/PassportRoute.php:18 |
| POST | `/api/v2/passport/auth/forget` | `App\Http\Controllers\V1\Passport\AuthController@forget` | api | app/Http/Routes/V2/PassportRoute.php:19 |
| POST | `/api/v2/passport/auth/getQuickLoginUrl` | `App\Http\Controllers\V1\Passport\AuthController@getQuickLoginUrl` | api | app/Http/Routes/V2/PassportRoute.php:20 |
| POST | `/api/v2/passport/auth/loginWithMailLink` | `App\Http\Controllers\V1\Passport\AuthController@loginWithMailLink` | api | app/Http/Routes/V2/PassportRoute.php:21 |
| POST | `/api/v2/passport/comm/sendEmailVerify` | `App\Http\Controllers\V1\Passport\CommController@sendEmailVerify` | api | app/Http/Routes/V2/PassportRoute.php:23 |
| POST | `/api/v2/passport/comm/pv` | `App\Http\Controllers\V1\Passport\CommController@pv` | api | app/Http/Routes/V2/PassportRoute.php:24 |
| GET|POST | `/api/v2/server/handshake` | `App\Http\Controllers\V2\Server\ServerController@handshake` | api, server.v2 | app/Http/Routes/V2/ServerRoute.php:19 |
| POST | `/api/v2/server/report` | `App\Http\Controllers\V2\Server\ServerController@report` | api, server.v2 | app/Http/Routes/V2/ServerRoute.php:20 |
| GET | `/api/v2/server/config` | `App\Http\Controllers\V1\Server\UniProxyController@config` | api, server.v2 | app/Http/Routes/V2/ServerRoute.php:21 |
| GET | `/api/v2/server/user` | `App\Http\Controllers\V1\Server\UniProxyController@user` | api, server.v2 | app/Http/Routes/V2/ServerRoute.php:22 |
| POST | `/api/v2/server/push` | `App\Http\Controllers\V1\Server\UniProxyController@push` | api, server.v2 | app/Http/Routes/V2/ServerRoute.php:23 |
| POST | `/api/v2/server/alive` | `App\Http\Controllers\V1\Server\UniProxyController@alive` | api, server.v2 | app/Http/Routes/V2/ServerRoute.php:24 |
| GET | `/api/v2/server/alivelist` | `App\Http\Controllers\V1\Server\UniProxyController@alivelist` | api, server.v2 | app/Http/Routes/V2/ServerRoute.php:25 |
| POST | `/api/v2/server/status` | `App\Http\Controllers\V1\Server\UniProxyController@status` | api, server.v2 | app/Http/Routes/V2/ServerRoute.php:26 |
| POST | `/api/v2/server/machine/nodes` | `App\Http\Controllers\V2\Server\MachineController@nodes` | api | app/Http/Routes/V2/ServerRoute.php:32 |
| POST | `/api/v2/server/machine/status` | `App\Http\Controllers\V2\Server\MachineController@status` | api | app/Http/Routes/V2/ServerRoute.php:33 |
| GET | `/api/v2/user/resetSecurity` | `App\Http\Controllers\V1\User\UserController@resetSecurity` | api, user | app/Http/Routes/V2/UserRoute.php:16 |
| GET | `/api/v2/user/info` | `App\Http\Controllers\V1\User\UserController@info` | api, user | app/Http/Routes/V2/UserRoute.php:17 |

