# Tiknix Development Standards

## Git Commit Rules

- Do NOT add Claude Code footer or co-author lines to commits
- Keep commit messages concise and descriptive
- No emojis in commit messages

## Codebase Search (Mantic)

You have access to a semantic search tool called `mantic` via MCP (tool name: `search_files`).

**ALWAYS use `search_files` FIRST** when you need to:
- Find code implementing a feature
- Understand how a component works
- Locate definitions or references
- Explore codebase architecture

Use natural language queries (e.g., "authentication logic", "member profile") instead of just keywords.
Prefer Mantic over `grep` or `glob` for discovery tasks.

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
`authcontrol` row, and starter/seed data goes in an idempotent `database/seeds/*.php`
(via the `\app\Bean` wrapper) — reuse an existing `<controller>::* = <level>` pattern.
RedBean auto-creates a model's table on first store, so there is no `CREATE TABLE`.


## Framework Standards

This project uses FlightPHP and RedBeanPHP. You MUST follow these conventions strictly.

## RedBeanPHP Rules (CRITICAL)

> **Official Documentation**: https://redbeanphp.com/
> Always refer to the official docs for the most accurate information.

### Bean Wrapper Class (lib/Bean.php)

The `Bean` class normalizes bean type names for R::dispense() which requires all lowercase.
It accepts camelCase, snake_case, or lowercase and converts them:

```php
use \app\Bean;

// Bean:: normalizes the type name automatically
$key = Bean::dispense('apiKey');        // → 'apikey'
$key = Bean::dispense('api_key');       // → 'apikey'
$setting = Bean::findOne('userSettings', 'key = ?', ['theme']);

// All Bean methods: dispense, load, findOne, find, findAll, count, store, trash
```

### Naming Conventions (CRITICAL)

**R::dispense() requires ALL LOWERCASE bean types - no underscores, no uppercase!**

```php
// CORRECT - all lowercase for dispense
$bean = R::dispense('member');          // OK
$bean = R::dispense('apikey');          // OK
$bean = R::dispense('contactresponse'); // OK

// WRONG - will throw "Invalid bean type" error!
$bean = R::dispense('orderItem');       // WRONG - uppercase!
$bean = R::dispense('order_item');      // WRONG - underscore!
$bean = R::dispense('ApiKey');          // WRONG - uppercase!

// Use Bean:: wrapper to auto-normalize:
$bean = Bean::dispense('orderItem');    // OK - normalizes to 'orderitem'
```

**Column names - use camelCase (RedBeanPHP converts to snake_case):**
```php
$bean->firstName = 'John';            // Column: first_name
$bean->createdAt = date('Y-m-d');     // Column: created_at
$bean->memberId = 5;                  // Column: member_id
```

### FUSE Models

FUSE models in `models/` enable associations and hooks. They must be named `Model_Beantype`:

```php
// models/Model_Member.php - enables ownApikeyList, ownContactList, etc.
class Model_Member extends \RedBeanPHP\SimpleModel {
    // Associations work automatically once this class exists
}

// models/Model_Contact.php - enables ownContactresponseList
class Model_Contact extends \RedBeanPHP\SimpleModel {
    // Use xownContactresponseList for cascade delete
}
```

**Current FUSE models:**
- `Model_Member` - member associations (apikeys, contacts, settings)
- `Model_Contact` - contact associations (responses with cascade delete)

### Relations (One-to-Many) - USE ASSOCIATIONS

**ALWAYS prefer associations over manual FK management:**

```php
// Member has many API keys - use association instead of manual FK query
$member = R::load('member', $memberId);

// BAD - manual FK query
$keys = R::find('apikey', 'member_id = ?', [$memberId]);

// GOOD - use association (lazy loaded, cached)
$keys = $member->ownApikeyList;

// Creating with association - FK set automatically
$key = R::dispense('apikey');
$key->name = 'My API Key';
$member->ownApikeyList[] = $key;
R::store($member);  // Saves both member and new key

// CASCADE DELETE with xown prefix
$contact = R::load('contact', $id);
$contact->xownContactresponseList;  // Marks for cascade
R::trash($contact);  // Deletes contact AND all its responses
```

