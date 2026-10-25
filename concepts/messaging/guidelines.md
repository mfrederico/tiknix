Messages to members — email and, for those who ask, text — with the member in control. Beans it owns:
`msgcategory` (a kind of message a member has a switch for), `msgpref` (their choice per kind and channel),
`msgcampaign` (a message and its audience), `msgsend` (the ledger: one row per recipient, written BEFORE the
message goes out), `msgevent` (a click, open, reply or action on a send), `msgsuppression` (addresses never to
mail). Classes `app\concepts\messaging\{Reach, Campaigns, Digest, Webhook, Runner, Providers}`; controllers
`Messaging` (`/messaging`, admins; `/messaging/compose?scope=KEY` for an audience's owner), `Msgtrack`
(`/msgtrack/l`, `/msgtrack/o`), `Msgpref` (`/msgpref` "What reaches you", `/msgpref/unsubscribe?t=`). Link to
`/msgpref` from the member's settings and to `/messaging` from the admin menu; do not draw a preferences panel of your own.

### Say what the app has — once, in lib/app.php

The plugin knows no groups, no digest content and no phone numbers: the app hands them in, as class names with
STATIC methods. Every key is optional; the admin page says which are connected. A class that does not exist or lacks
a method stops the boot, naming it. The plugin's classes autoload only after boot, so call `Concepts::boot` first
(a CLI run, like the hourly pipeline, never fires the start hook).

```php
if (\app\Concepts::has('messaging')) {
    \app\Concepts::boot(\app\Paths::root());
    \app\concepts\messaging\Providers::register([
        'audiences' => \app\MessagingAudiences::class,   // scopes($forMember), members($key), label($key), allowsFor($m, $key): ?bool, allow($m, $key, $on), mayMessage($m, $key), category()
        'digest'    => \app\MessagingDigest::class,      // content($memberId, $since): ?array {headline, html, text} — null = nothing new, no digest
        'triggers'  => ['no_friends_days' => \app\MessagingNoFriends::class],   // qualifies($m, $days): bool, label(): string
        'phone'     => \app\MessagingPhone::class,       // verified($memberId): string (E.164 or ''); prove/pending/confirm/cancel let a member prove it
    ]);
}
```

`Providers` documents every method, including the optional ones for a message ABOUT a thing inside an audience
(`abouts`, `aboutDraft`, `reminder`, `vars`, `link`). Audiences are keys like `community:5` (`kind:id`).

### Audiences are derived, never typed

There is no recipient list. `Campaigns::audience($campaign)` asks the audiences provider who is in the audience, removes
whoever switched that kind of message off (`Reach::allows()`: the member's choice or the kind's default, the audience's own
switch, suppression), and removes whoever the ledger shows already has it. Never keep a list of addresses; never send
around the ledger. `Reach::allows($memberId, $category, 'email'|'text', $scopeKey)` is the one answer to "may this reach
this person" — ask it before any message of your own. `transactional` is always allowed.

### The owner's page, the cap and the digest

An audience's owner writes at `/messaging/compose?scope=community:5` (link to it from the audience's own page); the
audiences provider's `mayMessage()` decides who may open it, anyone else gets 403. The page draws the form, the audience
line, the test send and the list. An owner may send `community_sends_per_week` (default 2) messages per audience per rolling
7 days; an admin sets it on `/messaging`. The weekly digest and the nudges (`inactive_days`, `joined_days`, plus the app's own
trigger codes) are campaigns of kind `digest` and `trigger`, run hourly by `pipelines/messaging-hourly.json`; with no digest
provider the digest is shown as unavailable and never sends.

### Settings to fill in (on /messaging)

- `messaging_reply_domain` — replies come back to `reply-{token}@` this domain; nothing is sent while it is empty.
- `mailgun_webhook_key` — the same value as `?key=` in the Mailgun route to `/webhook/mailgun`; delivery events and replies are refused until it is set. Call `\app\concepts\messaging\Webhook::intercept()` from the app's start hook (the runtime owns that route).
- `messaging_site_domain` — only in an install with several sites: whose Mailgun connection sends.
- `community_sends_per_week`, `owners_may_text`, `text_link_base` — the cap, whether owners may text, the short address for text links.

### Texts

A text needs the phone provider AND the member's opt-in (off by default, recorded with when they asked) AND a proved phone.
Each text is paid for by the character and must fit one part: it ends with "Reply STOP or:" and the stop link, and one over a
part is refused, not cut. Reminders text those who asked; an owner's text needs the admin's `owners_may_text` switch and
words of their own. No phone provider means the preferences page does not offer texts.

### Credit an action; link an unsubscribe

Call `\app\concepts\messaging\Campaigns::acted($memberId, 'rsvp'|'post', $meta)` where members act; it credits the campaign
whose link they followed in the last 48 hours. `Reach::unsubscribeUrl($memberId, $category, $scopeKey)` is the signed link
(`/msgpref/unsubscribe?t=…`, no expiry; the page shows one button, the button does it). Register an app's own kind of message
in a seed with `Model_Msgcategory::register($code, $label, $description, $defaultOn, $canOptOut)`.
