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
