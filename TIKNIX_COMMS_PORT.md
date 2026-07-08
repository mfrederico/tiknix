# Tiknix Comms — Phase 0 (generic, reusable subsystem)

> **Agent directive.** Port the threaded two-way email subsystem from
> `/var/www/html/default/dealeryes` into tiknix core at `/var/www/html/default/tiknix` as a
> **domain-agnostic capability**. No AUTOBID / RFQ / vendor concepts appear here — this is a framework
> feature any future tiknix project inherits. It gets **committed to the tiknix project** on its own,
> before any product domain is built on top. Follow tiknix `CLAUDE.md`: lowercase beans via `Bean::`,
> camelCase columns, FUSE models `Model_Beantype`, associations over manual FKs, never `R::exec` for CRUD,
> `$this->validateCSRF()` on POST, controllers auto-route `/<control>/<method>`, set `authcontrol` levels.

## What this gives every tiknix app

A polymorphic, Mailgun-backed conversation system: send a threaded email from anywhere with one fluent
call, let the recipient **reply from their own mail client**, and have that reply land back **in-app** on
the same thread — plus a role-scoped inbox UI to read/reply. Any domain object (a member, an order, an
invoice, a bid, an RFQ invite) attaches to a thread via a generic `relatedTo(type, id)` link. A single
`demo_mode` flag runs the whole thing offline (threads build in-app, no real send) for demos and tests.

## Source files to port (copy → adapt → generalize)

| From dealeryes | Into tiknix | Change on the way in |
|---|---|---|
| `services/Core/NotifyService.php` | `services/NotifyService.php` (or `lib/`) | Drop tenant params; generalize thread linkage to `relatedTo(type,id)` + `owner(memberId)` |
| `controls/Web/Webhook.php` (`mailgun`) | `controls/Webhook.php` | Inbound address becomes `reply-{token}@domain` (drop `{slug}` / WorkspaceResolver) |
| `controls/Web/Communications.php` + its views | `controls/Communications.php` + `views/communications/*` | Scope by tiknix `LEVELS` (ADMIN sees all, others see own `memberId`) |
| `services/Schema/Seeds/27_EmailThread.php` | tiknix schema seed / migration | Same three beans, tiknix naming |
| `services/Quotes/QuotePdfService.php` *(optional)* | `services/PdfService.php` | Generic HTML→PDF; keep only if a project needs PDFs |

---

## 1. Beans (three; all lowercase, camelCase columns)

**emailthread** — one conversation.
`subject, replyToken (unique, bin2hex(random_bytes(16))), relatedType, relatedId, ownerMemberId, recipientEmail, recipientName, unreadCount (int), lastDirection (in|out), status (open|closed), createdAt, updatedAt`

**notify** — one message in a thread. FUSE `Model_Notify`.
`threadId, direction (in|out), fromEmail, fromName, toEmail, toName, subject, content (html), bodyPlain, messageId, inReplyTo, referencesList, notifyType (email|system), status (queued|sent|received|failed), createdAt`

**notifyattachment** — a file on a message/thread.
`threadId, notifyId, diskPath, filename, mimeType, size`

> The own-list column is `thread_id` (not `emailthread_id`), so query messages with an explicit
> `Bean::find('notify', 'thread_id = ? ORDER BY created_at ASC', [$id])` — mirror dealeryes' comment.

FUSE models: `Model_Emailthread` (owns notify/attachment lists, `xown` for cascade delete).

---

## 2. `NotifyService` — generic fluent API (implement exactly this surface)

```php
NotifyService::create()
  ->to($email, $name = '')          // primary recipient
  ->cc($email) ->bcc($email)
  ->from($email, $name = '')        // defaults to [mail] from_email/from_name
  ->subject($subject)
  ->relatedTo($type, $id)           // polymorphic link + thread-reuse key (e.g. 'order', 42)
  ->owner($memberId)                // who monitors the thread in the inbox
  ->inReplyTo($messageId, $prevRefs = [])   // RFC-5322 threading on replies
  ->showLoginButton(false)          // template chrome toggle
  ->send($html, $attachments = []); // → ['thread' => int, 'notify' => int, 'sent' => bool]
```

Behaviour of `send()`:
1. **Find or create the thread**: reuse an existing `emailthread` for `(relatedType, relatedId)` if present, else create one and mint `replyToken`.
2. **Always** write an outbound `notify` row (content, messageId, in-reply-to, references) — the thread is complete in-app regardless of send mode.
3. Set header `Reply-To: reply-{replyToken}@{inbound_domain}` so replies route back to the webhook.
4. **If `demo_mode` OR `[mail].enabled=false`**: skip the Mailgun HTTP call, mark the notify `status='sent'`. Otherwise POST to Mailgun (with attachments, 25 MB cap) and store the returned `messageId`/`providerId`; on transport error mark `status='failed'`.
5. Bump thread `lastDirection='out'`, `updatedAt`.

Also port: `createSystemMessage($threadId, $html)` (in-app-only `notifyType='system'` row — no email), `sendEmail($to,$subj,$html,$name)` one-shot convenience, `normalizeSubject()` (strips `Re:`/`Fwd:` for thread matching).

---

## 3. Inbound — `controls/Webhook.php` (PUBLIC, level 101)

