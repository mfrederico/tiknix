# FUTURESCOPE: Communications

> Status: design / futurescope. Not yet built. Extends the connector + feature-flag
> + MCP-broker architecture already established for Connectors (Shopify/Stripe) and
> Ecommerce. This document is the plan of record for turning "Communications" into a
> pluggable, multi-vector messaging surface.

## 1. Goal

Turn Communications from a single in-app pane into a **pluggable, multi-vector
messaging surface**. A tiknixer wires up their *own* channels (Mailgun email first,
then Slack / Telegram / Discord / Google Chat) per instance and reaches the
**members of that instance** — and only them — through **one unified thread per
person**, no matter which vector a given message rode in on.

Two hard constraints shape everything:

1. **Anti-spam containment.** A message sent from a `*.tiknix.com` instance may only
   be delivered to a **member/contact of that same instance**. The platform must
   never become an open relay a customer can spam through.
2. **Opt-in per member.** Sending communications is a **per-member feature flag**
   (same mechanism as `ecommerce`), so an instance owner grants it deliberately —
   not every member can broadcast.

## 2. Communications as a connector category

Reuse the existing `ConnectorRegistry` + encrypted `connections` custody model. Add a
new **category = "Communication"** (alongside Deploy / Payments / Stores) so these
connectors render as cards on `/connections` and are discoverable by searching
"communication".

Each communication connector implements the existing `ConnectorInterface` plus a
small delivery contract:

```php
interface CommsConnector extends ConnectorInterface {
    public function vector(): string;   // 'email' | 'slack' | 'discord' | 'telegram' | 'gchat'
    // Control-plane only: receives the already-decrypted token, returns DATA.
    public function send(object $conn, string $token, CommAddress $to, CommMessage $msg): array;
    // Normalize a verified inbound webhook payload into a canonical message, or null.
    public function verifyInbound(array $params, string $secret): ?InboundMessage;
}
```

- Runs **only on the control plane**; the instance reaches it through the **MCP
  broker** (`comms:send`, `comms:list_threads`, …) exactly like `stripe:*` today.
  The provider token (Mailgun key, Slack bot token, Discord webhook secret) is
  custodied **encrypted in `connections`** and is never shipped to the instance.
- `meta()` carries `category => 'Communication'`, an `icon`/`color`, and `features`
  badges, so the card renders with zero extra wiring (same as Stripe/Shopify).

### First connector: Mailgun

`auth_type: 'api_key'` (paste flow, mirroring Stripe): the tiknixer pastes their
**Mailgun API key** + **sending domain**; validated against Mailgun
`GET /v3/domains/<domain>` before anything persists. `vector() = 'email'`. Because
it's *their* domain + key, deliverability and sender reputation are theirs — and a
deployed (GitHub) instance still never holds the key (broker only). Inbound uses a
Mailgun route webhook (`webhook::mailgun` already exists in authcontrol at 101),
verified by Mailgun HMAC before the sender is trusted.

## 3. Addressing: the `vector://identifier` URI scheme

When you hit **Send** from the Communications pane, the recipient is expressed as a
URI so the system knows *how* to reach them:

```
email://mfrederico@gmail.com
slack://bobjones            # resolves to a Slack user id via the connected workspace
discord://all               # a fan-out target within the connected guild
telegram://@handle
gchat://space/AAAA
```

A **`CommAddressResolver`** parses the URI → `(vector, identifier)` → finds the
connected connector for that vector on the instance → hands off to
`connector->send()`. `://all` and `://space/…` are **fan-out** targets that expand to
the instance's member set for that vector (still subject to §4).

## 4. The containment guardrail (non-negotiable)

Before any dispatch, the send path resolves the URI identifier to an **instance
member/contact record** and rejects if there is no match:

- Every recipient must map to a row in the instance's membership/contacts (a
  `commaddress` bound to a `contact` that belongs to `instance_id`).
- The broker (`comms:send`) enforces
  `connection.instance_id == broker_key.instance_id` **and**
  `resolved_recipient.instance_id == connection.instance_id`.
- Fan-out targets (`discord://all`) expand **only** to that instance's members —
  never an arbitrary channel population.
- Per-instance + per-member **rate limits**, and an **audit row per send**.
  Unknown/unbound addresses are dropped, logged, and surfaced as a delivery error —
  never silently attempted.

This is the same "instance ↔ connection binding" defense already proven in
`Mcp::brokerToolCall` for the store connectors.

## 5. Per-member opt-in

Add to `app\Feature::CATALOG` (identical pattern to `ecommerce`):

```php
'communications' => [
    'label'     => 'Communications',
    'blurb'     => 'Send and receive messages to instance members across email, Slack, Discord, and more.',
    'min_level' => 50, // ADMIN/ROOT may grant
],
```

Toggled on the **Edit Member** page, gates the Communications controller + every
`send` action (`requireFeature`-style guard), and shows/hides the nav item — exactly
as `ecommerce` works today. **Recommended two-tier grant:** `communications` (may
*view* the pane) vs `communications.send` (may *dispatch* outbound), so a level-100
member can be granted read without broadcast.

