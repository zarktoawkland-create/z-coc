# z-coc · Zeabur 部署清单

## 服务结构

在同一个 Zeabur 项目中创建：

1. MySQL 8 数据库服务。
2. 从 GitHub 仓库部署的 z-coc 网站服务。

根目录 `Dockerfile` 会启动 PHP 8.3 + Apache，并在容器端口 `8080` 提供网页与 PHP API。

本地联调可以直接使用根目录的 `docker-compose.yml`，它会启动同版本 PHP/Apache 和 MySQL 8.4：

```text
docker compose up --build
```

默认只用于本机测试的数据库密码写在 Compose 默认值中；生产环境必须通过 Zeabur 环境变量覆盖，不要复用本地密码。

## 数据库变量

网站服务应能读取 MySQL 服务注入的以下变量：

```text
MYSQL_HOST
MYSQL_PORT
MYSQL_USERNAME
MYSQL_PASSWORD
MYSQL_DATABASE
```

代码也兼容 `DB_HOST`、`DB_PORT`、`DB_USER`、`DB_PASSWORD` 和 `DB_NAME`。环境变量优先级高于配置文件。

同域部署时不要设置 `APP_ALLOWED_ORIGINS`。如果前端和 API 确实位于不同域名，使用逗号分隔的完整 Origin，例如：

```text
APP_ALLOWED_ORIGINS=https://app.example.com,https://preview.example.com
```

## Zeabur 操作顺序

1. 将项目推送到 GitHub 私有仓库。
2. 在 Zeabur 的 z-coc 项目内点击“新建服务”，部署 MySQL 8。
3. 再次点击“新建服务”，选择 GitHub 仓库和生产分支。
4. 确认构建日志显示使用根目录 Dockerfile，网站端口为 8080。
5. 为网站服务生成临时 `*.zeabur.app` 域名；不要为 MySQL 绑定公网域名。
6. 访问 `/health.php`，应返回 `{"status":"ok"}`。
7. 生产数据库先执行 `migrations/001_initial.sql`，让表结构和版本记录可审计；旧安装仍可由接口兼容升级。
8. 用测试账号完成注册、保存、退出、另一浏览器登录和恢复数据的回归测试。
9. 验证后再绑定正式域名并启用数据库自动备份。

## 上线检查

- `/db_api.php?action=pull` 返回 JSON 错误而不是 PHP 源码。
- `config.local.php` 不在 GitHub 仓库、构建上下文或容器文件中。
- MySQL 中已创建 `schema_migrations`、`users`、`user_sessions`、`user_data`、`coc_rooms`、`coc_room_messages`、`coc_library_modules` 和 `api_rate_limits`。
- HTTPS 有效，主站、Library、Workshop 和 Service Worker 均能加载。
- 设备 A 创建的调查员和存档能在设备 B 登录后恢复。
- 已配置数据库定期备份，并至少完成一次恢复演练。
