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

## 不覆盖原网站的并行部署

如果项目里已经存在正在运行的 `z-coc` 网站服务和 `mysql` 服务，不要删除或重部署原来的 `z-coc`。在同一个 Zeabur 项目中新增一个服务，例如 `z-coc-api`：

1. 将本仓库最新代码推送到 GitHub 分支，或在 Zeabur 中选择包含后端升级的分支。
2. 在项目中点击“新建服务”，从同一个仓库创建 `z-coc-api`，让它使用根目录 `Dockerfile`，容器端口保持 `8080`。
3. 在 `z-coc-api` 的“整合”中连接已有的 `mysql` 服务，确认注入 `MYSQL_HOST`、`MYSQL_PORT`、`MYSQL_USERNAME`、`MYSQL_PASSWORD` 和 `MYSQL_DATABASE`。不要新建第二个 MySQL。
4. 为 `z-coc-api` 生成临时域名，先访问 `/health.php?probe=live` 和 `/health.php`，确认进程和数据库都正常。
5. 在 MySQL 服务的“命令”或数据库控制台中执行 `migrations/001_initial.sql`。执行前先做一次数据库备份。
6. 先让 App 指向新 API，编辑 `assets/js/runtime-config.js`：

```js
webApiOrigin: '',
apiOrigin: 'https://你的-z-coc-api-临时域名',
```

这样原网站仍使用旧服务，App 使用新服务；两者通过同一个 MySQL 共享用户和云端数据。新 API 的会话写入独立的 `user_sessions`，不会覆盖旧网站的登录令牌。

7. 如果测试通过，再将 `webApiOrigin` 也改成新 API 域名，并重新部署网站前端；原来的 `z-coc` 服务仍可保留作为回滚版本。
8. App 的 API 请求来自 `https://localhost` 或 `capacitor://localhost`，因此 `z-coc-api` 的 `APP_ALLOWED_ORIGINS` 至少设置为：

```text
https://localhost,capacitor://localhost
```

如果网站前端也切到新 API，再追加网站的完整 HTTPS Origin。不要填写 `*`。

## 上线检查

- `/db_api.php?action=pull` 返回 JSON 错误而不是 PHP 源码。
- `config.local.php` 不在 GitHub 仓库、构建上下文或容器文件中。
- MySQL 中已创建 `schema_migrations`、`users`、`user_sessions`、`user_data`、`coc_rooms`、`coc_room_messages`、`coc_library_modules` 和 `api_rate_limits`。
- HTTPS 有效，主站、Library、Workshop 和 Service Worker 均能加载。
- 设备 A 创建的调查员和存档能在设备 B 登录后恢复。
- 已配置数据库定期备份，并至少完成一次恢复演练。
