# TODO

Deferred work: decided worth doing, not yet needed.

## Prompt-injection screening before an agent sees untrusted text

Deferred 2026-10-23: no outside users yet. Pick it up before apps with public visitors run with auto-fixes on.

**Why:** with `auto_triage` on, text an app's visitors wrote can reach a build agent with no person in between: the QA browser reads it on a live page, it becomes a found problem, and the problem launches a fix. Pushed commits from GitHub sync are pulled, built and checked automatically too.

**Where:**
- **Firehose:** `createTriageTask` → `launchFix`.
- **GitHub sync:** `GitSync::pull`.
- **AuditRunner check findings**, as they are filed.

**Plan, in order of what actually protects:**
1. **Mark untrusted text where it comes from.** A problem built from page content, a visitor's input or a pushed commit is marked untrusted, and the mark follows it into the brief.
2. **Normalize before any check:**
   - Unicode NFKC and folding of look-alike characters
   - strip zero-width and invisible characters
   - decode base64, URL-encoding and HTML entities
   - flag text that switches language or script mid-message
3. **Deterministic tripwires:** known injection phrases and encoded payloads. Score the text; never block outright.
4. **Classifier score as a second tripwire:** a small injection classifier returns a number, never text. Candidates:
   - Meta Prompt Guard (~86M parameters)
   - ProtectAI `deberta-v3-base-prompt-injection-v2`
   - embedding similarity against a corpus of known attacks

   Tune its threshold on the existing firehose problems, which are normal traffic. Run it in its own small container (the "Embeddings" agent type), never on the tiknix.com box.
5. **What a score does:** anything that trips step 3 or 4 waits for the owner before the auto-fix runs. A fix built from untrusted text runs with a reduced brief and reduced powers, whatever it scored:
   - sandbox only
   - no secrets
   - no push
   - no network beyond the sandbox

**Not the defence on its own:** word-frequency (Bayesian) filtering and small classifiers catch copied attacks, not determined ones. An attacker who can test against them gets through. The protection is steps 1 and 5. The filters only decide what a person looks at.

## Attack surface found 2026-10-25 (verified from inside a tenant container)

None critical while every owner is the platform's own people; do 4 now, 1 and 3 before the next outside owner.

1. **A build task is the whole app.** The agent runs `--permission-mode bypassPermissions` as `app`, with no jail; the only hook shipped to an app is the PHP validator (core's `security-sandbox.php` never reaches a container). A task can write `/srv/app` directly (skipping merge review), read `database/*.db` and `secure/` (`connections.key` opens every connection and `claude-oauth.enc`), install a crontab, or add a `pipelines/*.json` that runs every minute. Fix: a write guard shipped to apps (worktree only; `secure/`, `pipelines/`, crontab, `.mcp.json` refused), or tasks under a separate user that cannot read `secure/`.
2. **The owner's outside accounts go with a compromised task**: S3, Stripe, Shopify, QuickBooks, Quo SMS, Mailgun, the Claude OAuth token, GitHub sync (both directions). Broker-key endpoints on core are bound to the instance and owner; `publish` is gated at the owner's level.
3. **Flat tenant network.** From one container: every other container's 80/22/3990, the node's 8006 and 22, the QA host's 22. Proxmox firewall off at cluster, node and CT level. All credential-gated today. Fix: CT firewall rules — 80 only from core and the QA host, 22/3990 only from core, no tenant-to-tenant, no tenant-to-node management.
4. **BUG NOW: every visitor is 173.231.12.84 to an app.** Capricorn sends X-Forwarded-For/X-Real-IP; the app's nginx (`tenant/base.sh`) never applies them; `RateLimiter` keys on `REMOTE_ADDR`, `LeadGate` on `request->ip`. Five failed sign-ins by anyone lock sign-in for all of an app's visitors for five minutes. Fix: `set_real_ip_from <capricorn>; real_ip_header X-Real-IP;` in base.sh + a BASE_RECIPES entry (trusting only capricorn also stops spoofing from other tenants).
5. **Shared Mailgun** (inresonance only): Tiknix's notify.tiknix.com key sits in the app's store, sealed with the app's own key, so its agent can send as Tiknix. Check whether it is a domain sending key or the account key; prefer a per-app sending key or a relay through core with per-app limits.

Sound: `PlatformAgent` (QA author: no tools, bare, cost-capped), per-instance broker keys, per-app HMAC terminal bridge, authenticated git, loopback valkey, keyed MCP `tools/call`, the QA host's egress firewall.
