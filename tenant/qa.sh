#!/usr/bin/env bash
# qa.sh — make a container the QA browser host (lib/QaHost.php runs it as root, over SSH).
#
# What runs here is a headless browser showing pages written by project owners. So:
#   - it runs as `app`, an ordinary user (the browser keeps its own sandbox too)
#   - the container may not open a connection to any private address: not another project's
#     container, not the control plane, not the gateway. It reaches projects the way a visitor
#     does — at their public address — and answers the control plane's SSH. That is a firewall
#     rule, not a promise of the test runner.
# Idempotent: run it again to bring a host up to date.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
say() { echo "== $*"; }

NODE_MAJOR=22
PLAYWRIGHT=1.62.0
MCP=0.0.83          # the version the builder's audit pins (lib/AuditRunner.php)

say "the app user answers the control plane's key"
install -d -m 0700 -o app -g app /home/app/.ssh
install -m 0600 -o app -g app /root/.ssh/authorized_keys /home/app/.ssh/authorized_keys

say "node ${NODE_MAJOR}"
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  install -d -m 0755 /etc/apt/keyrings
  curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
  echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
  apt-get update -qq >/dev/null
  apt-get install -y -qq nodejs >/dev/null
fi
echo "node $(node --version)"

say "the runner's home and Playwright ${PLAYWRIGHT} with Chromium"
install -d -o app -g app /srv/qa /srv/qa/runner /srv/qa/browsers /srv/qa/jobs
if [ "$(cat /srv/qa/.playwright 2>/dev/null)" != "${PLAYWRIGHT}" ]; then
  cd /srv/qa/runner
  sudo -u app env HOME=/home/app npm init -y >/dev/null 2>&1 || true
  sudo -u app env HOME=/home/app npm install --no-audit --no-fund "playwright@${PLAYWRIGHT}" >/dev/null
  # the system libraries Chromium needs (root), then the browser itself (app)
  npx --yes "playwright@${PLAYWRIGHT}" install-deps chromium >/dev/null
  sudo -u app env HOME=/home/app PLAYWRIGHT_BROWSERS_PATH=/srv/qa/browsers npx playwright install chromium >/dev/null
  echo "${PLAYWRIGHT}" > /srv/qa/.playwright
fi
echo "playwright $(cat /srv/qa/.playwright), browsers: $(ls /srv/qa/browsers | tr '\n' ' ')"

say "Playwright MCP ${MCP} — the browser as tools, for the authoring agent"
install -d -o app -g app /srv/qa/mcp
if [ "$(cat /srv/qa/.mcp 2>/dev/null)" != "${MCP}" ]; then
  cd /srv/qa/mcp
  sudo -u app env HOME=/home/app npm init -y >/dev/null 2>&1 || true
  sudo -u app env HOME=/home/app npm install --no-audit --no-fund "@playwright/mcp@${MCP}" >/dev/null
  sudo -u app env HOME=/home/app PLAYWRIGHT_BROWSERS_PATH=/srv/qa/browsers npx playwright install chromium >/dev/null
  echo "${MCP}" > /srv/qa/.mcp
fi
echo "playwright mcp $(cat /srv/qa/.mcp)"

say "no way out to a private address"
apt-get install -y -qq iptables >/dev/null
iptables -F OUTPUT
iptables -A OUTPUT -o lo -j ACCEPT
iptables -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
for net in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16 100.64.0.0/10; do
  iptables -A OUTPUT -d "$net" -j REJECT
done
ip6tables -F OUTPUT
ip6tables -A OUTPUT -o lo -j ACCEPT
ip6tables -A OUTPUT -j REJECT
# kept across restarts: a unit that puts the rules back before the network is up
iptables-save > /etc/qa-firewall.v4
ip6tables-save > /etc/qa-firewall.v6
cat > /etc/systemd/system/qa-firewall.service <<'UNIT'
[Unit]
Description=QA browser host: no connections out to private addresses
Before=network-pre.target
Wants=network-pre.target
[Service]
Type=oneshot
ExecStart=/bin/sh -c '/usr/sbin/iptables-restore < /etc/qa-firewall.v4 && /usr/sbin/ip6tables-restore < /etc/qa-firewall.v6'
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable qa-firewall.service >/dev/null 2>&1
echo "firewall: $(iptables -S OUTPUT | grep -c REJECT) private ranges refused, ipv6 closed"

say "nothing of an app lives here"
rm -rf /srv/app
echo "disk: $(df -h / | awk 'NR==2 {print $4 " free of " $2}'), memory: $(free -m | awk 'NR==2 {print $2}') MB"
