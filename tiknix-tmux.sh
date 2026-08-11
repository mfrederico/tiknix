#!/usr/bin/env bash
#
# tiknix-tmux.sh — attach to the long-lived Claude session, or start it.
#
# Run it with no arguments. Attaches to the tmux session named "tiknix" if it is
# there, otherwise creates it and starts Claude inside.
#
# TIKNIX_MEMBER_LEVEL is what scripts/hooks/security-sandbox.php checks against the
# securitycontrol rows. Unset, the hook defaults to 100 (MEMBER), which is right for a
# build agent and wrong for you: lib/, conf/, .claude/ and scripts/hooks are `protect`
# rules at 50, so at 100 your own session cannot edit the framework.

set -euo pipefail

SESSION="tiknix"

# 50 = ADMIN. Enough for the protect rules (lib/, conf/, .claude/, scripts/hooks,
# CLAUDE.md) and for the /home/ubuntu/capricorn allow, which is also level 50.
#
# NOT 1. Level 1 additionally clears the `block` rules — /etc, /root, /boot, /proc,
# /sys, /var/log, ~/.ssh, ~/.aws — which are blocked because nothing in this project
# has business writing them. Override for a one-off if you genuinely need it:
#
#   TIKNIX_MEMBER_LEVEL=1 ./tiknix-tmux.sh
#
LEVEL="${TIKNIX_MEMBER_LEVEL:-50}"

# Already inside tmux: attaching would nest a session inside itself, and tmux refuses.
# Run Claude here instead — the surrounding session already provides the persistence
# this script exists to give.
if [[ -n "${TMUX:-}" ]]; then
    echo "Already inside tmux — starting Claude here (level ${LEVEL})."
    exec env TIKNIX_MEMBER_LEVEL="$LEVEL" claude --resume
fi

# `=tiknix`, not `tiknix`. Without the `=` tmux matches by PREFIX, and every build
# session is named tiknix-<slug>-… — so a bare -t tiknix (or `tmux ls | grep tiknix`)
# matches a plan orchestrator and attaches you to an agent's console instead.
if tmux has-session -t "=${SESSION}" 2>/dev/null; then
    echo "Resuming Claude — attaching to '${SESSION}'."
    exec tmux attach-session -t "=${SESSION}"
fi

echo "No '${SESSION}' session — starting Claude at level ${LEVEL}."
exec tmux new-session -s "$SESSION" \
    env TIKNIX_MEMBER_LEVEL="$LEVEL" claude --resume
