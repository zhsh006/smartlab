#!/usr/bin/env bash
# SmartLab Workbench — h3clab 部署脚本（可重复执行；首次自动初始化）
# 用法：bash deploy/h3clab/deploy.sh
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$PROJECT_ROOT"

BASE_IMAGE='elabftw/elabimg@sha256:500b0bcaed1b3b9f825d5272cd517072cd32f3e22d5dc68ec6f72f5a532cb745'
COMPOSE=(docker compose --env-file deploy/h3clab/.env -f deploy/h3clab/docker-compose.yml)
WEB=smartlab-workbench-web
DB=smartlab-workbench-mysql
SITE_URL_DEFAULT="https://172.18.21.142:3159"
SYSADMIN_EMAIL="smartlab.sysadmin@local.test"
SYSADMIN_PW="p8wi4PscFqhj5drxOBkobEdw"   # 与 deploy/local/README.md 记录的本地管理员一致

say() { printf '\n=== %s ===\n' "$*"; }

if [ ! -f deploy/h3clab/.env ]; then
  say "生成 deploy/h3clab/.env（随机密钥，权限 600）"
  ROOT_PW="$(openssl rand -hex 16)"
  DB_PW="$(openssl rand -hex 16)"
  # Alpine 镜像的 php.ini 是模板，直接 docker run php 需用 -d 覆盖 open_basedir/memory_limit
  SECRET="$(docker run --rm --entrypoint php "$BASE_IMAGE" -d open_basedir= -d memory_limit=256M bin/init tools:genkey 2>/dev/null | tail -n1 | tr -d '\r')"
  if [[ "$SECRET" != def* ]]; then
    echo "密钥生成失败（tools:genkey 未返回有效 Defuse 密钥）" >&2
    exit 1
  fi
  cat > deploy/h3clab/.env <<EOF
MYSQL_ROOT_PASSWORD=$ROOT_PW
MYSQL_DATABASE=elabftw
MYSQL_USER=smartlab
MYSQL_PASSWORD=$DB_PW
ELABFTW_SECRET_KEY=$SECRET
ELABFTW_SITE_URL=${SITE_URL_DEFAULT}
ELABFTW_BIND=0.0.0.0
ELABFTW_PORT=3159
EOF
  chmod 600 deploy/h3clab/.env
fi

say "准备 runtime 目录与自签名证书（含局域网 IP）"
mkdir -p runtime/uploads runtime/cache runtime/exports runtime/agent-inbox runtime/certs
if [ ! -f runtime/certs/cert.pem ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout runtime/certs/key.pem -out runtime/certs/cert.pem \
    -subj "/CN=172.18.21.142" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1,IP:172.18.21.142"
  cp runtime/certs/cert.pem runtime/certs/local-ca.pem
fi
docker run --rm --user 0:0 --entrypoint sh -v "$PROJECT_ROOT/runtime:/rt" "$BASE_IMAGE" -c \
  'chown -R 101:101 /rt/uploads /rt/cache /rt/exports /rt/agent-inbox; chmod 644 /rt/certs/cert.pem /rt/certs/local-ca.pem; chmod 600 /rt/certs/key.pem'

say "构建应用镜像（基础镜像已缓存，不拉取）"
"${COMPOSE[@]}" build --pull=false elabftw

say "启动数据库"
"${COMPOSE[@]}" up -d --wait --wait-timeout 180 mysql

TABLES="$(docker exec "$DB" sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();" "$MYSQL_DATABASE"' 2>/dev/null | tr -d '\r' | tail -n1)"
if [ "${TABLES:-0}" = "0" ]; then
  say "初始化 eLabFTW 结构并创建管理员（$SYSADMIN_EMAIL）"
  # 该镜像的 php.ini 为模板，需先让容器完成运行时配置，再在容器内执行 bin/init
  "${COMPOSE[@]}" up -d elabftw
  for _ in $(seq 1 30); do
    if docker exec "$WEB" php -v >/dev/null 2>&1; then break; fi
    sleep 2
  done
  docker exec "$WEB" sh -lc \
    "if [ -x /elabftw/bin/init ]; then php /elabftw/bin/init db:install -e '$SYSADMIN_EMAIL' -p '$SYSADMIN_PW' -f SmartLab -l Admin -t '智能医学实验室'; else php /elabftw/bin/console db:install -e '$SYSADMIN_EMAIL' -p '$SYSADMIN_PW' -f SmartLab -l Admin -t '智能医学实验室'; fi"
  say "应用 SmartLab 扩展表与迁移"
  for migration in 001_smartlab_phase1.sql 001b_v3_agent_inbox.sql 002_v3_ingest_safety.sql 003_wb_conditions.sql; do
    docker exec -i "$DB" sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 "$MYSQL_DATABASE"' < "database/elabftw/$migration"
    echo "applied $migration"
  done
else
  say "数据库已有 $TABLES 张表，跳过初始化"
fi

say "启动应用并等待健康"
"${COMPOSE[@]}" up -d --wait --wait-timeout 180 elabftw

say "创建运行期数据表（由模型构造函数幂等创建）"
docker cp tools/warmup-runtime-tables.php "$WEB:/elabftw/cache/warmup-runtime-tables.php"
docker exec "$WEB" php /elabftw/cache/warmup-runtime-tables.php

say "运行状态"
docker ps --format '{{.Names}}  {{.Status}}  {{.Ports}}' | grep smartlab-workbench || true
echo
echo "访问地址：$(grep -E '^ELABFTW_SITE_URL=' deploy/h3clab/.env | cut -d= -f2-)"
echo "管理员：$SYSADMIN_EMAIL"
echo "演示数据与进一步说明见 deploy/h3clab/README.md"
