# E-signature + CRM — two catalog plugins, one build

Harvested from myctobot's `controls/Agreement.php` (a sales-rep agreement signed on a canvas,
downloadable as a PDF) and `controls/Crm.php` + `Mcpcrm.php` (contacts, a cold→closing pipeline,
notes, touches, activity, sharing, merge, MCP tools). Apps are open source, so extraction is
allowed; the harvest workflow (pin → author in concept-dev → lint → publish → install → prove)
applies. Owner's call 2026-10-03: plan both, build `esign` first.

Why two plugins: signing is useful far beyond a CRM (quotes, waivers, contracts, onboarding),
and the CRM's "sign the agreement before you may use it" is one use of it, as a setting. A
single `crm` plugin with signing inside would make every other document wait for a CRM.

## Phase 1 — `esign` (kind: capability + pages)

**What it is.** Any document — HTML a view produced, or a stored PDF — gets a signing link. The
signer sees the document, types their name, draws a signature (signature_pad), ticks consent,
and signs. The plugin stores the signature, the signer's name/email/IP/UA, the time, and the
FROZEN document (its HTML and SHA-256) so what was signed can be shown later exactly as it was.
The signed PDF (document + a certificate page) is rendered by the `pdf` plugin.

**Beans (owned).**
- `signrequest` — `title`, `document_html`, `document_sha256`, `signer_name`, `signer_email`,
  `token` (64 hex: THE credential for the public pages), `status` (draft|sent|viewed|signed|
  declined|void), `expires_at`, `signed_at`, `signed_name` (typed), `signature_png` (data URL),
  `signer_ip`, `signer_ua`, `decline_reason`, `pdf_path` (under data/esign/), `member_id`
  (who asked), `related_type` + `related_ref` (the host's record, e.g. `crmcontact` 12; `_ref`
  because the host's row may be hard-deleted), `created_at`.
- `signevent` — the audit trail: `signrequest_id`, `event` (created|sent|viewed|signed|declined|
  voided|downloaded), `ip`, `ua`, `note`, `created_at`.

**API (`app\concepts\esign\Esign`).**
```php
$req = Esign::request($title, $html, ['name' => $n, 'email' => $e], ['member_id' => …, 'expires_days' => 14, 'related_type' => 'crmcontact', 'related_ref' => 12]);
Esign::send($req);          // emails the link (Mailer); no mail connection = ERROR logged, false, link still usable
Esign::url($req);           // https://…/esign/view/<token>
Esign::sign($req, $typedName, $pngDataUrl, $ip, $ua);   // status signed, pdf rendered + stored, event
Esign::decline($req, $reason, $ip, $ua);
Esign::void($req, $memberId);
Esign::pdf($req): string;   // bytes of the signed PDF (renders once, keeps the file)
Esign::isSigned(string $relatedType, int $relatedRef, ?string $title = null): bool;   // the CRM gate uses this
```
Document HTML is frozen at request time: `sign()` refuses if `sha256(document_html)` no longer
matches `document_sha256` (a tampered row is a fault, not a signature).

**Pages (`Esign` controller).** PUBLIC by token — unknown/expired token is a plain 404/410:
`view/<token>` (document + pad), `sign` (POST), `decline` (POST), `pdf/<token>` (signed only).
MEMBER: `index` (my requests), `create` (title, signer, document body — paste HTML or write
Markdown), `send`, `void`, `preview`. Every state change is a POST with CSRF (member pages) or
the token (public pages). Seeded in `seeds/02_EsignPermissions.php`.

**Rules.** Never widen the token's power: it signs ONE document for ONE signer. A signed request
is immutable (no edit, no re-send; void and make a new one). The PDF names the signer, the time
(UTC and the app's timezone), the IP, the request id and the document hash on its certificate
page. Expired = 410 with the requester named. Nothing fails quietly: a missing `pdf` plugin or
Chrome refuses at `verify`; a failed email is an ERROR log line and `status` stays `draft`.

**Requires.** concepts: `pdf`; lib: `Bean`, `Mailer`, `PermissionCache`. Signature pad from the
runtime's assets if present, else the pinned `signature_pad@4.2.0` from jsdelivr.

**Tests.** Real in-memory SQLite through the seeds (TicketsTestCase's shape); real PDF renders
(skipped without Chrome, as the pdf plugin's are); a tampered-document refusal; expiry; the
Mailer stand-in; the gate.

**Deliverables.** `concept-dev/concepts/esign/{concept.json,README.md,guidelines.md,lib,controls,
models,views,seeds,tests}`; lint + publish to the catalog; install + enable on catpoobox and sign
one document end to end in a browser (public link, PDF download).

## Phase 2 — `crm` (kind: feature)

**Port of myctobot's CRM** to the runtime's conventions, as the app's own pages under `/crm`:
- Beans: `crmcontact` (name, company, title, email, phone, location, `status_category`
  prospect|customer, `pipeline_stage` cold|warm|hot|closing, `account_type`, tags, source,
  `estimated_value`, enrichment JSON, `last_touch_at`, `merge_parent_id`, owner `member_id`),
  `crmnote`, `crmtouch` (type call|email|meeting|linkedin|other, outcome, duration, date),
  `crmactivity` (the per-member log), `crmcontact_member` (shared-with). FUSE models with
  `ownCrmnoteList`/`ownCrmtouchList` and cascade delete.
- Pages: dashboard (pipeline counts, recent touches), contacts (search/filter/stage), view (notes,
  touches, timeline, share), create/edit, pipeline board (move stage), customers, team activity
  (ADMIN), settings (stages' labels, agreement gate on/off), merge, convert to customer.
- Intake: `Model_Lead::capture` stays the one way a visitor's form writes a lead; the CRM imports
  leads (a button and a pipeline) rather than a second capture path. `apilead` becomes a pipeline
  source, not a bespoke route.
- MCP tools (`mcptools/`): `crm_contacts`, `crm_contact`, `crm_add_note`, `crm_add_touch`,
  `crm_move_stage` — the agent's face, levels as the pages.
- Visibility: owner or shared-with; ADMIN sees all. Same as myctobot; no new rule.
- The agreement gate: `concept.crm.agreement_title` (setting, default empty = no gate). When set,
  a member opens the CRM only once `Esign::isSigned('member', $memberId, $title)`; the request is
  made from `views/crm/agreement.html` (the app's own text). Needs `esign` only when the gate is on
  (`requires.concepts` lists it; the gate is a setting).
- What is NOT ported: LinkedIn refresh (a scraper), the `crmemployee`/WMS customer link, the
  myctobot-specific layout.

**Tests.** Visibility (owner/shared/admin), stage moves write activity, merge keeps notes and
touches, lead import is idempotent, the gate.

## Order of work

1. `esign`: author, test, lint, publish, install on catpoobox, prove in a browser.
2. `crm`: author with `esign` as the optional gate, publish, install on catpoobox with sample
   contacts, prove the pipeline board and an MCP tool call.
3. Roll both into the catalog's README index; note them in agent guidance via their
   `guidelines.md` (generated into CLAUDE.md on enable).
