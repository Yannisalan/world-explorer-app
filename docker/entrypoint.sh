#!/bin/sh
set -e

# Render routes traffic to $PORT. Rewrite Apache's listen directive before
# starting so the same image works on any port the platform assigns.
if [ -n "${PORT}" ]; then
    sed -ri "s!^Listen 80$!Listen ${PORT}!" /etc/apache2/ports.conf
fi

exec "$@"