## 6. Unified threading (the hard part)

**Requirement:** a member may have several addresses (email + Slack + Discord), but
their conversation is **one thread**, regardless of vector.

### Data model

- **`commcontact`** — a participant in an instance: `instance_id`, optional
  `member_id`, `display_name`. One row per human.
- **`commaddress`** — `contact_id`, `vector`, `address`, `is_primary`. *Many per
  contact* (email://…, slack://…). This is the join that collapses vectors into one
  identity.
- **`commthread`** — the singular thread: `instance_id`, `contact_id`, `subject`,
  **`thread_key`** (stable hash), `last_message_at`, `status`. **One open thread per
  (instance, contact, topic).**
- **`commmessage`** — `thread_id`, `vector`, `direction` (`in`/`out`), `address`,
  `body`, `external_id`, `raw_ref`, `created_at`.

(All lowercase bean types per RedBean rules; FUSE models `Model_Commcontact`,
`Model_Commthread` enable `ownCommaddressList` / `xownCommmessageList` cascade.)

### Thread resolution (the hash / subject)

- `thread_key = sha256(instance_id : contact_id : normalized_topic)`.
- `normalized_topic` derives from the subject for email (stripped of `Re:`/`Fwd:`,
  whitespace-folded, lowercased); for chat vectors it's the channel/space id or a
  synthetic `"default"` topic.
- **Inbound** on any vector: verify webhook → map sender `address` → `commaddress` →
  `contact` → find/open the thread whose `thread_key` matches the topic (or the
  contact's default thread) → append a `commmessage` tagged with its `vector`.
  Vector-native correlators (email `References`/`Message-ID`, Slack `thread_ts`,
  Discord message id) are stored in `raw_ref` and consulted **first**; `thread_key`
  is the fallback that guarantees convergence when no native correlator exists.
- **Outbound**: the pane shows the unified thread; replying lets you choose a vector
  (default = the contact's `is_primary`, or the vector the last inbound arrived on —
  "reply on the channel they used"). The chosen connector sends; the message is
  appended to the same thread.

**Net effect:** Bob emails, then DMs on Slack — both land in **Bob's one thread**,
each bubble labeled with its vector; you reply once and pick how it goes out.

## 7. Send / receive flow (summary)

```
Compose (pane)  →  pick contact + vector  →  vector://identifier
   → guardrail: recipient ∈ instance?  (broker enforces binding)
   → comms:send (broker)  → connector.send() with decrypted token (control-plane)
   → append outbound commmessage to the contact's thread

Inbound webhook (per vector, on a *.tiknix.com endpoint)
   → connector.verifyInbound()  (HMAC / signature)
   → resolve address → contact → thread_key
   → append inbound commmessage → notify pane
```

## 8. Security & custody notes

- Provider tokens: encrypted in `connections`, control-plane only, reached via
  broker — the same Tier-3 model as Stripe. Deployed instances hold only the broker
  key plus any non-secret/publishable ids.
- Inbound endpoints live on the control plane (`*.tiknix.com`) and **must** verify
  provider signatures before trusting sender identity (Mailgun HMAC, Slack signing
  secret, Discord ed25519, Telegram secret token).
- Every outbound send is audited (who / instance / vector / recipient / thread) for
  abuse review; enforce per-instance and per-member rate limits.

## 9. Suggested phasing

1. **Comms category + Mailgun connector** — api_key paste, `vector('email')`,
   `send`, Mailgun-HMAC inbound — surfaced on `/connections`.
2. **`communications` feature flag** + Edit-Member toggle + nav gate + controller
   guard (mirror `ecommerce`).
3. **Threading core** — `commcontact` / `commaddress` / `commthread` / `commmessage`
   + the `thread_key` resolver; unify the existing Communications pane onto it
   (email only to start).
4. **Containment guardrail** in `comms:send` — recipient-∈-instance binding + audit
   + rate limits.
5. **URI addressing + resolver** — `vector://identifier`, fan-out targets.
6. **Additional vectors** — Slack → Discord → Telegram → Google Chat, each a drop-in
   `CommsConnector` with its own inbound verifier; threading converges automatically
   via `commaddress` + `thread_key`.

## 10. Open questions

- **Fan-out semantics** (`discord://all`): cap size, dedupe across a contact's
  vectors, and confirm before large sends.
- **Two-tier grant**: is "may send" distinct from "may view"? (Recommend yes.)
- **Contact ↔ member identity**: do non-member contacts get threads, or are threads
  strictly instance-member-only? (The guardrail currently assumes instance-bound
  identities only.)
- **Topic granularity**: one thread per contact, or per (contact, subject)? Start
  with per-contact + subject-hash fallback; revisit if threads get noisy.

---

**In short:** Communications becomes a **connector category** (Mailgun first) under
the same broker/custody model as Stripe; a **per-member feature flag** gates it; a
**`vector://identifier` URI** addresses recipients; a **hard instance-membership
guardrail** blocks spam; and a **`commaddress` → `commthread` (`thread_key`)** model
collapses every vector into one thread per person.
