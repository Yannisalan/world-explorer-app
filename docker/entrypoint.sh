#!/bin/sh
set -e

# Render routes traffic to $PORT. Rewrite Apache's listen directive before
# starting so the same image works on any port the platform assigns.
if [ -n "${PORT}" ]; then
    # The sed expression is deliberately NOT wrapped in double quotes.
    # "$!" is a POSIX parameter expansion (the PID of the last background job),
    # so in "s!^Listen 80$!Listen ${PORT}!" the shell swallows the "$" and the
    # "!" along with it, leaving sed with no closing delimiter and failing with
    # `unterminated 's' command`. Single-quote the literal fragments and expand
    # only ${PORT}. Do not simplify this back into one double-quoted string.
    sed -ri 's!^Listen 80$!Listen '"${PORT}"'!' /etc/apache2/ports.conf
fi

exec "$@"
