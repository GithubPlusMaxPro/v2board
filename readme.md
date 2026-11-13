<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

## 本分支支持的后端
 - [修改版V2bX](https://github.com/wyx2685/V2bX)
 - [v2node](https://github.com/wyx2685/v2node)

## 节点独立通讯密钥

后台新建或编辑节点时，可填写“节点通讯密钥”（16–255 个字符）。留空或清空后使用系统配置中的全局通讯密钥；填写后，该节点只接受自己的密钥，全局密钥不再对该节点有效。修改后需要同步更新节点后端的 API Key / token；V2Node 安装命令会自动使用该节点当前有效的密钥。父节点与子节点各自按自己的配置校验。

已有面板更新代码后，先执行新增字段迁移。当前 Docker 部署使用 `php81` 容器，容器内目录为 `/www/sites/v2board/index`，宿主机目录为 `/opt/1panel/www/sites/v2board/index`：

```bash
cd /opt/1panel/www/sites/v2board/index && git pull origin master
docker exec php81 sh -c '
cd /www/sites/v2board/index &&
php artisan migrate --path=database/migrations/2026_11_13_000000_add_server_token_to_server_tables.php --force &&
php artisan view:clear &&
php artisan horizon:terminate
'
```

其他部署方式在 PHP 运行环境中执行同样的 Artisan 命令即可。

使用 Webman 等常驻 HTTP 服务时，按部署方式重启服务以加载新代码。原有节点无需重新填写密钥，默认继续使用全局密钥。安装 SQL 和 `v2board:update` 使用的更新 SQL 已同步加入字段，迁移可在字段已存在时重复执行。

## 原版迁移步骤

按以下步骤进行面板代码文件迁移：

    git remote set-url origin https://github.com/wyx2685/v2board  
    git checkout master  
    ./update.sh  


按以下步骤配置缓存驱动为redis，然后刷新设置缓存，重启队列:

    sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
    php artisan config:clear
    php artisan config:cache
    php artisan horizon:terminate

最后进入后台重新保存主题： 主题配置-选择default主题-主题设置-确定保存

# **V2Board**

- PHP7.3+
- Composer
- MySQL5.5+
- Redis
- Laravel

## Document
[Click](https://v2board.com)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Group: [@unofficialV2board](https://t.me/unofficialV2board)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.
