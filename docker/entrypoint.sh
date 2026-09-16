#!/bin/sh
set -e

# Runs in every container built from this image (app/php-fpm, queue worker,
# Reverb) before the real command starts. Each container caches into its
# own filesystem (baked from the image, not a shared volume), so there's no
# race between them. Migrations are deliberately NOT run here — that's a
# one-off deploy step (`docker compose run --rm app php artisan migrate
# --force`), not something that should fire every time any container starts.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
