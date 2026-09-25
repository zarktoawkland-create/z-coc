# 安全部署说明

1. `config.local.php` 只用于传统虚拟主机或本地 PHP 环境，不要上传到公开 GitHub，也不要复制进容器镜像。
2. Zeabur 部署优先使用同一项目内 MySQL 服务注入的 `MYSQL_HOST`、`MYSQL_PORT`、`MYSQL_USERNAME`、`MYSQL_PASSWORD` 和 `MYSQL_DATABASE`。
3. 公开仓库只保留 `config.example.php`；其中只能包含占位符和非敏感默认值。
4. 网页与 PHP API 同域部署时，不设置 `APP_ALLOWED_ORIGINS`。跨域部署时填入逗号分隔的完整 HTTPS Origin，禁止使用 `*`。
5. 首次部署先执行 `migrations/001_initial.sql`；兼容旧安装的 PHP 接口仍会检查缺失表或列，因此首次兼容升级需要 `CREATE`、`ALTER`、`INDEX` 权限。
6. 登录令牌现在写入 `user_sessions`，按设备独立、可过期、可撤销；`users.auth_token` 仅作为历史令牌兼容层保留。
7. 曾经进入聊天记录、公开仓库或公开文件的数据库密码和 API Key 必须在服务商后台轮换。
8. 前端不内置 AI Key 或生图 Token。用户自行填写的服务地址、Key 和模型信息只保存在其浏览器中。
9. 正式上线后必须启用 HTTPS、MySQL 定期备份和恢复演练；不要为 MySQL 服务绑定不必要的公网域名。
10. `/health.php?probe=live` 只检查进程，默认 `/health.php` 检查数据库 readiness；两者都不返回数据库地址、账号、库名或错误详情。
