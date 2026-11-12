# 管理员权限

沿用 `is_admin` 身份，通过 `v2_user.admin_permissions` 分配权限，不启用员工模块。

当前实现范围是网页管理后台及其接口。Telegram 工单回复仍只检查 `is_admin` / `is_staff` 身份，尚未校验 `ticket.reply`；已绑定 Telegram 的受限管理员可能绕过网页的工单回复限制。部署前需要补齐该入口的授权检查。角色／权限组管理及员工后台接入也未包含在本次改动中。

- 数据库 SQL NULL：完整权限管理员，可以分配其他管理员权限。
- JSON 数组：受限管理员，只能使用数组中明确授权的操作；空数组没有业务权限。
- 原有管理员升级后保留完整权限；通过用户管理新提拔的管理员默认使用空数组。
- 操作权限自动包含对应查看权限，调整余额和佣金还会包含修改用户权限。用户、订单、优惠券、礼品卡页面会补齐套餐查询，用户和套餐页面会补齐节点权限组查询，不会因此授予节点连接凭证的查看权限。
- 无法撤销自己的完整权限。删除、封禁或撤销完整管理员身份前，需要另一位完整管理员先将其改为受限管理员。批量封禁和删除只作用于普通用户。

## 升级

先备份数据库，在发布新 PHP 代码前执行以下增量 SQL 一次：

```sql
ALTER TABLE `v2_user`
ADD `admin_permissions` text DEFAULT NULL
COMMENT 'NULL: full admin, JSON array: restricted admin' AFTER `is_admin`;
```

此 SQL 已加入 `database/update.sql`，新安装的结构也已同步。已执行项目更新流程的实例无需重复执行。由于现有更新命令会忽略 SQL 错误，请确认列实际创建成功。

发布后刷新配置与视图缓存，按部署方式重启常驻 PHP 进程：

```sh
php artisan config:clear
php artisan config:cache
php artisan view:clear
```

后台模板加载新增的 `permissions.js`、`permissions.css`，并更新原有后台脚本的缓存标记。仓库只包含后台构建产物，现有菜单与登录流程的接入修改位于 `public/assets/admin/umi.js`；以后替换该文件时需保留这些接入修改。

## 使用

1. 完整权限管理员在「用户管理」将目标账号设置为管理员。
2. 在侧栏「设置」分类下打开「管理员权限」，选择账号。
3. 取消「完整管理权限」，按主菜单分类勾选所需权限后保存。每个主菜单支持整组全选／取消，也可展开单独勾选操作；部分选中会显示半选状态。需要完整权限时可以显式勾选。
4. 目标账号重新进入后台后，菜单和默认页面按权限显示。

每次后台请求都会重新校验签名、会话、封禁状态、管理员身份和数据库权限，不受原身份缓存的一小时有效期影响。权限保存后，下一个请求立即生效；已打开页面的菜单在重新进入后台时刷新。没有功能权限的管理员会看到等待授权页面。拒绝操作返回 HTTP 403 和 `permission_denied: true`，前端显示错误且保留登录状态。

受限管理员的用户列表和详情可查看普通用户及本人账号，其他管理员和员工不可见；本人管理员账号仅供查看，界面不提供编辑、重置、删除等操作。修改、批量操作及导出仍仅针对普通用户，不能通过本人可见这一例外改变自己的管理员权限或身份。用户查看接口不返回密码哈希，受限管理员也不获取订阅凭证；用户导出是独立权限，其导出内容包含普通用户订阅地址。修改余额、佣金、折扣和封禁状态另行检查权限。系统配置、支付配置、节点配置、日志及内容发布等权限涉及敏感数据或站点内容，按实际职责授予。

## 接口和扩展

- `GET /api/v1/{secure_path}/permission/current`：当前管理员的权限与权限目录。
- `GET /api/v1/{secure_path}/permission/fetch`：完整管理员查看管理员账号。
- `POST /api/v1/{secure_path}/permission/save`：完整管理员分配权限，请求包含 `id`、布尔值 `is_super_admin`、数组 `permissions`。

权限目录和明确的控制器动作映射位于 `config/admin_permissions.php`。新增后台动作在未映射时默认拒绝受限管理员。Horizon 自带的其他监控和管理接口保留仅完整管理员可访问；后台已有的队列状态接口按目录授权。未授予系统配置查看权限时，`config/fetch?key=site` 只返回页面所需的币种及币种符号，不能读取其他配置。权限变更日志记录操作人、目标账号和变更前后的授权。

