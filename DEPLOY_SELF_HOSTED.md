# z-coc · 自有服务器部署

项目后端使用 PHP 8.3、Apache 和 MySQL 8，保留现有的 `db_api.php`、`room_api.php`、`library_api.php` 和 `health.php`。服务器可以是云主机、家用服务器或支持 Docker 的 VPS。

## 推荐方式：Docker Compose

将仓库复制到服务器后，在项目根目录执行：

```bash
docker compose up -d --build
```

默认网站端口为 `8080`。正式环境建议让 Nginx、Caddy 或云负载均衡负责 HTTPS，再反向代理到 `127.0.0.1:8080`。不要把 MySQL 端口暴露到公网。

生产环境请通过 `.env` 覆盖 Compose 的本地默认值：

```text
ZCOC_HTTP_PORT=8080
MYSQL_DATABASE=z_coc
MYSQL_USER=z_coc
MYSQL_PASSWORD=请改成随机长密码
MYSQL_ROOT_PASSWORD=请改成另一组随机长密码
```

首次部署后，在 MySQL 中执行 `migrations/001_initial.sql`。接口保留旧安装的兼容升级逻辑，但生产库仍建议先执行迁移文件。

## 不使用 Docker 的 PHP 主机

服务器需要 PHP 8.3、`mysqli` 扩展、Apache `mod_rewrite`，以及 MySQL 8。将仓库内容放到站点根目录，确保 PHP 文件由 PHP-FPM 或 Apache 执行，并确认 `.htaccess` 生效。不要把 `config.local.php` 提交到 Git。

可以在站点根目录创建仅存在于服务器上的 `config.local.php`，或使用环境变量：

```php
<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'z_coc',
        'password' => '随机长密码',
        'database' => 'z_coc',
    ],
];
```

## 前端和 App API 地址

如果网页和 PHP 接口使用同一个域名，不需要设置 `APP_ALLOWED_ORIGINS`。如果分开部署：

1. 在服务器设置 `APP_ALLOWED_ORIGINS`，只填写完整的 HTTPS Origin，多个值用逗号分隔。
2. 修改 `assets/js/runtime-config.js` 中的 `apiOrigin`，填入服务器 API 的 HTTPS Origin。
3. 重新执行 `pnpm mobile:sync`，再生成 Android 包。

## 验证部署

```bash
curl -fsS https://你的域名/health.php?probe=live
curl -fsS https://你的域名/health.php
```

第二个请求应返回数据库检查为 `ok`。然后用测试账号验证注册、登录、保存、退出、另一台设备登录恢复数据，以及多人房间的创建和加入。

正式上线前请配置 HTTPS、MySQL 自动备份和恢复演练，并限制服务器防火墙只开放 80/443；MySQL 只允许本机或应用容器访问。
