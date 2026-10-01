#!/bin/bash
# tenant/seal.sh — make a container that ran tenant/base.sh safe to turn into the TEMPLATE every
# tenant is a linked clone of (lib/TenantHost.php buildTemplate). Run as root, last, just before
# the container is stopped and converted.
#
# Every clone must be its own machine: its own SSH host keys (or every tenant would present the
# same identity, and one leaked key would impersonate them all) and its own machine-id. Both are
# removed here and regenerated on the clone's first boot.
set -euo pipefail
say() { echo "== $*"; }

say "SSH host keys: regenerated on each clone's first boot"
cat > /etc/systemd/system/tiknix-ssh-hostkeys.service <<'EOF2'
[Unit]
Description=Generate this container's own SSH host keys (tiknix tenant template)
Before=ssh.service
ConditionPathExists=!/etc/ssh/ssh_host_ed25519_key

[Service]
Type=oneshot
ExecStart=/usr/bin/ssh-keygen -A

[Install]
WantedBy=multi-user.target
EOF2
systemctl enable -q tiknix-ssh-hostkeys.service
rm -f /etc/ssh/ssh_host_*

say "machine-id: regenerated on first boot"
truncate -s 0 /etc/machine-id
rm -f /var/lib/dbus/machine-id

say "no state from the build"
rm -f /root/.bash_history /home/app/.bash_history
find /var/log -type f -exec truncate -s 0 {} +
journalctl --rotate >/dev/null 2>&1 || true
journalctl --vacuum-time=1s >/dev/null 2>&1 || true
apt-get clean
rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*
say "sealed: $(df -h / | tail -1 | awk '{print $3}') used"
