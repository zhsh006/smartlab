# SmartLab Workbench — h3clab 部署

本目录是 **SmartLab Workbench（V3 版）** 在 h3clab 服务器上的正式部署配置。

## 与服务器上其他 SmartLab 实例的关系（互不影响）

| 实例 | 目录 | 容器 | 端口 | 说明 |
|---|---|---|---|---|
| **SmartLab Workbench（本目录）** | `/home/h3clab/smartlab-workbench` | `smartlab-workbench-web` / `smartlab-workbench-mysql` | `0.0.0.0:3159` | 本次迁移的正式开发/演示实例 |
| WB 预览（上一轮，已存在） | `/home/h3clab/smartlab-previews/wb-20261014` | `smartlab-wb-preview*` | `127.0.0.1:3160` | 保留不动 |
| 旧源码副本 | `/home/h3clab/smartlab-v4` | — | — | 保留不动 |
| 线上专利/旧系统 | `/srv/smartlab-v5/...` | `labsmart-patent-2*` | `5280/5543/3323...` | 生产实例，禁止改动 |

本部署使用独立目录、独立 Compose 项目名（`smartlab-workbench`）、独立容器名、独立数据卷和独立端口，**不会与上述实例共用数据库、附件卷或端口**。

## 部署

```bash
cd /home/h3clab/smartlab-workbench/LabSmart
bash deploy/h3clab/deploy.sh
```

脚本可重复执行：

1. 首次运行时生成 `deploy/h3clab/.env`（随机库密码 + `tools:genkey` 生成的有效密钥，权限 600）；
2. 创建 `runtime/{uploads,cache,exports,agent-inbox,certs}` 并修正权限、生成自签名证书（SAN 含 `172.18.21.142`/`localhost`）；
3. 构建应用镜像（基础镜像按摘要固定，不拉取）；
4. 启动 MySQL；空库时执行 eLabFTW 结构初始化 + 管理员，并应用 `database/elabftw/001~003` 扩展迁移；
5. 启动应用并等待健康检查。

## 访问

- 地址：`https://172.18.21.142:3159`（局域网；自签名证书，浏览器需信任或忽略警告）
- 管理员：`smartlab.sysadmin@local.test` / `p8wi4PscFqhj5drxOBkobEdw`
- 演示学生（由种子脚本/验证创建后可用）：`preview.student@local.test`

## 演示数据

```bash
cd /home/h3clab/smartlab-workbench/LabSmart
docker cp tools/seed-demo.php smartlab-workbench-web:/elabftw/cache/seed-demo.php
docker exec smartlab-workbench-web php /elabftw/cache/seed-demo.php
```

演示图片在 `tools/demo-assets/`，可通过页面“快速记录 → 照片与文件”上传到实验，或使用 `/api/v2/experiments/<id>/uploads`。

## 仪器数据采集（Agent）

Agent 脚本在 `tools/agent/`（Python3 标准库，systemd 模板 + README）。仪器电脑（或本服务器模拟目录）运行后，
在网页「数据」页生成令牌，Agent 把稳定后的文件包上传到 `/smartlab-agent.php`，进入待关联队列，由人确认归档。

## 更新源码后

```bash
bash deploy/h3clab/deploy.sh
```

镜像会重建；容器启动时会自动清理本实例的 Twig 编译缓存（镜像内已打补丁），避免复用旧模板。

## 备份（建议）

```bash
# 数据库
docker exec smartlab-workbench-mysql sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction -uroot "$MYSQL_DATABASE"' > backup-$(date +%F).sql
# 附件
tar -czf uploads-$(date +%F).tar.gz -C runtime uploads
```
