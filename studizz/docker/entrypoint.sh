#!/bin/sh
# Studiz — Railway container entrypoint.
# Railway assigns a dynamic $PORT at runtime (not build time), so the vhost
# is templated with the literal token __PORT__ and substituted here, once,
# before Apache starts. Falls back to 8080 for local `docker run` testing
# without Railway's env injection.
set -e

PORT="${PORT:-8080}"

sed -i "s/__PORT__/${PORT}/g" /etc/apache2/sites-enabled/000-default.conf
# Overwrite outright rather than sed the stock file — it also contains
# inert "Listen 443" lines inside <IfModule ssl_module> blocks that would
# otherwise get rewritten too (harmless since ssl_module isn't loaded here,
# but overwriting is simpler than reasoning about it).
printf 'Listen %s\n' "${PORT}" > /etc/apache2/ports.conf

exec apache2-foreground
