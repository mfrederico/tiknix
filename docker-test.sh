#!/usr/bin/env bash
# Proto: run test1-ec34d9 as an isolated Docker container with the four persistence
# volumes (conf/secrets, db, public uploads, secure uploads), then capricorn .proxy's
# tiknix.ngn.sh -> 127.0.0.1:8091. Run on the Docker host (needs docker + the base image
# ghcr.io/mfrederico/tiknix-base:8.3 reachable).
set -euo pipefail

IMG=hosted/test1-ec34d9:latest
SRC=/var/www/html/default/test1-ec34d9.tiknix

# 1) Build the image from the instance's Dockerfile (COPY . + composer install --no-dev).
docker build -t "$IMG" "$SRC"

# 2) Replace any prior container so re-runs are clean (volumes below are NOT removed).
docker rm -f hosted-test1-ec34d9 2>/dev/null || true

# 3) Run it. -p binds localhost only (capricorn proxies to it). The four -v volumes hold
#    ALL the gitignored data/secrets, so a rebuild/redeploy never touches them.
docker run -d --name hosted-test1-ec34d9 --restart unless-stopped \
    -p 127.0.0.1:8091:8080 \
    -e BASE_URL=https://tiknix.ngn.sh \
    -v t1_conf:/var/www/html/conf \
    -v t1_db:/var/www/html/database \
    -v t1_uploads_pub:/var/www/html/public/uploads \
    -v t1_uploads_sec:/var/www/html/secure/uploads \
    "$IMG"

# 4) Probe it directly (before wiring the .proxy).
sleep 2
echo "--- container status ---"
docker ps --filter name=hosted-test1-ec34d9 --format '{{.Names}} {{.Status}} {{.Ports}}'
echo "--- HTTP probe (expect a redirect to /install on a fresh DB) ---"
curl -s -o /dev/null -w "http://127.0.0.1:8091/ -> HTTP %{http_code} (Location: %{redirect_url})\n" http://127.0.0.1:8091/ || echo "no response yet — check: docker logs hosted-test1-ec34d9"
