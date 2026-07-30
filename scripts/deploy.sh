#!/usr/bin/env bash
#
# Production deploy for stock_app_backend.
#
# `queue:restart` at the end is not optional: notification delivery depends on
# the queue workers, and without a restart they keep running the old code (and
# the old cached config) indefinitely.
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/stock_app_backend}"

cd "$APP_DIR"

git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force

php artisan optimize:clear
# config:cache MUST run before queue:restart, or the restarted workers boot
# with the previous config cache.
php artisan config:cache
php artisan route:cache

# Graceful: workers finish their current job, then exit. systemd restarts them.
php artisan queue:restart

echo "Deploy complete. Verify the workers came back:"
echo "  systemctl status 'laravel-queue@*'"
