## RedBeanPHP Rules (CRITICAL)

> **Official Documentation**: https://redbeanphp.com/
> Always refer to the official docs for the most accurate information.

### Bean Wrapper Class (lib/Bean.php) — REQUIRED

**ALWAYS use `Bean::` for database operations. Never call `R::` directly.**

Raw `R::dispense()` requires all-lowercase bean types and throws "Invalid bean type" on
anything else. `Bean::` normalizes the type name for you, so it accepts camelCase,
snake_case, or lowercase and converts them:

```php
use \app\Bean;

// Bean:: normalizes the type name automatically
$key = Bean::dispense('apiKey');        // → 'apikey'
$key = Bean::dispense('api_key');       // → 'apikey'
$key = Bean::dispense('ApiKey');        // → 'apikey'
$setting = Bean::findOne('userSettings', 'key = ?', ['theme']);
```

`Bean::` wraps the full surface — CRUD, raw queries, transactions, schema and
multi-database control:

| Group | Methods |
|-------|---------|
| CRUD | `dispense`, `load`, `store`, `trash`, `trashAll` |
| Queries | `findOne`, `find`, `findAll`, `count` |
| Raw SQL | `exec`, `getAll`, `getRow`, `getCol`, `getCell` |
| Transactions | `begin`, `commit`, `rollback` |
| Schema / DB | `freeze`, `inspect`, `addDatabase`, `selectDatabase`, `hasDatabase`, `currentDatabaseKey`, `getDatabaseAdapter` |
| Utility | `normalize`, `genSlots` |

**Direct `R::` is legitimate in exactly two places:**
1. Connection lifecycle in `bootstrap.php` — `R::setup()`, `R::close()` (not wrapped).
2. **Schema seeds** (`services/Schema/Seeds/*.php`) — these build the schema itself and
   run before the ORM layer is meaningful, so raw `R::` is expected there.

Everywhere else — controllers, lib services, models, scripts — goes through `Bean::`.

### Naming Conventions (CRITICAL)

Bean type names reach RedBeanPHP as all-lowercase, no underscores. `Bean::` handles the
conversion, which is exactly why you should never bypass it:

```php
// CORRECT - Bean:: normalizes whatever you pass
$bean = Bean::dispense('member');         // → 'member'
$bean = Bean::dispense('apiKey');         // → 'apikey'
$bean = Bean::dispense('order_item');     // → 'orderitem'
$bean = Bean::dispense('contactResponse');// → 'contactresponse'

// WRONG - bypassing the wrapper, throws "Invalid bean type" at RUNTIME
$bean = R::dispense('orderItem');       // WRONG - uppercase!
$bean = R::dispense('order_item');      // WRONG - underscore!
$bean = R::dispense('ApiKey');          // WRONG - uppercase!
```

**Column names - use camelCase (RedBeanPHP converts to snake_case):**
```php
$bean->firstName = 'John';            // Column: first_name
$bean->createdAt = date('Y-m-d');     // Column: created_at
$bean->memberId = 5;                  // Column: member_id
```

### External IDs and non-FK pointers (CRITICAL)

The `_id` suffix is RESERVED for RedBeanPHP integer foreign keys. RedBean reads any
`<something>_id` column as a relation to bean type `<something>` and emits a real FK in
fluid mode. Two suffixes exist to stay out of its way:

| Suffix | Meaning | Example |
|--------|---------|---------|
| `_eid` | **String external ID** — what the far end calls this thing | `external_eid` (Shopify domain, Stripe acct, Telegram user id) |
| `_ref` | **Plain integer pointer** where a real FK is wrong | `connection_ref`, `member_ref`, `external_identity_ref` |

```php
// WRONG — RedBean tries to make this an integer FK to a bean type 'shopify'
$conn->shopify_id = 'acme-store.myshopify.com';

// CORRECT — string ids from another system always end in _eid
$conn->external_eid = 'acme-store.myshopify.com';
```

Use `_ref` (not `_id`) when the target is a real row but a FOREIGN KEY would be harmful:
- The bean type is **plural** (`connections`), so `connection_id` would point at a bean
  type `connection` that does not exist — see `services/Schema/Seeds/04_ExternalIdentity.php`
  and `lib/Mentions.php` (`thread_ref`, `message_ref`).
- The parent is **hard deleted** (`Bean::trash`), and SQLite's default `NO ACTION` would
  make that delete fail forever — e.g. `externalidentity.member_ref`.

A `_ref` column gets no automatic index. Declare it explicitly in the migration
(`database/migrate-external-identity.php` is the worked example).

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
$member = Bean::load('member', $memberId);

// BAD - manual FK query
$keys = Bean::find('apikey', 'member_id = ?', [$memberId]);

// GOOD - use association (lazy loaded, cached)
$keys = $member->ownApikeyList;

// Creating with association - FK set automatically
$key = Bean::dispense('apikey');
$key->name = 'My API Key';
$member->ownApikeyList[] = $key;
Bean::store($member);  // Saves both member and new key

// CASCADE DELETE with xown prefix
$contact = Bean::load('contact', $id);
$contact->xownContactresponseList;  // Marks for cascade
Bean::trash($contact);  // Deletes contact AND all its responses
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
$product = Bean::dispense('product');
$product->name = 'Widget';

