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

# Warm up cache
echo "Warming up cache..."
php bin/console cache:warmup

echo "Ready."
exec frankenphp run --config /etc/caddy/Caddyfile
