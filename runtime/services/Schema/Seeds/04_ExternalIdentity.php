<?php
/**
 * 04_ExternalIdentity.php — people who reach you from OUTSIDE, and the channels
 * they reach you through.
 *
 * A Telegram group or a Slack channel contains people who have no tiknix
 * account and may never want one. They still send messages, and those messages
 * still need an author.
 *
 *   externalidentity — one handle on one connected platform. NOT a member.
 *
 * The whole reason this is its own table rather than a `member` row with a
 * status: 31 of the 35 member lookups in this codebase do not filter on status,
 * and two of them are authentication paths. A row that cannot log in has no
 * business sitting where credentials are resolved. Keeping it out is structural
 * — there is nothing to remember and nothing to forget.
 *
 * Linking, not merging. `member_id` is null while the handle belongs to nobody,
 * and points at an account once that person signs up. Historical messages keep
 * pointing at the handle, which stays the record of how they reached you; the
 * ACCOUNT wins for name and permissions from the moment it is linked. Converting
 * somebody is one column, not a rewrite of the 26 tables that carry a member id.
 *
 * One person on two platforms is two handles pointing at one member, which a
 * shadow-member design cannot express without two accounts for one human.
 *
 * Column naming, and the trap it avoids:
 *
 *   connection_ref  — NOT connection_id. The connections bean type is PLURAL, so
 *                     RedBean would read a `connection_id` suffix as a relation to
 *                     a bean type `connection` that does not exist. Same reason
 *                     lib/Mentions.php uses thread_ref / message_ref. The index
 *                     RedBean would have given a real *_id column is declared
 *                     explicitly in database/migrate-external-identity.php.
 *
 * Also widens two existing beans:
 *
 *   thread.connection_ref  — the connected channel this conversation belongs to
 *   thread.external_ref    — the chat/channel id on that platform. TEXT, not the
 *                            existing related_id: Slack channel ids are strings
 *                            (C024BE91L) and Telegram group ids are large and
 *                            negative, so an INTEGER column cannot hold them.
 *
 * message.provider_id is REUSED for the platform's own message id rather than
 * adding an external_ref beside it — it already means "what this message is
 * called at the provider", which is exactly the value webhook retries need to
 * deduplicate against. See the unique index in the migration.
 *
 * Idempotent: fluid mode only adds what is missing, and every ghost is deferred
 * so the builder trashes it once the schema is baked.
 */

use \RedBeanPHP\R;

// ---- externalidentity ghost ------------------------------------------------
$identity = R::dispense('externalidentity');
$identity->connection_ref    = 999999;              // connections.id — see note above
// external_eid, matching connections.external_eid and the connector contract in
// services/connectors/ConnectorInterface.php: "what the far end calls this
// thing". There it is a GitHub repo path or a Shopify domain; here it is the
// platform's own user id. Same concept, so the same name.
$identity->external_eid      = str_repeat('x', 128);
$identity->external_handle   = str_repeat('x', 128); // @name on that platform
$identity->display_name      = str_repeat('x', 200);
$identity->avatar_url        = str_repeat('x', 500);
// member_ref, NOT member_id, and for a different reason than connection_ref
// above. `member_id` IS a valid RedBean relation — member is a real bean type —
// so fluid mode emits a genuine FOREIGN KEY. But members are HARD deleted
// (controls/Admin.php:479 calls Bean::trash), and SQLite's default NO ACTION
// would make deleting anyone who had ever been linked fail with a constraint
// violation. The handle is meant to OUTLIVE the account anyway, so the link is a
// plain pointer that Model_Member::delete() clears.
$identity->member_ref        = 999999;              // null until they sign up
$identity->message_count     = 999999;
$identity->first_seen_at     = date('Y-m-d H:i:s');
$identity->last_seen_at      = date('Y-m-d H:i:s');  // drives purging idle handles
$identity->blocked_at        = date('Y-m-d H:i:s');  // per-handle mute, no account needed
$identity->created_at        = date('Y-m-d H:i:s');
$identity->updated_at        = date('Y-m-d H:i:s');
R::store($identity);
$_defer($identity);

// ---- thread, widened for connected channels --------------------------------
// Fluid mode adds only the columns that are missing, so this does not disturb
// the thread schema declared in 03_EmailThread.php.
$thread = R::dispense('thread');
$thread->connection_ref = 999999;
$thread->external_ref   = str_repeat('x', 191);
$thread->created_at     = date('Y-m-d H:i:s');
R::store($thread);
$_defer($thread);

// ---- message, widened for external authorship ------------------------------
// external_identity_ref rather than *_id for the same reason as above: the
// identity bean type is `externalidentity`, and a would-be FK column named for a
// property is not what we want here — this is a plain pointer, indexed
// explicitly in the migration.
//
// sender_member_id and external_identity_ref are mutually exclusive: a message
// is either from an account or from a handle. Nothing enforces that in the
// schema; NotifyService sets exactly one.
$message = R::dispense('message');
$message->external_identity_ref = 999999;
$message->created_at            = date('Y-m-d H:i:s');
R::store($message);
$_defer($message);
