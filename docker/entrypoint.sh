#!/usr/bin/env bash
# First-boot bootstrap for the tiknix container: seed config (the real .ini files
# are gitignored and absent from the image), generate an encryption key, and
# initialize the SQLite database. Idempotent — safe on every start.
set -euo pipefail
cd /var/www/html

# 1) Seed config from the committed examples on first boot.
[ -f conf/config.ini ] || cp conf/config.example.ini conf/config.ini
[ -f conf/github.ini ] || { [ -f conf/github.example.ini ] && cp conf/github.example.ini conf/github.ini; } || true

# 2) Ensure EncryptionService has a key ([security] app_key, 64 hex chars).
if ! grep -qE '^app_key[[:space:]]*=[[:space:]]*"[0-9a-f]{64}"' conf/config.ini; then
  KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
  if grep -qE '^app_key[[:space:]]*=' conf/config.ini; then
    sed -ri "s|^app_key[[:space:]]*=.*|app_key = \"${KEY}\"|" conf/config.ini
  else
    sed -ri "s|^\[security\]|[security]\napp_key = \"${KEY}\"|" conf/config.ini
  fi
  echo "entrypoint: generated [security] app_key"
fi

# 3) Initialize the SQLite database on first boot.
mkdir -p database storage/logs
if [ ! -s database/tiknix.db ]; then
  echo "entrypoint: initializing database/tiknix.db"
  for f in sql/schema.sql sql/workbench_schema.sql sql/map_permissions.sql; do
    [ -f "$f" ] && sqlite3 database/tiknix.db < "$f" || true
  done
  echo "entrypoint: fresh install — open the site in a browser to complete first-run setup (/install)"
fi

# 4) Make runtime paths writable by the web user.
chown -R www-data:www-data database storage conf 2>/dev/null || true

# 5) Listen on the platform's port. Hyperlift injects APPLICATION_PORT (8080); also
# honor $PORT (Cloud Run, etc.), else default 8080. Rewrites the global Listen and the
# vhost. Idempotent.
PORT="${APPLICATION_PORT:-${PORT:-8080}}"
sed -ri "s/^Listen 80\b/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf
echo "entrypoint: Apache listening on port ${PORT}"

exec "$@"
