#!/usr/bin/env bash
# First-boot bootstrap for the tiknix container: seed config (the real .ini files
# are gitignored and absent from the image), generate an encryption key, and
# initialize the SQLite database. Idempotent — safe on every start.
set -euo pipefail
cd /var/www/html

# 1) Seed config from the committed examples on first boot. The container runs on
# SQLite (step 3 inits the .db), so prefer the SQLite example — the generic
# config.example.ini defaults to MySQL and would fail to connect in-container.
if [ ! -f conf/config.ini ]; then
  if [ -f conf/config.sqlite.example.ini ]; then
    cp conf/config.sqlite.example.ini conf/config.ini
    echo "entrypoint: seeded conf/config.ini from config.sqlite.example.ini (sqlite)"
  else
    cp conf/config.example.ini conf/config.ini
    echo "entrypoint: seeded conf/config.ini from config.example.ini (generic)"
  fi
fi
echo "entrypoint: database type = $(sed -n 's/^type[[:space:]]*=[[:space:]]*"\([a-z]*\)".*/\1/p' conf/config.ini | head -1)"
[ -f conf/github.ini ] || { [ -f conf/github.example.ini ] && cp conf/github.example.ini conf/github.ini; } || true

# 1b) Point baseurl at the public URL when the platform provides one (the seeded
# example defaults to http://localhost:8080). Set BASE_URL in the Hyperlift env.
if [ -n "${BASE_URL:-}" ]; then
  sed -ri "s#^baseurl[[:space:]]*=.*#baseurl = \"${BASE_URL}\"#" conf/config.ini
  echo "entrypoint: baseurl = ${BASE_URL}"
fi

# 2) Ensure EncryptionService has a key ([security] app_key, 64 hex chars).
# Priority: APP_KEY env  ->  key already in config  ->  generate ephemeral.
# SET APP_KEY IN THE HYPERLIFT ENV: a generated key changes every boot, so anything
# encrypted (2FA secrets, stored tokens) becomes unreadable after a redeploy. A fixed
# APP_KEY makes encryption survive restarts even without a persistent volume.
if [ -n "${APP_KEY:-}" ] && printf '%s' "$APP_KEY" | grep -qE '^[0-9a-f]{64}$'; then
  KEY="$APP_KEY"; KEYSRC="APP_KEY env"
elif grep -qE '^app_key[[:space:]]*=[[:space:]]*"[0-9a-f]{64}"' conf/config.ini; then
  KEY=""; KEYSRC="existing config"          # already set (e.g. persisted config volume)
else
  KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
  KEYSRC="generated (EPHEMERAL — set APP_KEY to persist!)"
fi
if [ -n "$KEY" ]; then
  if grep -qE '^app_key[[:space:]]*=' conf/config.ini; then
    sed -ri "s|^app_key[[:space:]]*=.*|app_key = \"${KEY}\"|" conf/config.ini
  else
    sed -ri "s|^\[security\]|[security]\napp_key = \"${KEY}\"|" conf/config.ini
  fi
fi
echo "entrypoint: app_key source = ${KEYSRC}"
if [ -z "${APP_KEY:-}" ] && [ "$KEYSRC" != "existing config" ]; then
  echo "entrypoint: WARNING — no APP_KEY env set; encrypted data will not survive a redeploy."
fi

# 3) Initialize the SQLite database on first boot only. Idempotent: if database/ is a
# persistent volume, the .db survives redeploys and this init is skipped (so your data
# — and the completed install — persist). Mount a volume at /var/www/html/database.
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
