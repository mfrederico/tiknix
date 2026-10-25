# messaging

Email and text messages to members, with the member in control, extracted from an app's own messaging code.

- `lib/Reach.php` — may this message reach this person (kind default, the member's choice, the audience's own switch, suppression), and the signed unsubscribe link.
- `lib/Campaigns.php` — a message with a derived audience, the ledger (`msgsend`, written before the send), rendering, tracking, texts, the weekly cap, stats.
- `lib/Digest.php` — the weekly digest and the nudges. `lib/Runner.php` — what the hourly pipeline calls.
- `lib/Webhook.php` — Mailgun delivery events and replies (`intercept()` is called from the app's start hook).
- `lib/Providers.php` — what the app hands in: audiences, digest content, extra triggers, phone. Documented method by method.
- `controls/Messaging.php` (`/messaging`, `/messaging/compose`), `controls/Msgtrack.php`, `controls/Msgpref.php` (`/msgpref`).
- `seeds/` — schema (refuses to run before an app has renamed earlier tables), permissions, the two built-in kinds of message.
- `pipelines/messaging-hourly.json` — hourly sending.

Tests: `vendor/bin/phpunit --bootstrap vendor/autoload.php concepts/messaging/tests` from the app root.
