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

受限管理员的用户查询和修改仅针对普通用户，排除管理员及员工。用户查看接口不返回密码哈希，受限管理员也不获取订阅凭证；用户导出是独立权限，其导出内容包含普通用户订阅地址。修改余额、佣金、折扣和封禁状态另行检查权限。系统配置、支付配置、节点配置、日志及内容发布等权限涉及敏感数据或站点内容，按实际职责授予。

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

后台侧栏「管理员审查 → 订阅访问记录」是独立的管理员审查页面，默认集中显示全部可查看用户的记录。可按用户 ID、完整邮箱、IP、访问类型及起止时间筛选，并分页查看。普通用户没有此页面或接口权限。完整管理员可直接查看；受限管理员需单独授予 `user.subscribe_log`（查看订阅访问记录），原有授权继续有效。受限管理员仅能查询普通用户，不可查看管理员、员工或已删除用户的历史；完整管理员可审查已删除用户的记录，邮箱显示为「用户已删除」。

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
