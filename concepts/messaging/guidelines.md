Messages to members — email and, for those who ask, text — with the member in control, SENT BY THE TIKNIX MESSAGING
SERVICE (messaging.tiknix.com) with this app's own Mailgun account. This plugin is the service's client: it mirrors the
app's members, kinds of message and audiences into the service every hour, feeds the digest and the app's own nudges with
what they say, and draws the pages. It owns no beans: who may be reached, what was sent and what came of it live in the
service, reached through `app\concepts\messaging\Client` with the app's broker key (conf/broker.ini). Classes
`app\concepts\messaging\{Client, Providers, Sync, Reach, Campaigns, Digest, Runner}`; controllers `Messaging` (`/messaging`,
admins; `/messaging/compose?scope=KEY` for an audience's owner), `Msgpref` (`/msgpref` "What reaches you"). Link to
`/msgpref` from the member's settings and to `/messaging` from the admin menu; do not draw a preferences panel of your own.
### Joining the service — once, from a terminal

Messaging is a premium Tiknix service the project's owner switches on. Then, from the app's root:
`php scripts/clitool.php --connection-share=mailgun --with=messaging --site=<host>` — the app's Mailgun connection (key,
domain, from-address, webhook signing key) becomes a sealed copy the service sends with; the site is made on first sight.
The owner then pastes the site's webhook address (shown on `/messaging`, and `GET /api/v1/sites`) into Mailgun's webhooks
and the reply route. Without the signing key on the connection the service refuses Mailgun's events: enter it under
Connections → Mailgun and share again. The hourly pipeline (`pipelines/messaging-hourly.json`, `Runner::hourly`) does the
mirroring; nothing is mirrored until it has run once, and a page that needs one member in the service now mirrors them itself.

### Say what the app has — once, in lib/app.php

The service knows no groups, no digest content and no phone numbers: the app hands them in, as class names with STATIC
methods. Every key is optional; the admin page says which are connected. A class that does not exist or lacks a method stops
the boot, naming it. The plugin's classes autoload only after boot, so call `Concepts::boot` first (a CLI run, like the hourly
pipeline, never fires the start hook).

```php
if (\app\Concepts::has('messaging')) {
    \app\Concepts::boot(\app\Paths::root());
    \app\concepts\messaging\Providers::register([
        'audiences'  => \app\MessagingAudiences::class,   // all(), scopes($forMember), members($key), label($key), mayMessage($m, $key), category(); optional allowsFor, abouts, aboutDraft, reminder, vars, link
        'digest'     => \app\MessagingDigest::class,      // content($memberId, $since): ?array {headline, html, text} — null = nothing new, no digest
        'triggers'   => ['no_friends_days' => \app\MessagingNoFriends::class],   // qualifies($m, $days): bool, label(): string, optionally content($m)
        'phone'      => \app\MessagingPhone::class,       // verified($memberId): string (E.164 or ''); prove/pending/confirm/cancel let a member prove it
        'categories' => [['news', 'News', 'What is new in your groups', true, true, 'email,text']],   // the app's own kinds; transactional and reminders exist already
        'system'     => [['kind' => 'digest', 'category' => 'news', 'subject' => '{headline}', 'body_email' => '<p>Hi {first_name|there}</p>{content_html}', 'body_text' => "Hi {first_name|there}\n{content_text}", 'cadence' => 'every_n_days', 'cadence_days' => 7],
                         ['kind' => 'trigger', 'trigger_code' => 'no_friends_days', 'trigger_days' => 7, 'category' => 'reminders', 'subject' => '…', 'body_email' => '…']],
    ]);
}
```

`Providers` documents every method. Audiences are keys like `community:5`; `all()` lists every audience (mirrored hourly, members
included). `categories` are brought in line each hour; a `system` message (the digest, a nudge) is MADE ONCE in the service, paused,
when the tenant has none of that kind — the admin switches it on; never overwritten. Register nothing in a seed: seeds run where the service may not be reachable.

### Audiences are derived, never typed

There is no recipient list, here or in the service. A campaign names an audience; the service asks its mirror who is in it,
removes whoever switched that kind of message off (their choice or the kind's default, the audience's own switch, suppression),
and removes whoever its ledger shows already has it. Never keep a list of addresses; never mail around the service for anything
but transactional mail of the app's own. `Reach::allows($memberId, $category, 'email'|'text', $scopeKey)` asks the service the
one question — may this reach this person — before any message of your own; `transactional` is always allowed. An audience's
own switch is the SERVICE's once a member is in it (the hosted page and `/msgpref` flip it); the app's `allowsFor()` only seeds it.

### The owner's page, the cap and the digest

An audience's owner writes at `/messaging/compose?scope=community:5` (link to it from the audience's own page); the audiences
provider's `mayMessage()` decides who may open it, anyone else gets 403. Every check is the service's: a draft is made, asked
about (the words, the text's parts, who gets it) and deleted again. An owner may send `weekly_cap` (default 2) messages per
audience per rolling 7 days; an admin sets it on `/messaging`. The digest and the app's own nudges are APP-FED: each hour
`Runner::hourly` asks the service who is due and posts each person's copy (the digest provider's `content`, a trigger's
`qualifies` + `content`). `inactive_days` and `joined_days` the service evaluates itself from the mirrored dates.

### Settings (on /messaging)

- Per site: the from-name and the reply domain (replies come back to `reply-{token}@` it, through a Mailgun route to the site's
  webhook address; empty = replies go to the from-address, untracked). `weekly_cap`, `owners_may_text`, `text_link_base`.
- The Mailgun webhook key of v1 is gone: the service authenticates Mailgun with the site's hook key (in its address) and the
  connection's signing key. `Webhook::intercept()` no longer exists; remove the call from the app's start hook.

### Texts

A text needs the phone provider AND the member's opt-in (off by default, recorded with when they asked) AND a proved phone. One
part only (it ends with "Reply STOP or:" and the stop link; over a part is refused, not cut). Texting through the service is not
switched on yet: texts are recorded as not sent, loudly, until it is.

### Credit an action; link the choices

Call `\app\concepts\messaging\Campaigns::acted($memberId, 'rsvp'|'post', $meta)` where members act: it credits the campaign whose
link they followed in the last 48 hours (a service that does not answer is an ERROR in the log, never a broken page).
`Reach::prefsUrl($memberId)` is the signed hosted page where the member chooses what reaches them, for the app's own mail footers. Old `/msgpref/unsubscribe?t=` links (signed by the app in v1) answer 410 and point to `/msgpref`.
