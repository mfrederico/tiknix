# API documentation

Document this app's HTTP API in OpenAPI (Swagger) form, and serve it.

The routes already exist — `controls/Shift.php` and `controls/Workshift.php` — and their
permission levels already exist in `authcontrol`. Read both. Do not invent endpoints, and
do not restate what the code says: derive the document from what is actually there, so it
cannot drift from the app.

## What to build

**`lib/OpenApi.php`** — builds an OpenAPI 3.1 document for this instance:

- One path per public controller method that has an `authcontrol` row, using the same
  `/controller/method` routing the app uses.
- `GET` for read methods, `POST` for the ones that write (`save`, `assign`, `request`,
  `decide`). Say which in the summary if it is not obvious from the name.
- Every path carries its **required level** from `authcontrol` — 50 manager, 100 worker —
  as a description line, so the document answers "who may call this".
- Schemas for the three things the API returns: `Shift`, `Workshift`, `ShiftRequest`.
  Surface **uuid, never id** — that is the rule the rest of the app follows.
- Mark the manager-only paths with a `security` requirement so the split is machine
  readable, not just prose.

**`GET /apidocs/spec`** — returns that document as JSON. PUBLIC: an API description is not
a secret, and tooling has to fetch it without a session.

**`GET /apidocs`** — a page that renders the spec with Swagger UI from a CDN, pointed at
`/apidocs/spec`. PUBLIC too.

**A seed in `scripts/`** — the `authcontrol` rows for both new routes, idempotent, same
shape as `scripts/seed-shift-manager.php`. Without them the routes inherit the admin
default and the docs are unreachable.

## Rules

- Derive from `authcontrol` and the controllers. A hand-written list of endpoints is wrong
  by tomorrow.
- No new beans, no migrations, no changes to the existing controllers.
- If a route exists with no `authcontrol` row, leave it out and say so in the summary
  count rather than guessing its level.
