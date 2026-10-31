Cookie consent the browser enforces. Classes `app\concepts\consent\{Guard, Policy, Inventory, Known, Groups, Choice, Banner, Settings}`;
controller `Consent` (`/consent` for admins, `/consent/cookies` public). The plugin adds its own menu entry, footer link and banner.

### What to do in an app

- Run something only on a yes: `<script type="text/plain" data-consent="analytics" src="…"></script>`, which the plugin's script
  starts after a yes, or `Choice::allows('analytics')` in PHP. In a page's own script: `tiknixConsent.allows('analytics')`.
  Groups: `preferences`, `analytics`, `media`, `marketing` (`necessary` is never asked).
- A layout of the app's own gets the banner and the policy by itself; give its footer a link to `/consent/cookies`.
- Never hand-write a banner, and never send a `Content-Security-Policy` header for this: a policy sent later replaces the plugin's
  on that response, so that page is not covered. (A page that must be framed elsewhere, like a Shopify admin page, is that case.)

### How it works

A plugin declares what it loads at the collect point `consent.services` (see `Inventory::declared`): a service waiting for a yes never loads, so no browser could report it.

Watching (the start): a report-only policy, nothing blocked, new outside hosts reported to `/consent/report` and listed. Enforcing
(the owner's button): visitors are asked and the browser refuses all but what is needed and what they accepted; unsorted hosts are
blocked for everyone. The app's connections (storage, video, Stripe, Turnstile) are needed by themselves. A service sorted into an
asked group raises the list's version, so everyone is asked again.

A page scan runs only when a person asks for one: the button on `/consent`, or the tool `consent_scan` when they say "run the consent scan". Never schedule it, run it after a build, or start it from a pipeline. The scan: the platform walks the site from its QA host, as a visitor and as a plain member of its own (`Scan::SCAN_EMAIL`, switched off between scans; signed in it follows links only), before answering, and lists outside services, cookies and browser storage. Non-GET requests are stopped and action-looking addresses skipped.
