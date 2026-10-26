# messaging

Email and text messages to members, with the member in control — sent by the Tiknix messaging service
(messaging.tiknix.com) with the app's own Mailgun account. 2.0.0 is the service's client: the engine (who may be
reached, the ledger, tracked links, the hosted unsubscribe and preferences pages, Mailgun's webhook, the clock)
moved into the service; the app keeps what only it knows.

- `lib/Client.php` — the calls, signed with the app's broker key; `ServiceException` carries the service's refusal.
- `lib/Providers.php` — what the app hands in: audiences, digest content, extra triggers, phone, its kinds of message, the platform's own messages. Documented method by method.
- `lib/Sync.php` — the mirror: members → contacts, kinds → categories, audiences (members included), system messages made once.
- `lib/Reach.php` — may this message reach this person (the service answers); the member's panel; the hosted preferences link.
- `lib/Campaigns.php` — the service's campaigns from here: list, create, send, test, preview, who gets it; `acted()` credits an action.
- `lib/Digest.php` — feeds the digest and the app's own nudges with what they say (due → deliveries). `lib/Runner.php` — what the hourly pipeline calls.
- `controls/Messaging.php` (`/messaging`, `/messaging/compose`), `controls/Msgpref.php` (`/msgpref`).
- `seeds/01_MessagingPermissions.php`; `pipelines/messaging-hourly.json` — hourly mirroring and feeding.

Upgrading from 1.0.0: the app's tables (msgcategory, msgpref, msgcampaign, msgsend, msgevent, msgsuppression) stay until
their rows are imported into the service (the app's own move seed); `Model_Msgcategory::register` in seeds becomes the
`categories` provider, campaign-row seeds become `system`; `Webhook::intercept()` is gone; the audiences provider needs `all()`.

Tests: `php concepts/messaging/tests/run.php` from the app root (a fake service answers; the runner declares the plugin's classes before the app boots its installed copy, which `vendor/bin/phpunit` would load first).
