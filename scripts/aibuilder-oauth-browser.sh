#!/bin/sh
# aibuilder-oauth-browser.sh — the "fake browser" handed to Claude Code via $BROWSER
# inside the jail.
#
# When Claude Code wants to "open a browser" for OAuth sign-in it execs whatever
# $BROWSER points at, with the target URL as an argument. There is no GUI browser
# in the bubblewrap jail, so instead of opening anything we DROP the URL into this
# instance's .aibuilder/oauth-request.json. The tiknix AI Builder web UI polls for
# that file and surfaces a clickable "Sign in to Claude" modal to the operator, who
# authenticates in their OWN browser, copies the authorization code, and pastes it
# back into the terminal — which flows straight into Claude's stdin over the PTY
# websocket that is already open. Creds then persist per-instance under
# .aibuilder/state/claude, so this is a one-time step per instance.
#
# WHY A FILE, NOT A SOCKET: the jail is the security boundary. Writing a file in the
# bind-mounted instance dir is the same out-of-jail channel the agent already uses
# for .aibuilder/plan.json — no network egress, no websocket client in the jail.
#
# WHY IT SELF-LOCATES: this template is copied verbatim into each instance's
# .aibuilder/ and finds its own instance from its path, so one file serves every
# instance with nothing baked in.
#
# CONTRACT: exit 0 immediately and always, so Claude proceeds to its "paste the
# code" prompt regardless of what we did with the URL.

set -eu

# --- find the URL argument --------------------------------------------------
# $BROWSER is normally invoked as `$BROWSER <url>`, but some openers append the URL
# after other tokens, so fall back to the first http(s) argument we see.
url="${1:-}"
case "$url" in
  http://*|https://*) : ;;
  *)
    url=""
    for a in "$@"; do
      case "$a" in http://*|https://*) url="$a"; break ;; esac
    done
    ;;
esac

# --- self-locate: .aibuilder/ is this script's dir; instance dir is its parent ---
aib_dir="$(cd "$(dirname "$0")" && pwd)"
instance_dir="$(dirname "$aib_dir")"
instance="$(basename "$instance_dir")"

# minimal JSON string escaper (URLs are query-encoded, but be safe about \ and ")
esc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

case "$url" in
  *oauth/authorize*|*/oauth/*)
    # The sign-in URL — trap it for the web UI.
    ts="$(date +%s 2>/dev/null || echo 0)"
    tmp="$aib_dir/oauth-request.json.tmp.$$"
    printf '{"url":"%s","instance":"%s","ts":%s}\n' \
      "$(esc "$url")" "$(esc "$instance")" "$ts" > "$tmp"
    mv -f "$tmp" "$aib_dir/oauth-request.json"
    echo ""
    echo "  [tiknix] Claude wants you to sign in."
    echo "  [tiknix] Open the AI Builder — a 'Sign in to Claude' button will appear."
    echo "  [tiknix] After approving, paste the code back here."
    echo ""
    ;;
  http://*|https://*)
    # Not the sign-in flow (e.g. a docs link) — don't swallow it; print so it stays
    # visible/clickable in the terminal.
    echo "Open in your browser: $url"
    ;;
  *)
    : # nothing openable was passed
    ;;
esac

exit 0
