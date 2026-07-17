#!/bin/sh
set -eu

# Railway injects PORT. Docker Compose and ordinary Apache deployments keep
# the image default of 80 when PORT is absent.
port="${PORT:-80}"
case "$port" in
    ''|*[!0-9]*)
        echo "PORT must be an integer from 1 to 65535" >&2
        exit 1
        ;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo "PORT must be an integer from 1 to 65535" >&2
    exit 1
fi

# Railway mounts a fresh volume as root at container start, after image-build
# ownership has been applied. Prepare only the directories PHP writes to and
# avoid a recursive chown that would make startup scale with stored slips.
install -d -o www-data -g www-data -m 0750 \
    /var/www/html/storage \
    /var/www/html/storage/private \
    /var/www/html/storage/private/slips \
    /var/www/html/storage/logs \
    /var/www/html/storage/sessions \
    /var/www/html/storage/cache

# The official php:apache image runs non-thread-safe mod_php, which requires
# prefork. Normalize again at runtime so a stale layer or mounted Apache config
# cannot leave event/worker enabled beside prefork and crash the container.
a2dismod -q -f mpm_event mpm_worker
a2enmod -q mpm_prefork

sed -ri "s/^[[:space:]]*Listen[[:space:]]+[0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:[0-9]+>#<VirtualHost *:${port}>#" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