$tag = Bean::dispense('tag');
$tag->name = 'Featured';

// Add tag to product (creates product_tag link table automatically)
$product->sharedTagList[] = $tag;
Bean::store($product);

// Retrieve related beans
$tags = $product->sharedTagList;
$products = $tag->sharedProductList;
```

### Foreign Key Naming

Foreign keys are automatically named `[parent_type]_id`:
- `shop_id` in product table (product belongs to shop)
- `member_id` in order table (order belongs to member)

### Bean Operations (CRITICAL)

**ALWAYS use bean operations for CRUD. `Bean::exec` should ONLY be used in extreme situations where there is no other way to get the data.**

```php
// CORRECT - Use beans for create
$member = Bean::dispense('member');
$member->email = 'test@example.com';
$member->createdAt = date('Y-m-d H:i:s');
Bean::store($member);

// CORRECT - Use Bean::load for updates
$member = Bean::load('member', $id);
$member->lastLogin = date('Y-m-d H:i:s');
Bean::store($member);

// CORRECT - Use Bean::findOne for lookups
$member = Bean::findOne('member', 'email = ?', [$email]);

// CORRECT - Use Bean::trash for deletes
$member = Bean::load('member', $id);
Bean::trash($member);
// Or: Bean::trash('member', $id);

// WRONG - NEVER use exec for simple CRUD
Bean::exec('INSERT INTO member (email) VALUES (?)', [$email]);  // WRONG!
Bean::exec('UPDATE member SET email = ? WHERE id = ?', [$email, $id]);  // WRONG!
Bean::exec('DELETE FROM member WHERE id = ?', [$id]);  // WRONG!
```

**The ONLY acceptable uses for `Bean::exec`:**
```php
// Complex atomic operation that can't be done with beans
Bean::exec('UPDATE member SET loginCount = loginCount + 1 WHERE id = ?', [$id]);

// Bulk operations on many records with complex conditions
Bean::exec('DELETE FROM session WHERE expiresAt < NOW() AND memberId IN (SELECT id FROM member WHERE isDeleted = 1)');
```

**If you think you need `Bean::exec`, ask yourself:**
1. Can this be done with `Bean::load` + `Bean::store`? → Use that instead
2. Can this be done with `Bean::find` + loop + `Bean::store`? → Use that instead
3. Is this a complex aggregate/batch that truly can't use beans? → Only then use `Bean::exec`

### Why Bean Operations Are Mandatory

RedBeanPHP models (FUSE) ONLY work with bean operations. Using raw `exec` bypasses:
- Model hooks (`update()`, `afterUpdate()`, `delete()`, etc.)
- Model validation
- Business logic in models
- The entire point of using an ORM

If you use `exec` for simple CRUD, the ORM becomes useless and models are ignored.

### Query Methods Reference

| Method | Returns | Use Case |
|--------|---------|----------|
| `Bean::load($type, $id)` | Single bean (empty if not found) | Get by ID |
| `Bean::findOne($type, $sql, $params)` | Single bean or NULL | Get first match |
| `Bean::find($type, $sql, $params)` | Array of beans (**id-keyed**) | Get matching rows |
| `Bean::findAll($type, $sql, $params)` | Array of beans (**id-keyed**) | Same as find |
| `Bean::count($type, $sql, $params)` | Integer | Count rows |
| `Bean::dispense($type)` | New bean | Create new bean |
| `Bean::store($bean)` | ID | Save bean |
| `Bean::trash($bean)` | void | Delete bean |
| `Bean::getAll($sql, $params)` | Array of arrays | Complex SELECT with joins |
| `Bean::getRow($sql, $params)` | Array or null | Single row as array |
| `Bean::getCol($sql, $params)` | Flat array | Single column |
| `Bean::getCell($sql, $params)` | Mixed | Single value |

### CRITICAL: find() returns id-KEYED arrays — array_values() before IN() bindings

`Bean::find` / `Bean::findAll` return beans **keyed by bean id**, NOT 0,1,2.
`array_map()` over such a result **preserves those id keys**. If you then pass that
array straight into a query with an `IN (?,?)` binding, RedBeanPHP maps each integer
KEY to a **positional parameter index** — so `[3 => 5, 7 => 9]` binds params at
positions 3 and 7 in a 2-placeholder query → `SQLSTATE[HY000]: General error: 25
column index out of range`.

```php
// WRONG — id-keyed array flows into an IN() binding
$teamIds = array_map(fn($m) => (int)$m->teamId, Bean::find('teammember', 'member_id = ?', [$id]));
Bean::getCol("SELECT id FROM instance WHERE team_id IN (" . implode(',', array_fill(0, count($teamIds), '?')) . ")", $teamIds); // BOOM

// CORRECT — array_values() drops the id keys (fix at the SOURCE getter so every caller is safe)
$teamIds = array_values(array_map(fn($m) => (int)$m->teamId, Bean::find('teammember', 'member_id = ?', [$id])));
```

`array_merge($a, $b)` also reindexes integer keys, so params built via `array_merge`
are accidentally safe — which MASKS the bug until someone passes the raw array through
directly. Any `find()`/relation-list result or `array_map` over one that flows into
`Bean::exec`/`getCol`/`getAll`/`find` params must be `array_values()`'d first.

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
