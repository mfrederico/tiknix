# Shift Manager

Build a shift-scheduling tool: managers define reusable shift templates, assign workers
to dated work shifts, and workers see their own roster and request changes.

This is a spec, not a wish list. Build exactly what is here.

## Domain

Three beans. Every one of them carries a `uuid` column, and **only the uuid is ever
surfaced** — ids stay internal, in URLs, JSON and views alike.

- **member** — already exists. EXTEND it: add `role` (`admin` | `manager` | `worker`),
  `managerId` (the member they report to), and `uuid`.
- **shift** — a reusable *template*, not an occurrence. `name` is cosmetic (`day`,
  `night`, `grave`, or anything); the real content is `startTime` / `endTime` as `HH:MM`
  in a 24-hour range, plus `workersNeeded`.
- **workshift** — one dated occurrence: a `shift` template applied to a `workDate`
  (`YYYY-MM-DD`) and assigned to a member. A shift template is reused across many dates;
  that reuse is the whole point of splitting the two.

Use associations, not manual foreign keys: `$member->ownWorkshiftList`,
`$shift->ownWorkshiftList`. A FUSE model in `models/` for each type that needs one.

## Routes

Each public controller method is a route, and each needs an `authcontrol` row — without
one it falls through to PUBLIC, which for this app would be a data leak. Ship the rows
as an idempotent seed in `scripts/`.

**Worker (level 100)**
- `GET /workshift/mine` — this member's upcoming work shifts as JSON: uuid, shift name,
  date, start, end, status.
- `POST /workshift/request` — request a change to one of their own work shifts (new
  start/end, or time off) with a reason. Never lets a worker edit someone else's.

**Manager (level 50)**
- `GET /shift/list`, `POST /shift/save` — manage the reusable templates. `save` is an
  upsert keyed on uuid.
- `GET /workshift/roster?week_of=YYYY-MM-DD` — the week's roster as JSON: every dated
  work shift with its assigned member, plus `workersNeeded` vs assigned count per shift
  so the UI can show which shifts are short.
- `POST /workshift/assign` — upsert a member onto a dated shift. **Reject an assignment
  that overlaps another work shift the same member already has that day**, and say which
  one it collided with.
- `GET /workshift/requests`, `POST /workshift/decide` — pending change requests, and
  approve/decline with a reason.

## Views

- `views/workshift/roster.php` — the week's roster as a table, Bootstrap, rendered from
  the roster JSON. Short-staffed shifts stand out.
- `views/workshift/mine.php` — a worker's own upcoming shifts, with the request form.

The JSON endpoints must carry enough for the page to render without a second call.

## The outbox

Every POST that changes data writes an `outbox` row in the same request: `topic`,
`payload` (JSON), `createdAt`, `processedAt` (null until drained). The write and its
outbox row succeed or fail together — that is the point of the pattern.

Draining is a **pipeline**, not a bespoke daemon: a pipeline definition in `pipelines/`
that reads unprocessed outbox rows, handles them, and stamps `processedAt`. Reuse the
existing pipeline runtime; do not write a new scheduler.

## Rules

- No migrations. Storing a bean creates its table.
- No `R::exec` for CRUD — beans only.
- Every new route gets its permission row in the same seed.
- A manager may only act on members who report to them; an admin may act on anyone.
  Enforce it server-side in every manager route, not just in the view.
