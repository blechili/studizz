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

# Force mpm_prefork as the ONLY enabled MPM (mod_php requires it — it isn't
# thread-safe). The Dockerfile already tries to enforce this at build time,
# but Debian's apache2 package can re-enable mpm_event as a side effect of
# later apt triggers, so re-assert it here, directly on the symlinks, right
# before Apache actually starts — this is the last point before startup and
# doesn't depend on a2dismod/a2enmod's exit codes or timing.
rm -f /etc/apache2/mods-enabled/mpm_event.load  /etc/apache2/mods-enabled/mpm_event.conf
rm -f /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

exec apache2-foreground
