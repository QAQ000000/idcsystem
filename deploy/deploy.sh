#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/idcsystem}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"

cd "$APP_DIR"

git fetch --prune origin
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$NPM_BIN" ci
"$NPM_BIN" run build

"$PHP_BIN" artisan down --render="errors::503" || true

"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan storage:link || true
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

# 当前仓库未安装 Horizon，生产默认重启 queue worker。
"$PHP_BIN" artisan queue:restart
supervisorctl reread
supervisorctl update
supervisorctl restart idcsystem-worker:* idcsystem-scheduler

"$PHP_BIN" artisan up

echo "Deploy complete."
