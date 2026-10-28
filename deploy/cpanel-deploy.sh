#!/bin/bash
# Runs on the cPanel server after each deployment (see .cpanel.yml).
# Installs PHP packages, applies database migrations and rebuilds Laravel's caches.
set -euo pipefail
cd "$(dirname "$0")/.."
echo "Deploying StallionKing in $(pwd)"

# The command-line PHP on cPanel is often older than the website's; find 8.2 or newer.
PHP=""
for c in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/php php; do
    if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then
        PHP="$c"; break
    fi
done
if [ -z "$PHP" ]; then
    echo "ERROR: PHP 8.2 or newer was not found. Enable it in cPanel > MultiPHP Manager / EasyApache." >&2
    exit 1
fi
echo "Using $("$PHP" -r 'echo PHP_BINARY, " ", PHP_VERSION;')"

if [ ! -f .env ]; then
    echo "ERROR: $(pwd)/.env is missing. Create it once in File Manager (start from .env.example), then deploy again." >&2
    exit 1
fi

# vendor/ is not in git, so install it here (production packages only).
COMPOSER=""
for c in /opt/cpanel/composer/bin/composer /usr/local/bin/composer "$(command -v composer 2>/dev/null || true)"; do
    if [ -n "$c" ] && [ -f "$c" ]; then COMPOSER="$c"; break; fi
done
if [ -n "$COMPOSER" ]; then
    "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress
elif [ -d vendor ]; then
    echo "WARNING: Composer not found; keeping the existing vendor/ folder."
else
    echo "ERROR: Composer not found and there is no vendor/ folder. Upload and unzip vendor.zip here once." >&2
    exit 1
fi

"$PHP" artisan migrate --force
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
chmod -R ug+rwX storage bootstrap/cache
echo "Deployment finished."
