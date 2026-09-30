#!/bin/bash
# tenant/provision.sh — turn a fresh Ubuntu 24.04 container into a tiknix app host.
#
# Run AS ROOT inside the container, over SSH, by the control plane (lib/TenantHost.php):
#   ssh root@10.10.10.N 'bash -s' < tenant/provision.sh   (with the settings below in the env)
#
# Idempotent: every step checks before it acts, so re-running it repairs a tenant rather
# than failing on one. This file IS the tenant image (RUNTIME-SPLIT-MAP.md step 4): the
# container is Proxmox's stock ubuntu-24.04-standard template, and everything tiknix needs is
# put there by this script, versioned with the control plane — no image registry, no custom
# template to publish.
#
# What the tenant ends up with:
#   PHP 8.5 (ondrej PPA — the same PHP core runs) + nginx, PHP-FPM pool running as `app`
#   /srv/app                  the app: a git repository, cloned from core once, then its own
#   app@ over SSH             the builder's door (core's tenant key); root@ is provisioning only
#   ~app/.git-credentials,    read-only access to core's git endpoint (this app's deploy token):
#   ~app/.config/composer/    its own repository at first clone, and the tiknix/runtime package
#     auth.json
#
# Required environment (TenantHost passes them; a missing one stops here, named):
#   APP_SLUG        the app's slug (instance registry), also its git endpoint username
#   CORE_HOST       core's hostname (the git endpoint's host), e.g. tiknix.com
#   CORE_IP         the address the tenant reaches core on (NAT'd tenants do not hairpin)
#   DEPLOY_TOKEN    the app's read-only deploy token for CORE_HOST/git/
#   BUILDER_PUBKEY  core's tenant SSH public key, authorised for the `app` user
#   APP_BASEURL     https://<the app's public host>
#   APP_NAME        the app's display name
#   APP_KEY         64 hex chars, [security] app_key
set -euo pipefail
for v in APP_SLUG CORE_HOST CORE_IP DEPLOY_TOKEN BUILDER_PUBKEY APP_BASEURL APP_NAME APP_KEY; do
  if [ -z "${!v:-}" ]; then echo "provision: $v is not set" >&2; exit 2; fi
done
export DEBIAN_FRONTEND=noninteractive
PHPV=8.5
APP_DIR=/srv/app
say() { echo "== $*"; }

say "packages"
if ! command -v php$PHPV >/dev/null 2>&1; then
  apt-get update -q
  apt-get install -yq --no-install-recommends software-properties-common ca-certificates curl gnupg
  add-apt-repository -y ppa:ondrej/php
  apt-get update -q
fi
apt-get install -yq --no-install-recommends \
  php$PHPV-fpm php$PHPV-cli php$PHPV-sqlite3 php$PHPV-mbstring php$PHPV-intl php$PHPV-zip \
  php$PHPV-gd php$PHPV-curl php$PHPV-xml php$PHPV-apcu php$PHPV-mysql \
  nginx git unzip curl ca-certificates openssh-server >/dev/null
if ! command -v composer >/dev/null 2>&1; then
  # getcomposer.org's installer, checked against its published signature
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  if [ "$EXPECTED" != "$ACTUAL" ]; then echo "provision: composer installer signature mismatch" >&2; exit 3; fi
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
php -v | head -1; composer --version 2>/dev/null | head -1

say "core is $CORE_HOST at $CORE_IP"
grep -q " $CORE_HOST\$" /etc/hosts || echo "$CORE_IP $CORE_HOST" >> /etc/hosts

say "the app user"
id app >/dev/null 2>&1 || useradd --create-home --shell /bin/bash app
install -d -o app -g app -m 700 /home/app/.ssh
touch /home/app/.ssh/authorized_keys
grep -qF "$BUILDER_PUBKEY" /home/app/.ssh/authorized_keys || echo "$BUILDER_PUBKEY" >> /home/app/.ssh/authorized_keys
chown app:app /home/app/.ssh/authorized_keys; chmod 600 /home/app/.ssh/authorized_keys
install -d -o app -g app -m 755 "$APP_DIR"