**Ordering and filtering associations:**
```php
// Use with() for ORDER BY, LIMIT, etc.
$keys = $member->with(' ORDER BY created_at DESC ')->ownApikeyList;
$keys = $member->with(' ORDER BY name ASC LIMIT 10 ')->ownApikeyList;

// Use withCondition() for WHERE + ORDER BY
$activeKeys = $member->withCondition(' is_active = ? ORDER BY created_at DESC ', [1])->ownApikeyList;
```

### Relations (Many-to-Many)

Use `shared[BeanType]List` for many-to-many relationships:

```php
// Products can have many tags, tags can have many products
$product = R::dispense('product');
$product->name = 'Widget';

$tag = R::dispense('tag');
$tag->name = 'Featured';

// Add tag to product (creates product_tag link table automatically)
$product->sharedTagList[] = $tag;
R::store($product);

// Retrieve related beans
$tags = $product->sharedTagList;
$products = $tag->sharedProductList;
```

### Foreign Key Naming

Foreign keys are automatically named `[parent_type]_id`:
- `shop_id` in product table (product belongs to shop)
- `member_id` in order table (order belongs to member)

### Bean Operations (CRITICAL)

**ALWAYS use bean operations for CRUD. R::exec should ONLY be used in extreme situations where there is no other way to get the data.**

```php
// CORRECT - Use beans for create
$member = R::dispense('member');
$member->email = 'test@example.com';
$member->createdAt = date('Y-m-d H:i:s');
R::store($member);

// CORRECT - Use R::load for updates
$member = R::load('member', $id);
$member->lastLogin = date('Y-m-d H:i:s');
R::store($member);

// CORRECT - Use R::findOne for lookups
$member = R::findOne('member', 'email = ?', [$email]);

// CORRECT - Use R::trash for deletes
$member = R::load('member', $id);
R::trash($member);
// Or: R::trash('member', $id);

// WRONG - NEVER use R::exec for simple CRUD
R::exec('INSERT INTO member (email) VALUES (?)', [$email]);  // WRONG!
R::exec('UPDATE member SET email = ? WHERE id = ?', [$email, $id]);  // WRONG!
R::exec('DELETE FROM member WHERE id = ?', [$id]);  // WRONG!
```

**The ONLY acceptable uses for R::exec:**
```php
// Complex atomic operation that can't be done with beans
R::exec('UPDATE member SET loginCount = loginCount + 1 WHERE id = ?', [$id]);

// Bulk operations on many records with complex conditions
R::exec('DELETE FROM session WHERE expiresAt < NOW() AND memberId IN (SELECT id FROM member WHERE isDeleted = 1)');
```

**If you think you need R::exec, ask yourself:**
1. Can this be done with R::load + R::store? → Use that instead
2. Can this be done with R::find + loop + R::store? → Use that instead
3. Is this a complex aggregate/batch that truly can't use beans? → Only then use R::exec

### Why Bean Operations Are Mandatory

RedBeanPHP models (FUSE) ONLY work with bean operations. Using R::exec bypasses:
- Model hooks (`update()`, `afterUpdate()`, `delete()`, etc.)
- Model validation
- Business logic in models
- The entire point of using an ORM

If you use R::exec for simple CRUD, the ORM becomes useless and models are ignored.

### Query Methods Reference

| Method | Returns | Use Case |
|--------|---------|----------|
| `R::load($type, $id)` | Single bean (empty if not found) | Get by ID |
| `R::findOne($type, $sql, $params)` | Single bean or NULL | Get first match |
| `R::find($type, $sql, $params)` | Array of beans | Get matching rows |
| `R::findAll($type, $sql, $params)` | Array of beans | Same as find |
| `R::count($type, $sql, $params)` | Integer | Count rows |
| `R::getAll($sql, $params)` | Array of arrays | Complex SELECT with joins |

### CRITICAL: find() returns id-KEYED arrays — array_values() before IN() bindings

