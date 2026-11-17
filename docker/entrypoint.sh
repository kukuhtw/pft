#!/bin/sh
# Container entrypoint. Usage: entrypoint <web|worker|scheduler|artisan args...>
set -eu

ROLE="${1:-web}"
export CONTAINER_ROLE="$ROLE"
cd /var/www/html

as_app() { su-exec www-data "$@"; }

# Named volume may be empty on first start: recreate Laravel's storage tree.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is not set. Generate one with: php -r \"echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;\"" >&2
    exit 1
fi

# Wait for the database (max ~60s).
i=0
until as_app php -r '
    try { new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); }
    catch (Throwable $e) { exit(1); }'; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then echo "FATAL: database not reachable at ${DB_HOST:-?}" >&2; exit 1; fi
    echo "Waiting for database (${i}/30)..."
    sleep 2
done

# Cache config/routes/views/events from the runtime environment (secrets stay out of the image).
as_app php artisan optimize --no-interaction

case "$ROLE" in
    web)
        if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
            as_app php artisan migrate --force --no-interaction
        fi
        if [ -n "${DASHBOARD_ADMIN_EMAIL:-}" ] && [ -n "${DASHBOARD_ADMIN_PASSWORD:-}" ]; then
            # Idempotent: creates the dashboard login or updates it when the .env value changed.
            as_app php artisan dashboard:user --no-interaction || echo "WARNING: dashboard:user failed (check DASHBOARD_ADMIN_*)" >&2
        fi
        if [ "${VERIFY_LEDGER_ON_BOOT:-true}" = "true" ]; then
            # Non-fatal: report but keep serving; the daily scheduled check raises a CRITICAL log.
            as_app php artisan ledger:verify || echo "WARNING: ledger:verify reported issues" >&2
        fi
        exec /usr/bin/supervisord -c /etc/supervisord.conf
        ;;
    worker)
        exec su-exec www-data php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --no-interaction
        ;;
    scheduler)
        exec su-exec www-data php artisan schedule:work --no-interaction
        ;;
    *)
        exec su-exec www-data php artisan "$@"
        ;;
esac