## 验证

```sh
vendor/bin/phpunit tests/Feature/AdminPermissionTest.php
node --check public/assets/admin/permissions.js
node --check public/assets/admin/umi.js
```

权限接口测试使用 SQLite 内存数据库，不连接业务数据库。数据库行锁在 MySQL 上串行处理权限分配；SQLite 测试不验证并发锁行为。

## 订阅历史

后台侧栏「管理员审查 → 订阅访问记录」是独立的管理员审查页面，默认集中显示全部可查看用户的记录。可按用户 ID、完整邮箱、IP、访问类型及起止时间筛选，并分页查看。普通用户没有此页面或接口权限。完整管理员可直接查看；受限管理员需在「管理员权限 → 订阅访问记录」独立分组中授予 `user.subscribe_log`（查看订阅访问记录），原有授权继续有效。受限管理员仅能查询普通用户，不可查看管理员、员工或已删除用户的历史；完整管理员可审查已删除用户的记录，邮箱显示为「用户已删除」。

记录保存在独立表 `v2_subscription_log`，包含用户 ID、请求时间（Unix 秒）、IP（支持 IPv4/IPv6）、客户端 User-Agent（最多 1024 字符）和 HTTP 响应码。两种事件分别记录：

- `address`：用户面板请求 `GET /api/v1/user/getSubscribe` 获取地址；该接口也可能由页面自动调用，不代表用户一定点击了复制。
- `download`：客户端拉取订阅，默认路径及配置的自定义订阅路径均支持，包括一次性／限时订阅令牌。

只记录通过身份验证后的请求；非法令牌无法关联用户，不会生成历史。HTTP 响应码沿用接口原始行为，`200` 不保证账号可用或订阅有节点。记录不保存订阅令牌、完整 URL、请求参数或订阅内容。IP 使用 Laravel 的请求 IP，反向代理环境需按实际部署正确配置可信代理，否则可能记录代理地址；不会无条件信任客户端提交的转发头。

记录从功能启用且建表成功后开始，无法补回以前的历史，默认持续保留，不受系统日志每月清理影响。写入失败不会中断订阅，会在 `storage/logs` 的每日应用日志中留下不含令牌的提示。

### 创建历史表

对现有实例，发布代码时可只执行本次迁移：

```sh
php artisan migrate --path=database/migrations/2026_11_09_000000_create_subscription_log_table.php --force
php artisan config:clear
php artisan config:cache
php artisan view:clear
```

该迁移可兼容已经通过 SQL 更新流程创建的同名表。`database/install.sql` 和 `database/update.sql` 也包含建表语句；使用现有安装／更新流程的实例无需另建数据库。按部署方式重启常驻 PHP 进程。后台模板会加载 `subscription-log.js`、`subscription-log.css`，并更新 `umi.js` 的缓存标记；以后替换后台构建文件时需保留独立菜单和页面路由的接入。此次菜单调整沿用已创建的记录表，无需新增迁移。

审查接口为 `GET /api/v1/{secure_path}/subscriptionLog/fetch`，不指定用户时查询所有有权查看的记录。可传 `user_id`、`email`（完整邮箱）、`current`（默认 1）、`page_size`（默认 20，最多 100）、`action`（`address` 或 `download`）、`ip`（完整 IP）、`start_at` / `end_at`（Unix 秒，含边界）。响应含 `data`、`total`、`current`、`page_size`，每条记录包含关联用户邮箱，按记录 ID 倒序返回。后台按浏览器本地时区显示时间。旧的 `user/getSubscribeLog?id=用户ID` 接口保留兼容。

验证：`php -d disable_functions= vendor/bin/phpunit tests/Feature/SubscriptionLogTest.php`。测试使用 SQLite 内存数据库，不连接业务数据库。

## 用户日流量

后台侧栏「管理员审查 → 用户日流量」按用户和自然日汇总所有节点的原始上传、原始下载、合计及倍率扣量；点击用户下方的「展开节点明细」可查看该用户当天各节点的流量。按节点类型或节点 ID 筛选时，汇总和展开明细都只包含筛选范围内的节点。管理员权限弹窗有独立的「用户日流量」分组，权限键为 `traffic.view`，不依赖用户管理或节点配置查看权限，不会因此授予节点连接凭证。完整管理员可直接查看；受限管理员只可查询普通用户的明细，管理员、员工和已删除用户的记录不返回。完整管理员能看到已删除用户的记录。

### 数据与统计口径