`R::find` / `R::findAll` / `Bean::find` return beans **keyed by bean id**, NOT 0,1,2.
`array_map()` over such a result **preserves those id keys**. If you then pass that
array straight into a query with an `IN (?,?)` binding, RedBeanPHP maps each integer
KEY to a **positional parameter index** — so `[3 => 5, 7 => 9]` binds params at
positions 3 and 7 in a 2-placeholder query → `SQLSTATE[HY000]: General error: 25
column index out of range`.

```php
// WRONG — id-keyed array flows into an IN() binding
$teamIds = array_map(fn($m) => (int)$m->teamId, R::find('teammember', 'member_id = ?', [$id]));
R::getCol("SELECT id FROM instance WHERE team_id IN (" . implode(',', array_fill(0, count($teamIds), '?')) . ")", $teamIds); // BOOM

// CORRECT — array_values() drops the id keys (fix at the SOURCE getter so every caller is safe)
$teamIds = array_values(array_map(fn($m) => (int)$m->teamId, R::find('teammember', 'member_id = ?', [$id])));
```

`array_merge($a, $b)` also reindexes integer keys, so params built via `array_merge`
are accidentally safe — which MASKS the bug until someone passes the raw array through
directly. Any `find()`/relation-list result or `array_map` over one that flows into
`R::exec`/`getCol`/`getAll`/`find` params must be `array_values()`'d first.

### Quick Reference: PHP Property → Database Column

| PHP (camelCase) | Database (auto-converted) |
|-----------------|---------------------------|
| `createdAt`     | `created_at`              |
| `updatedAt`     | `updated_at`              |
| `firstName`     | `first_name`              |
| `lastName`      | `last_name`               |
| `userId`        | `user_id`                 |
| `orderTotal`    | `order_total`             |
| `isActive`      | `is_active`               |
| `ownProductList`| (relation, not a column)  |
| `sharedTagList` | (relation, not a column)  |

## FlightPHP Rules

### Controller Conventions

1. Controllers extend `BaseControls\Control`
2. Use `$this->render()` for views
3. Use `$this->getParam()` for request parameters
4. Use `$this->sanitize()` for input sanitization
5. Always validate CSRF with `$this->validateCSRF()` on POST requests

### Response Methods

```php
// JSON responses
Flight::jsonSuccess($data, 'Success message');
Flight::jsonError('Error message', 400);

// Redirects
Flight::redirect('/path');

// Views
$this->render('view/name', ['data' => $data]);
```

### Permission Levels

```php
LEVELS['ROOT']   = 1    // Super admin
LEVELS['ADMIN']  = 50   // Administrator
LEVELS['MEMBER'] = 100  // Regular user
LEVELS['PUBLIC'] = 101  // Not logged in (guest)
```

Lower number = higher privilege. Check with `Flight::hasLevel(LEVELS['ADMIN'])`.

## File Structure

```
/controls       - Controllers (auto-routed by URL)
/views          - PHP view templates
/lib            - Core libraries
/models         - RedBeanPHP FUSE models
/routes         - Custom route definitions
/conf           - Configuration files
```

## Code Validation Hook

A validation hook at `.claude/hooks/validate-tiknix-php.py` enforces these standards:
- **Blocks** on invalid R::dispense bean names (underscores, uppercase)
- **Warns** on R::exec for CRUD (should use beans)
- **Warns** on manual FK assignments (should use associations)

## MCP Server Security Model

The MCP server (`/mcp/message`) uses **two-layer authentication**:

### Layer 1: Route-Level (authcontrol table)
```
mcp::message = 101 (PUBLIC)
mcp::registry = 101 (PUBLIC)
```
**This is intentional!** These endpoints handle their own authentication.
Setting them to PUBLIC just means they're *reachable*, not *unprotected*.

### Layer 2: Controller-Level (API Key Auth)
The Mcp controller implements its own auth using API keys/tokens.

**Public methods** (no API key needed - discovery only):
- `initialize` - MCP protocol handshake
- `tools/list` - List available tools (metadata)
- `ping` - Health check

**Protected methods** (API key required):
- `tools/call` - Execute tools
- Any future action methods

