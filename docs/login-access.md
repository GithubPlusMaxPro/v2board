# 管理员登录页面与用户前端开关

管理员登录页保留新的自适应布局、密码显示切换、表单校验及登录中状态。

在「设置 → 系统配置 → 站点」中配置「用户前端」，配置项为 `user_frontend_enable`：

- `0`（默认）：关闭系统自带的整套用户前端，首页显示用户中心未启用提示，不加载用户前端应用。
- `1`：恢复配置中选择的用户前端主题，包括其登录、注册和用户中心等页面。

此开关只控制用户前端页面的加载，不修改登录、注册、快捷登录、订阅及节点 API 的行为。后台入口照常使用。

前一版误加的 `user_login_enable` 及认证接口限制已撤销；配置文件中如残留旧字段，也不再生效。

开关沿用现有系统配置保存到 `config/v2board.php`，不需要新增 SQL。拥有系统配置修改权限的管理员可以操作；仅查看配置的账号不能切换。

## 更新

```sh
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
```

使用常驻 Web 进程时，按原部署方式重新加载代码。本次开关调整不需要重启 Horizon 队列。

## 验证

```sh
vendor/bin/phpunit
node --check public/assets/admin/umi.js
```

相关测试使用 SQLite 内存数据库；配置保存测试通过模拟文件写入验证，不修改实际站点配置。