- `v2_stat_server_user`：每天、节点类型、节点 ID、用户 ID 唯一一行；页面将同一用户同一天的多条节点记录合并显示。`u` / `d` 是节点上报的原始字节，`billed_u` / `billed_d` 是每次上报乘当时倍率并取整数后的累计扣量。倍率当天变更不会将旧用量按新倍率重算。
- `v2_stat_server_user_batch`：保存统计任务的批次编号，用于同一队列任务重试、重放去重；批次标记与流量累加在同一数据库事务中提交。计数采用数据库原子累加，避免多个节点上报任务相互覆盖。
- 日期在 `UserService::trafficFetch` 接收上报时确定，按 `config/app.php` 的站点时区归日，当前为 `Asia/Shanghai`（UTC+8）。队列跨零点执行或重试不会改变归属日期。节点没有上报逐笔发生时间，所以跨日上报的整批用量按面板接收日归属，不能精确拆分真实发生日期。
- 新任务通过已有 `stat` 队列处理，现有套餐扣量和节点／用户汇总链路保留。该记录从功能部署后开始，旧的汇总数据无法反推节点与用户的交叉明细。零用量不创建行。
- 这里的节点是面板中的协议节点，不是物理服务器；同一台机器上的不同节点分别统计。
- 两张新表默认持续保留，不受现有 `reset:log` 两个月清理影响。如需归档，应将相同日期的明细和批次标记一起处理；单独移除批次标记后重放旧任务可能再次累计。去重针对同一队列任务，不会将节点再次发送的相同 HTTP 请求识别成同一批次，因为现有上报没有全局批次 ID。

### 部署与变更范围

新增每日明细模型 `app/Models/StatServerUser.php`、统计任务 `app/Jobs/StatServerUserJob.php` 和查询控制器 `app/Http/Controllers/V1/Admin/ServerUserTrafficController.php`。`UserService::trafficFetch` 增加一个任务派发；管理路由、权限目录、权限弹窗、后台菜单和模板增加对应入口。前端功能位于 `public/assets/admin/server-user-traffic.js` / `.css`，`umi.js` 只接入菜单与路由。安装和更新 SQL 同步建表，新迁移可用于现有实例。

先更新服务器代码，再在项目目录执行：

```sh
php artisan migrate --path=database/migrations/2026_11_12_000000_create_stat_server_user_tables.php --force
php artisan config:cache
php artisan route:clear
php artisan view:clear
php artisan horizon:terminate
```

`horizon:terminate` 让旧队列进程退出，需由已有 Supervisor／进程守护重新启动 Horizon。若部署使用其他队列管理方式，应按现有方式重启工作进程；常驻 HTTP 进程也要重新加载代码。节点客户端不需要改协议。未配置业务数据库的开发副本不会自动建业务表。

接口为 `GET /api/v1/{secure_path}/serverUserTraffic/fetch`：支持 `start_date` / `end_date`（`YYYY-MM-DD`，含两端，最多一年）、`server_type`、`server_id`、`user_id`、`email`、`current`、`page_size`（最多 100）。默认查看站点当天；节点 ID 可独立查询；未指定节点类型时，返回所有类型下该 ID 的记录，各类型仍分别列出，指定类型后可进一步限定。响应包括分页数据、筛选范围内全部记录的原始及倍率合计、日期范围和时区。日期取站点时区，界面以二进制单位 KiB / MiB / GiB 显示用量。

验证使用 SQLite 内存库，覆盖跨日、不同节点／用户／协议、倍率变化、序列化重试、跨批次事务回滚、原有任务派发、筛选合计、权限隔离和迁移重复执行。SQLite 测试不验证 MySQL 实际并发锁调度。

## 用户在线与用量

后台侧栏「管理员审查 → 用户在线与用量」默认展示分页用户列表，包括邮箱、ID、近期在线状态、当前 IP 预览、当日原始流量和倍率扣量；支持 ID 精确搜索、邮箱关键字搜索及按今日流量排序。顶部卡片显示匹配用户总数，以及当前页用户的在线人数和用量。点击「查看明细」打开侧边抽屉，包含「当前在线 IP」「在线 IP 历史」「流量用量」三个页签。管理员权限弹窗有独立的同名分组，权限为 `online.view`，不自动授予用户管理、节点配置或全站日流量权限。完整管理员可查看所有现存用户；受限管理员只能查询普通用户，不能访问管理员或员工的在线记录。列表接口仅返回当前管理员有权查看的用户及概览；明细和 IP 查询接口必须指定有权查看的用户。