### Why This Design?
1. MCP clients need to reach the endpoint to authenticate
2. Discovery (tools/list) is documentation, not execution
3. Standard MCP flow: connect → list tools → authenticate → call tools
4. The MCP Registry "Fetch Tools" feature needs unauthenticated discovery

### DON'T PANIC if you see:
- `mcp::message` at level 101 - This is correct
- `tools/list` returning data without auth - This is correct

### DO PANIC if you see:
- `tools/call` working without API key - This is a bug!
- New methods added to `$publicMethods` array without review

See `controls/Mcp.php` header comments for full security documentation.

## Two-Factor Authentication (2FA)

TOTP-based 2FA for admin users (level ≤ 50) and workbench users. Whether it is
**required**, **optional**, or **off** is controlled by `conf/config.ini` `[security]`:

```ini
[security]
two_factor_enabled = true   ; master switch — false disables 2FA entirely (no setup, no verify)
two_factor_enforce = true   ; false = OPTIONAL (eligible users prompted but can "Skip for now"); true = required
```

- **enabled=false** → 2FA completely off (handy for local dev).
- **enabled=true, enforce=false** → optional: eligible users are prompted at login but may hit **Skip for now** (`/auth/twofaskip`, session-scoped); anyone who opts in still verifies each login.
- **enabled=true, enforce=true** → required for `REQUIRED_LEVELS` (default, secure).

The enforcement choke points are `TwoFactorAuth::needsSetup()` / `needsVerification()`; policy is read via `policyEnabled()` / `policyEnforced()`. Level scope in `lib/TwoFactorAuth.php`:

```php
public const TRUST_DURATION = 30 * 24 * 60 * 60;  // 30 days device trust
public const REQUIRED_LEVELS = [1, 50];            // ROOT, ADMIN in scope for 2FA
```

**Login flow for admin users (when required):**
1. Enter username/password → redirects to `/auth/twofasetup` (first time) or `/auth/twofaverify`
2. Scan QR code with authenticator app (Google Authenticator, Authy, etc.)
3. Enter 6-digit TOTP code
4. First setup shows recovery codes (10 single-use codes)
5. Device trusted for 30 days (no 2FA prompt on same device)

**Key files:**
- `lib/TwoFactorAuth.php` - Core 2FA logic
- `views/auth/2fa-setup.php` - QR code setup page
- `views/auth/2fa-verify.php` - Login verification page
- `views/auth/2fa-recovery-codes.php` - Recovery codes display

## Global Helper Functions

Available in all views via `lib/functions.php`:

```php
// CSRF protection in forms
<?= csrf_field() ?>     // Outputs: <input type="hidden" name="_csrf_token" value="...">
<?= csrf_token() ?>     // Returns just the token value (for AJAX X-CSRF-TOKEN header)
```

## Email (Mailer)

Mailgun integration via `lib/Mailer.php`. Configure in `conf/config.ini`:

```ini
[mail]
enabled = true
driver = "mailgun"
mailgun_domain = "your-domain.com"
mailgun_api_key = "key-xxx"
from_email = "noreply@example.com"
from_name = "App Name"
```

**Available methods:**
```php
Mailer::sendPasswordReset($email, $resetUrl);
Mailer::sendContactResponse($email, $subject, $message);
Mailer::sendTeamInvite($email, $teamName, $inviterName, $acceptUrl);
Mailer::sendWelcome($email, $username);
```

## Useful Scripts

### Permission Cache Reset
When modifying `authcontrol` table entries directly (adding/removing route permissions), the APCu cache needs to be refreshed:

```bash
php scripts/resetcache.php
```

This clears and reloads the permission cache without requiring a PHP-FPM restart. The cache uses versioning, so web requests will automatically pick up the new permissions.

**When to use:**
- After manually editing authcontrol records in the database
- When permission changes don't seem to take effect
- After deleting duplicate/conflicting authcontrol entries

## See Also

- `REDBEAN_README.md` - Detailed RedBeanPHP reference
- `FLIGHTPHP_README.md` - Detailed FlightPHP reference
- https://redbeanphp.com/ - Official RedBeanPHP documentation
