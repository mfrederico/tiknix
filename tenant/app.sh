#!/bin/bash
# tenant/app.sh — put ONE app on a tenant whose system layer is tenant/base.sh (a linked clone
# of the template, or a container base.sh ran in).
#
# Run AS ROOT inside the container, over SSH, by the control plane (TenantHost::provision), with
# the settings below as `export` lines ahead of this script on stdin. Idempotent: re-running it
# repairs an app rather than failing on one.
#
# What the app ends up with (on top of base.sh's PHP 8.5, nginx and FPM pool):
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
#   APP_BRANCH      the origin branch the app starts from: main for a new app, app for one
#                   carried from a host clone (its main is still the host clone's)
#   APP_HEARTBEAT   on | off — the minute crontab; off for a carried app until its cutover
set -euo pipefail
for v in APP_SLUG CORE_HOST CORE_IP DEPLOY_TOKEN BUILDER_PUBKEY APP_BASEURL APP_NAME APP_KEY APP_BRANCH APP_HEARTBEAT; do
  if [ -z "${!v:-}" ]; then echo "provision: $v is not set" >&2; exit 2; fi
done
export DEBIAN_FRONTEND=noninteractive
PHPV=8.5
APP_DIR=/srv/app
say() { echo "== $*"; }

say "core is $CORE_HOST at $CORE_IP"
grep -q " $CORE_HOST\$" /etc/hosts || echo "$CORE_IP $CORE_HOST" >> /etc/hosts

say "the app user"
id app >/dev/null 2>&1 || { echo "app.sh: no app user — run tenant/base.sh first" >&2; exit 5; }
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
  sudo -u app git clone -q -b "$APP_BRANCH" "https://$CORE_HOST/git/$APP_SLUG.git" "$APP_DIR"
  [ "$APP_BRANCH" = main ] || sudo -u app git -C "$APP_DIR" branch -m "$APP_BRANCH" main
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

say "the app's heartbeat: its own pipelines and durable objects, every minute"
# The app schedules itself (scripts/pipeline-cron.php ticks it in-process); nothing on core has
# to know its files. Written whole, so re-running this script never duplicates the line.
# APP_HEARTBEAT=off for an app carried from a host clone: a staging copy must not run the live
# app's schedule (its sends, its polls) while the clone still does — cutover switches it on.
if [ "$APP_HEARTBEAT" = on ]; then
  printf '%s\n' '* * * * * cd /srv/app && php scripts/pipeline-cron.php >> log/pipeline-cron.log 2>&1' | crontab -u app -
  crontab -l -u app | sed 's/^/crontab: /'
else
  crontab -r -u app 2>/dev/null || true
  echo "heartbeat: off (switched on at cutover)"
fi

say "the app's agent: bin/claude and its engines"
sudo -u app HOME=/home/app php -r 'require "/srv/app/vendor/autoload.php"; $r = \app\ClaudeBinary::link("/srv/app", realpath("/home/app/.local/bin/claude")); echo "bin/claude: {$r["action"]} — {$r["detail"]}\n";'
if [ ! -f conf/aibuilder.ini ] && [ -f conf/aibuilder.example.ini ]; then sudo -u app cp conf/aibuilder.example.ini conf/aibuilder.ini; fi
systemctl restart php$PHPV-fpm nginx

say "done: $(sudo -u app git -C "$APP_DIR" log --oneline -1) on runtime $(sudo -u app php -r 'require "/srv/app/vendor/autoload.php"; echo \app\InstanceUpdate::installedRuntime("/srv/app")["version"] ?? "?";')"