`mailgun()` — the endpoint Mailgun Routes POST inbound mail to (`/webhook/mailgun`).
1. **Verify HMAC** with `[mail].mailgun_signing_key` (timestamp + token + signature); reject stale/mismatch. If no signing key configured, log and accept **only** in dev.
2. Parse the recipient `reply-{token}@{domain}` → `{token}`. (Single-tenant: no `{slug}`. Reserve the slug slot in a comment for a future multi-tenant tiknix.)
3. `Bean::findOne('emailthread', 'reply_token = ?', [$token])`. Unknown token → 200 + log (never 500 to Mailgun).
4. Append an **inbound `notify`** (`direction='in'`, `status='received'`, capture `Message-Id`/`In-Reply-To`/`References`, sanitized HTML + plain). Save any inbound attachments as `notifyattachment`. `unreadCount++`, `lastDirection='in'`.
5. Optional: fork a new thread when the subject changed materially (port dealeryes' logic; keep behind a flag).
6. Also accept Mailgun **event webhooks** (`delivered`/`failed`/`bounced`) at the same or a sibling route: on `failed`/`bounced`, mark the matching outbound notify `status='failed'` and drop a `system` notify so the owner sees the dead address. Always return `200`.

---

## 4. Inbox — `controls/Communications.php` (owner UI, level 100)

- `index` — threads for the viewer: `ADMIN` (≤50) sees all; others see `ownerMemberId = me`. Unread badges, newest-first, search by subject/recipient.
- `thread/@id` — detail: messages oldest→newest (chat bubbles, in vs out), attachments, zero `unreadCount` on open. Reject if the viewer can't see it.
- `reply/@id` *(POST, CSRF)* — sanitize body (allowlist `<p><br><strong><b><em><i><u><ul><ol><li><a><h1>..<h4><span><div><blockquote>`, force `target=_blank rel=noopener` on links), resolve recipient from the first outbound notify, look up the last `messageId` for `inReplyTo`, optional quoted-history block, then `NotifyService::create()->relatedTo(thread.relatedType, thread.relatedId)->inReplyTo(...)->send($html, $attachments)`.

Views: `views/communications/index.php`, `views/communications/thread.php` (Bootstrap, match existing tiknix chrome).

---

## 5. Public token access (generic helper — optional but recommended)

Provide `lib/PublicLink.php` so any consumer controller can expose a **no-login** page tied to a thread/entity:
- `PublicLink::resolveThread($token): ?emailthread` — `findOne` by `replyToken`.
- The consuming project renders its own token-gated page (form, review, etc.) and can post messages into the
  thread via `NotifyService::createSystemMessage()` or an outbound send. Expiry, if needed, is the consumer's
  concern (compare against a domain deadline) — comms itself imposes no TTL.

This keeps comms generic: it owns the *channel + token*, the product owns the *page*.

---

## 6. Config (`conf/config.ini`)

```ini
[mail]
enabled          = true
driver           = "mailgun"
mailgun_domain   = "mg.example.com"
mailgun_api_key  = "key-xxx"
mailgun_signing_key = "xxx"      ; inbound HMAC verification
inbound_domain   = "mg.example.com"  ; where reply-{token}@ is Routed
from_email       = "noreply@example.com"
from_name        = "Tiknix"

[app]
demo_mode        = true          ; ON: build threads in-app, never hit Mailgun
```

Register routes in `authcontrol`: `communications::* = 100`, `webhook::mailgun = 101`, then
`php scripts/resetcache.php`. Add a Mailgun **Route** forwarding `reply-*@{inbound_domain}` → `/webhook/mailgun`.

---

## 7. Acceptance test (must pass before commit)

Drive from a scratch route or CLI tinker — **no product domain needed**:

1. **Offline thread** (`demo_mode=true`): `NotifyService::create()->to('me@x.com')->subject('Ping')->relatedTo('member',1)->send('<p>hi</p>')` → an `emailthread` + outbound `notify` exist; both show at `/communications`.
2. **System message**: `createSystemMessage($threadId,'<p>note</p>')` → appears in the thread, no email attempted.
3. **Live send** (`demo_mode=false`, Mailgun set): same call → a **real** email hits your inbox; its `Reply-To` is `reply-{token}@{inbound_domain}`.
4. **Inbound loop**: reply from your mail client → Mailgun → `/webhook/mailgun` → a new **inbound** `notify` on the *same* thread; `/communications` shows it, unread badge bumps.
5. **Reply from the hub**: `/communications/thread/{id}` → Reply → outbound notify, threaded (`In-Reply-To` set), delivered.
6. **Attachment** both directions: outbound with a PDF path arrives attached; inbound with a file creates a `notifyattachment`.
7. **Bounce**: send to a dead address → Mailgun event webhook flips the notify `status='failed'` + a system notify appears.

When 1–7 pass, **commit comms to tiknix** (`feat: threaded two-way email comms subsystem`). Every future
tiknix project — AUTOBID first — now builds on this spine instead of reinventing it.

> Next: `AUTOBID_BUILD_SPEC.md` (Phase 1+) consumes this via `relatedTo('rfqinvite', $id)` and runs as a
> **separate remote AI-builder task**, so the tiknix project isn't bifurcated further.