say "read-only access to $CORE_HOST/git/"
sudo -u app git config --global credential.helper store
sudo -u app git config --global user.name "$APP_NAME"
sudo -u app git config --global user.email "app@$APP_SLUG.invalid"
sudo -u app git config --global init.defaultBranch main
printf 'https://%s:%s@%s\n' "$APP_SLUG" "$DEPLOY_TOKEN" "$CORE_HOST" > /home/app/.git-credentials
install -d -o app -g app -m 700 /home/app/.config/composer
printf '{"http-basic":{"%s":{"username":"%s","password":"%s"}}}\n' "$CORE_HOST" "$APP_SLUG" "$DEPLOY_TOKEN" > /home/app/.config/composer/auth.json
chown app:app /home/app/.git-credentials /home/app/.config/composer/auth.json
chmod 600 /home/app/.git-credentials /home/app/.config/composer/auth.json

say "the app"
if [ ! -d "$APP_DIR/.git" ]; then
  sudo -u app git clone -q "https://$CORE_HOST/git/$APP_SLUG.git" "$APP_DIR"
  # From here the tenant's repository is the app's home: the builder works in it over SSH and
  # the control plane reads from it. It never pulls "builds" from core, so no origin.
  sudo -u app git -C "$APP_DIR" remote rename origin seed
fi
cd "$APP_DIR"
sudo -u app composer install --no-interaction --no-progress -q
if [ ! -f conf/config.ini ]; then
  sudo -u app cp conf/config.example.ini conf/config.ini
  sudo -u app sed -i \
    -e "s#^name = .*#name = \"$APP_NAME\"#" \
    -e "s#^baseurl = .*#baseurl = \"$APP_BASEURL\"#" \
    -e "s#^app_key = .*#app_key = \"$APP_KEY\"#" \
    -e "s#^environment = .*#environment = \"production\"#" \
    -e "s#^debug = .*#debug = false#" \
    conf/config.ini
fi
if ! sudo -u app php scripts/clitool.php --build > /tmp/tiknix-build.log 2>&1; then
  grep -E "error|FAILED" /tmp/tiknix-build.log | tail -20 >&2
  echo "provision: clitool --build failed (full output in the tenant's /tmp/tiknix-build.log)" >&2
  exit 4
fi
grep -cE ": ok$" /tmp/tiknix-build.log | sed 's/^/seeds ok: /'
sudo -u app php scripts/clitool.php --agent-sync | tail -1

say "php-fpm pool and nginx"
cat > /etc/php/$PHPV/fpm/pool.d/app.conf <<EOF
[app]
user = app
group = app
listen = /run/php/app.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
php_admin_value[error_log] = /srv/app/log/php-error.log
EOF
rm -f /etc/php/$PHPV/fpm/pool.d/www.conf
cat > /etc/nginx/sites-available/app <<'EOF'
# The app, behind capricorn (which terminates TLS and proxies here on port 80).
server {
    listen 80 default_server;
    server_name _;
    root /srv/app/public;
    index index.php;
    client_max_body_size 25m;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/app.sock;
        # capricorn terminates TLS: tell PHP the original scheme
        fastcgi_param HTTPS $fwd_https;
    }
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(log|db|sqlite|ini)$ { deny all; }
}
EOF
cat > /etc/nginx/conf.d/forwarded.conf <<'EOF'
map $http_x_forwarded_proto $fwd_https { default ""; https on; }
EOF
ln -sf /etc/nginx/sites-available/app /etc/nginx/sites-enabled/app
rm -f /etc/nginx/sites-enabled/default
nginx -t 2>&1 | tail -1
systemctl enable -q php$PHPV-fpm nginx
systemctl restart php$PHPV-fpm nginx

say "done: $(sudo -u app git -C "$APP_DIR" log --oneline -1) on runtime $(sudo -u app php -r 'require "/srv/app/vendor/autoload.php"; echo \app\InstanceUpdate::installedRuntime("/srv/app")["version"] ?? "?";')"
