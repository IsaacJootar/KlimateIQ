#!/usr/bin/env bash
#
# Production deploy — run on the EC2 box (invoked by .github/workflows/deploy.yml over SSH,
# or manually: `bash /var/www/klimateiq/deploy.sh`).
#
# On failure the site is left in maintenance mode on purpose: a visibly-down site beats a
# half-migrated live one. Re-run after fixing, or `php artisan up` to force it back.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/klimateiq}"
cd "$APP_DIR"

echo "==> Maintenance mode"
php artisan down --retry=15 || true

echo "==> Pulling origin/main"
git fetch --all --quiet
git reset --hard origin/main
git log -1 --oneline

echo "==> Composer"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Migrations"
php artisan migrate --force

echo "==> Idempotent data seed (new indices + sectors + crop calendar — safe to re-run, does not touch tuned calibration/weights)"
php artisan db:seed --class=AdditionalIndicesSeeder --force
php artisan db:seed --class=SectorSeeder --force
php artisan db:seed --class=CropCalendarSeeder --force
php artisan db:seed --class=FacilitySeeder --force

echo "==> Frontend build"
npm ci --no-audit --no-fund
npm run build

echo "==> Caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Restart workers"
php artisan queue:restart
# If php-fpm opcache is set to opcache.validate_timestamps=0, also reload it (needs sudo):
# sudo systemctl reload php-fpm 2>/dev/null || true

echo "==> Live"
php artisan up

echo "==> Done: $(git rev-parse --short HEAD)"
