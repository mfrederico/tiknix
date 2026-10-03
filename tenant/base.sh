#!/bin/bash
# tenant/base.sh — the SYSTEM layer of a tiknix tenant, identical for every app: PHP 8.5 (the
# same PPA core runs) + nginx + a PHP-FPM pool running as `app`, Composer, the `app` user and
# its own Claude Code, then trimmed. Run as root over SSH (lib/TenantHost.php).
#
# Run ONCE per template (TenantHost::buildTemplate: base.sh, seal.sh, convert to a Proxmox
# template); every tenant is a linked clone of it, so the per-tenant work is only app.sh.
# Idempotent: on a container that already has it all, it changes nothing.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
PHPV=8.5
say() { echo "== $*"; }

say "no docs, man pages or translations (for everything installed from here on)"
cat > /etc/dpkg/dpkg.cfg.d/01-tiknix-slim <<'EOF2'
path-exclude=/usr/share/doc/*
path-include=/usr/share/doc/*/copyright
path-exclude=/usr/share/man/*
path-exclude=/usr/share/info/*
path-exclude=/usr/share/locale/*
path-include=/usr/share/locale/locale.alias
EOF2

say "packages"
if ! command -v php$PHPV >/dev/null 2>&1; then
  apt-get update -q
  apt-get install -yq --no-install-recommends software-properties-common ca-certificates curl gnupg
  add-apt-repository -y ppa:ondrej/php
  apt-get update -q
fi
apt-get install -yq --no-install-recommends \
  php$PHPV-fpm php$PHPV-cli php$PHPV-sqlite3 php$PHPV-mbstring php$PHPV-intl php$PHPV-zip \
  php$PHPV-gd php$PHPV-imagick php$PHPV-curl php$PHPV-xml php$PHPV-apcu php$PHPV-mysql php$PHPV-redis \
  nginx git unzip curl ca-certificates openssh-server tmux valkey-server >/dev/null
# tmux: the app's /claude page runs `claude setup-token` in a tmux session (AgentLogin)
# valkey + php-redis: the query cache's version store ([cache] version_store = valkey), shared by
# php-fpm, cron and pipelines — with apcu a pipeline's write reached the web only after the TTL
if ! command -v composer >/dev/null 2>&1; then
  # getcomposer.org's installer, checked against its published signature
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  if [ "$EXPECTED" != "$ACTUAL" ]; then echo "provision: composer installer signature mismatch" >&2; exit 3; fi
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
# The downloaded .debs and package lists are only needed to install; ~350 MB left behind otherwise.
apt-get clean
rm -rf /var/lib/apt/lists/*
php -v | head -1; composer --version 2>/dev/null | head -1


say "the app user"
id app >/dev/null 2>&1 || useradd --create-home --shell /bin/bash --uid 1000 app
install -d -o app -g app -m 700 /home/app/.ssh
install -d -o app -g app -m 755 /srv/app

say "the app's own agent (Claude Code, for builder tasks and pipeline agent steps)"
if [ ! -x /home/app/.local/bin/claude ]; then
  sudo -iu app bash -c 'curl -fsSL https://claude.ai/install.sh | bash' >/dev/null
fi
sudo -iu app /home/app/.local/bin/claude --version | head -1

say "valkey (the query cache's version store: ~4 MB, loopback only, nothing persisted)"
cat > /etc/valkey/valkey.conf <<'EOF2'
bind 127.0.0.1 -::1
port 6379
protected-mode yes
daemonize no
supervised systemd
dir /var/lib/valkey
save ""
appendonly no
maxmemory 16mb
maxmemory-policy allkeys-lru
loglevel notice
logfile /var/log/valkey/valkey-server.log
databases 1
EOF2
systemctl enable valkey-server >/dev/null 2>&1
systemctl restart valkey-server
valkey-cli ping | grep -q PONG || { echo "provision: valkey did not answer PONG" >&2; exit 5; }

say "php-fpm pool (as app) and nginx (/srv/app/public)"
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
# The packaged unit sets PrivateDevices=yes: no /dev/ptmx, so tmux started from a request
# (the /claude sign-in terminal, AgentLogin) dies with "server exited unexpectedly". The
# container is the isolation boundary here — one app per container.
mkdir -p /etc/systemd/system/php$PHPV-fpm.service.d
printf '[Service]\nPrivateDevices=no\n' > /etc/systemd/system/php$PHPV-fpm.service.d/pty.conf
systemctl daemon-reload
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

say "trim: services a tenant never uses, their packages, docs and caches"
# postfix (mail goes through the Mailgun API), rsyslog (journald keeps the logs),
# networkd-dispatcher (python, unused), polkit/packagekit, sysstat. software-properties-common
# only mattered for adding the PHP PPA, which is done.
apt-get purge -yq --auto-remove postfix rsyslog networkd-dispatcher polkitd packagekit sysstat software-properties-common >/dev/null 2>&1 || true
rm -rf /usr/share/doc/* /usr/share/man/* /usr/share/info/* 
find /usr/share/locale -mindepth 1 -maxdepth 1 ! -name 'locale.alias' -exec rm -rf {} +
apt-get clean
rm -rf /var/lib/apt/lists/*
say "base done: $(php -v | head -1 | cut -c1-20), $(df -h / | tail -1 | awk '{print $3}') used"
