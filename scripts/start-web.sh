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

sed -ri "s/^[[:space:]]*Listen[[:space:]]+[0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:[0-9]+>#<VirtualHost *:${port}>#" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
