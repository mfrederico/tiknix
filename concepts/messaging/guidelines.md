Messages to members — email and, for those who ask, text — with the member in control, SENT BY THE TIKNIX MESSAGING
SERVICE (messaging.tiknix.com) with this app's own Mailgun account. This plugin is the service's client: it mirrors the app's
members, kinds of message and audiences hourly, feeds the digest and the app's nudges, and draws the pages; it owns no beans.
Classes `app\concepts\messaging\{Client, Providers, Sync, Reach, Campaigns, Digest, Runner}`; controllers `Messaging` (`/messaging`,
admins; `/messaging/compose?scope=KEY` for an audience's owner), `Msgpref` (`/msgpref` "What reaches you"). Link `/msgpref` from the
member's settings and `/messaging` from the admin menu; do not draw a preferences panel of your own.

### Joining the service — once, from a terminal

Messaging is a premium Tiknix service the project's owner switches on. Then, from the app's root (per host, `TIKNIX_HOST=…`):
`php scripts/clitool.php --connection-share=mailgun --with=messaging --site=<host>` — the app's Mailgun connection becomes a
sealed copy the service sends with; the site is made on first sight. The owner pastes the site's webhook address (on `/messaging`)
into Mailgun's webhooks and the reply route; the signing key is set on Connections → Mailgun and travels by itself. The hourly
pipeline (`pipelines/messaging-hourly.json`) does the mirroring; a page that needs one member in the service now mirrors them itself.

### Say what the app has — once, in lib/app.php

The service knows no groups, no digest content and no phone numbers: the app hands them in, as class names with STATIC methods.
Every key is optional; a class that does not exist or lacks a method stops the boot, naming it. Call `Concepts::boot` first.

```php
if (\app\Concepts::has('messaging')) {
    \app\Concepts::boot(\app\Paths::root());
    \app\concepts\messaging\Providers::register([
        'audiences'  => \app\MessagingAudiences::class,   // all(), scopes($forMember), members($key), label($key), mayMessage($m, $key), category(); optional allowsFor, abouts, aboutDraft, reminder, vars, link
        'digest'     => \app\MessagingDigest::class,      // content($memberId, $since): ?array {headline, html, text} — null = nothing new, no digest
        'phone'      => \app\MessagingPhone::class,       // verified($memberId): string (E.164 or ''); prove/pending/confirm/cancel let a member prove it
        'categories' => [['news', 'News', 'What is new in your groups', true, true, 'email,text']],   // the app's own kinds; transactional and reminders exist already
        'system'     => [['kind' => 'digest', 'category' => 'news', 'subject' => '{headline}', 'body_email' => '<p>Hi {first_name|there}</p>{content_html}', 'body_text' => "Hi {first_name|there}\n{content_text}", 'cadence' => 'every_n_days', 'cadence_days' => 7],
                         ['kind' => 'trigger', 'trigger_code' => 'pipeline', 'code' => 'no_friends', 'category' => 'reminders', 'subject' => '…', 'body_email' => '…']],   // Custom: a pipeline sends it
    ]);
}
```

`Providers` documents every method (`triggers` = the app's own nudge rules in PHP, `qualifies($m, $days)`; a pipeline is the better
way). A `system` message is MADE ONCE in the service, paused, when the tenant has none of its kind — the admin switches it on; never
overwritten. Register nothing in a seed: seeds run where the service may not be reachable.

### Audiences are derived, never typed

There is no recipient list, here or in the service. A campaign names an audience; the service asks its mirror who is in it,
removes whoever switched that kind off (their choice or the kind's default, the audience's own switch, suppression) and whoever
its ledger shows already has it. Never keep a list of addresses. `Reach::allows($memberId, $category, 'email'|'text', $scopeKey)`
asks the service the one question before any message of your own; `transactional` is always allowed. An audience's own switch is
the SERVICE's once a member is in it (the hosted page and `/msgpref` flip it); the app's `allowsFor()` only seeds it.

### A Custom trigger is a pipeline

A message whose "when" is **Custom** has a `code` (no_friends) and is never sent by the service on its own: a pipeline is its
trigger and its condition, and the pipeline's `messaging` step sends it to whom the run names — once per person, only to those
with that kind on, with the run's own `{vars}` (a copy a `{name}` cannot fill fails alone). The pipeline's trigger is whatever
pipelines have: `cron` + a `db_query` for a condition in the app's data; a `connector` event (a Stripe purchase); or the app's
own moment — `\app\AppEvents::fire('ticket.validated', ['member_id' => …])` in the code, `"trigger": {"event": "ticket.validated"}`
in the pipeline. Declare such a message in `system` with `'trigger_code' => 'pipeline', 'code' => 'no_friends'`; no `triggers` class.

### The owner's page, the cap and the digest

An audience's owner writes at `/messaging/compose?scope=community:5` (link it from the audience's own page); the provider's
`mayMessage()` decides who may, anyone else gets 403. Every check is the service's (a draft is made, asked about, deleted). An
owner may send `weekly_cap` (default 2) messages per audience per rolling 7 days. The digest and the app's own nudges are
APP-FED: each hour `Runner::hourly` asks the service who is due and posts each person's copy (the digest provider's `content`,
a trigger's `qualifies` + `content`); `inactive_days` and `joined_days` the service evaluates itself. `/messaging/calendar`
shows when messages go out and what came of them; an owner's `?scope=` shows their audience's.

### Settings (on /messaging); texts

Per site: the from-name and the reply domain (replies come back to `reply-{token}@` it through a Mailgun route to the site's
webhook address; empty = untracked). `weekly_cap`, `owners_may_text`, `text_link_base`. v1's webhook key and `Webhook::intercept()` are gone.
A text needs the phone provider, the member's opt-in (off by default, recorded) and a proved phone; one part only, ending with
"Reply STOP or:" and the stop link. Texting through the service is not switched on yet: texts are recorded as not sent, loudly.

### Credit an action; message events; the choices

`Campaigns::acted($memberId, 'rsvp'|'post', $meta)` where members act credits the campaign whose link they followed in the last
48 hours (a service that does not answer is an ERROR in the log, never a broken page). What happens to a message is the app's own
event: the service posts it to `/msghook/event` (signed with the site's hook key) and a pipeline starts on `"trigger": {"event":
"message.replied"}` — delivered | opened | clicked | replied | unsubscribed | bounced | complained, with `{context.member_id}`,
`{context.campaign_code}`, `{context.subject}`, `{context.meta.*}`. `Campaigns::recipient($code, $memberId)` (a `messaging` step's
`check`) says what one member did with a message. `Reach::prefsUrl($memberId)` is the signed hosted page where a member chooses
what reaches them. Old `/msgpref/unsubscribe?t=` links (v1) still work, through the service.
