#!/bin/sh
set -e

cd /application/app

# Generate JWT keys if they don't exist
if [ ! -f config/jwt/private.pem ]; then
    echo "Generating JWT keys..."
    php bin/console lexik:jwt:generate-keypair --skip-if-exists
fi

# Run database migrations
echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Rebuild cache. Clear any cache compiled by a previous image version (which can
# mismatch the deployed code, e.g. ArgumentCountError when a constructor changed),
# then warm. The removal is best-effort: it must never abort startup (under set -e)
# if a leftover file is owned by a different user — cache:warmup overwrites anyway.
echo "Rebuilding cache..."
rm -rf /application/var/cache/* 2>/dev/null || true
php bin/console cache:warmup

echo "Ready."
exec frankenphp run --config /etc/caddy/Caddyfile
