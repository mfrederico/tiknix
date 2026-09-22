## Codebase Introspection (MCP)

Inside an AI Builder instance, the `tiknix` MCP server exposes structural
primitives — prefer them over scanning the tree:

- `reuse_digest` — the pre-baked "what already exists" inventory in ONE call: controllers (+levels), models (+columns/relations), lib services (+methods), authcontrol wildcards, config sections, seeders. Call this FIRST when adding a feature.
- `codebase_map` — orient first: controllers (+route counts), models+tables, lib classes, config sections.
- `whatprovides("<concept>")` — everything providing a concept (e.g. `auth`, `email`, `permissions`), as ranked `path:line` pointers.
- `describe("<name>")` — a controller's routes+levels, a model's columns+relations, or a lib's methods.

They return pointers, not file bodies — `Read` the file at the pointer for detail. Use these before grepping.

### Reuse first (MANDATORY when adding functionality)

Before creating any controller, model, or lib service, call `reuse_digest` and MATCH the
need against what already exists. For each capability, decide explicitly:

- **REUSE** an existing primitive — wire to it.
- **EXTEND** an existing primitive — add a method / column / route to it.
- **NEW** — only when nothing fits, and say why.

Bias hard toward REUSE/EXTEND. A new controller/model/service when a close match already
exists is a defect — prefer a method on an existing controller and a column on an existing
model. When decomposing a plan, record what each task builds on in its `reuses` field
(e.g. `["controller/Lead","lib/Mailer"]`).

Data & permissions ship as seeds, never as direct DB writes: a new route needs an
`authcontrol` row, and starter/seed data goes in an idempotent numbered seeder
`services/Schema/Seeds/NN_Name.php` (run by `WorkspaceSchemaBuilder::build()`, i.e.
`php scripts/clitool.php --build`) — reuse an existing `<controller>::* = <level>` pattern.

**Set permission rows with `PermissionCache::seedRule()`, not by hand.** A route gets an
auto-generated row at the ADMIN default the first time ANYTHING touches it — including a
`curl` while you are testing, or a verification step that fetches the page before seeding
it. A hand-written seed that "never widens an existing rule" then finds that row, declines
to change it, and the route stays admin-only forever:

```php
use app\PermissionCache;
// corrects a row the framework invented; never overrules one a person set
echo PermissionCache::seedRule('apidocs', 'spec', 101, 'OpenAPI JSON (public)');  // added|corrected|kept|unchanged
```

Seed the rows BEFORE fetching the route, and report what `seedRule` returns — `kept` means
somebody's deliberate rule won, which is a fact worth printing rather than swallowing.
RedBean auto-creates a model's table on first store, so there is no `CREATE TABLE`.