当前在线 IP 读取节点 `UniProxy/alive` 上报的缓存，只显示最近 100 秒仍有上报的节点数据。IPv4 / IPv6 会规范化并去重，同一 IP 出现在多个节点时分别列出，概览按唯一 IP 计数，不代表设备数量。未上报在线 IP 的节点无法据此确认在线或离线；面板登录会话和订阅访问 IP 也不会被当作节点在线 IP。

新增表 `v2_user_online_log` 按「日期、用户、节点类型、节点 ID、IP」汇总，记录当日首次观察时间、最近观察时间以及节点名称快照。既有在线上报缓存仍即时更新，新的 `RecordUserOnlineJob` 通过 `stat` 队列持久化。日期在接收节点上报时确定，原子 upsert 使用最小首次时间与最大最近时间，因此队列重试、乱序执行不会重复插入或回退时间。空 IP 列表不清除历史；缓存过期后当前 IP 会消失，历史继续保留。历史不是精确连接／断开日志，也不据此计算在线时长。默认持续留存，部署前的历史无法补回。

流量页签复用已新增的 `v2_stat_server_user` 每日统计，支持日期、节点类型和节点 ID 筛选，节点 ID 可单独查询。当天卡片与页签使用同一原始／倍率统计口径。查询返回用户 ID 和邮箱，不返回密码、UUID、订阅 token 或节点密钥。

### IP 归属地

默认接入 [ipwho.is / IPWhois API](https://ipwhois.io/documentation)，经后台 HTTPS 请求查询公网 IP，显示提供方返回的国家、地区、城市、运营商及 ASN。免费接口无需密钥，官方当前额度为每个请求源 IP 每天 1,000 次；发生 HTTP 429 时按 Retry-After 进入服务商级冷却，避免重复请求。字段可能为空，结果是近似归属地，不能当作设备的准确物理位置。IPv4 / IPv6 均支持，不需要 API 密钥。仅发送查询的公网 IP，不附带用户邮箱、ID 或订阅凭证。内网和保留 IP 本地标记，不发送外部请求。接口地址固定，前端不能指定任意目标 URL。

成功查询缓存 1 天，失败缓存 5 分钟；请求超时 3 秒，不跟随重定向。前端按需异步加载、最多同时请求两个归属地，同一 IP 在页签之间复用结果，不阻塞在线与流量查询。归属地接口限制每分钟 60 次，并校验 IP 确实属于目标用户的当前 IP 或历史记录。`config/ip_lookup.php` 提供服务商、超时和缓存配置；默认 `IP_LOOKUP_PROVIDER=ipwhois`，也可设为 `geojs` 切回旧服务商。服务商切换后使用独立缓存，不会继续展示旧 GeoJS 缓存；在 `.env` 设置 `IP_LOOKUP_ENABLED=false` 并重新生成配置缓存可以停用外部查询。

### 部署

此功能依赖上一版的每日节点用户流量表。已有该表的实例，更新代码后执行新增在线历史迁移：

```sh
php artisan migrate --path=database/migrations/2026_11_12_000001_create_user_online_log_table.php --force
php artisan config:cache
php artisan route:clear
php artisan view:clear
php artisan horizon:terminate
```

若尚未执行上一版的 `2026_11_12_000000_create_stat_server_user_tables.php`，先执行该迁移。SQL 安装和更新脚本已同步加入在线历史表。迁移允许同名表已存在；队列进程由现有守护服务重新启动，常驻 HTTP 进程也需要按部署方式加载新代码。节点需要启用在线 IP 上报，才能生成当前 IP 与在线 IP 历史。

接口均位于 `GET /api/v1/{secure_path}/userOnline/`：`fetch` 返回分页用户列表及当日概览，可传 `user_id`、邮箱关键字 `email`、`sort=id|traffic`、`current`、`page_size`；`summary` 返回用户、当前 IP 和当天流量；`history` 返回按天汇总的 IP 历史；`traffic` 返回指定用户的每日节点流量；`ipInfo` 查询该用户已出现 IP 的归属地。除 `fetch` 外，所有接口必传 `user_id` 或 `email`，同时提供时必须对应同一账号。`history` / `traffic` 支持 `start_date`、`end_date`、`server_type`、`server_id`、`current`、`page_size`，历史另支持 `ip` 精确筛选。日期范围最多一年，每页最多 100 条。`ipInfo` 还需传 `ip`。
