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

# Rebuild cache. var/ may be on a persistent volume, so a container compiled by
# a previous image version can linger and mismatch the deployed code (e.g.
# ArgumentCountError when a service constructor changed). Clear it, then warm.
echo "Rebuilding cache..."
rm -rf /application/var/cache/*
php bin/console cache:warmup

echo "Ready."
exec frankenphp run --config /etc/caddy/Caddyfile
