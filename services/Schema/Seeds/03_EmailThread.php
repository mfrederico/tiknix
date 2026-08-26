<?php
/**
 * 03_EmailThread.php — threaded two-way email comms subsystem.
 *
 * Three beans (all lowercase; camelCase props → snake_case columns):
 *
 *   emailthread      — one conversation. reply_token powers inbound routing:
 *                      mail arriving at reply-{token}@<inbound_domain> lands
 *                      back on this thread via /webhook/mailgun.
 *   notify           — one message in a thread (in|out, email|system).
 *   notifyattachment — a file on a message/thread.
 *
 * Schema is built RedBean-natively: we dispense a *ghost* of each bean, wire
 * the parent/child beans together with BEAN REFERENCES (not raw ids), store
 * them so RedBean's fluid mode emits the correct columns + foreign keys +
 * FK indexes by convention, then $_defer() every ghost so the builder trashes
 * them once the schema has been baked in.
 *
 *   $notify->thread     = $threadGhost;   // → notify.thread_id  (FK + index)
 *   $att->thread        = $threadGhost;   // → notifyattachment.thread_id
 *   $att->notify        = $notifyGhost;   // → notifyattachment.notify_id
 *
 * The FK column is named after the PROPERTY ('thread'), so it comes out as
 * `thread_id` (not `emailthread_id`) — matching the spec. Because the relation
 * is aliased, query a thread's messages explicitly rather than via ownNotifyList:
 *   Bean::find('message', 'thread_id = ? ORDER BY created_at ASC', [$id]).
 * Cascade delete lives in Model_Emailthread::delete().
 *
 * Ghosts are deferred parent-first (thread, notify, attachment); the builder
 * reverse-trashes them (attachment, notify, thread) so FK constraints hold.
 * Idempotent — fluid mode only adds what's missing; ghost rows never persist.
 */

use \RedBeanPHP\R;

// ---- emailthread ghost -----------------------------------------------------
$thread = R::dispense('thread');
$thread->subject         = str_repeat('x', 255);
$thread->reply_token     = str_repeat('x', 64);
$thread->related_type    = str_repeat('x', 64);
$thread->related_id      = 999999;
$thread->owner_member_id = 999999;   // polymorphic owner (may be 0) — plain int
$thread->recipient_email = str_repeat('x', 200);
$thread->recipient_name  = str_repeat('x', 150);
$thread->message_count   = 999999;
$thread->last_direction  = str_repeat('x', 8);
$thread->last_preview    = str_repeat('x', 500);
$thread->last_message_at = date('Y-m-d H:i:s');
$thread->status          = str_repeat('x', 16);
$thread->created_at      = date('Y-m-d H:i:s');
$thread->updated_at      = date('Y-m-d H:i:s');
R::store($thread);
$_defer($thread);

// ---- notify ghost (child of thread) ----------------------------------------
$notify = R::dispense('message');
$notify->thread          = $thread;              // → notify.thread_id (FK + idx)
$notify->direction       = str_repeat('x', 8);
$notify->notify_type     = str_repeat('x', 16);
$notify->from_email      = str_repeat('x', 200);
$notify->from_name       = str_repeat('x', 150);
$notify->to_email        = str_repeat('x', 200);
$notify->to_name         = str_repeat('x', 150);
$notify->subject         = str_repeat('x', 255);
$notify->content         = str_repeat('x', 16000);
$notify->body_plain      = str_repeat('x', 16000);
// message_eid, not message_id. This holds the RFC822 Message-ID -- a STRING minted
// by whoever sent the mail -- and the _id suffix is reserved for RedBean's integer
// foreign keys. Under the old `notify` name that was harmless, because no table
// called `message` existed for it to point at. Renaming the bean to `message` made
// it self-referential: fluid mode emitted message.message_id -> message.id, and
// storing a Message-ID string into it failed the constraint. See CLAUDE.md.
$notify->message_eid     = str_repeat('x', 250);
$notify->in_reply_to     = str_repeat('x', 250);
$notify->references_list = str_repeat('x', 4000);
$notify->provider_id     = str_repeat('x', 250);
$notify->status          = str_repeat('x', 16);
$notify->error_message   = str_repeat('x', 1000);
$notify->related_type    = str_repeat('x', 64);
$notify->related_id      = 999999;
$notify->ip              = '255.255.255.255';
$notify->sent_at         = date('Y-m-d H:i:s');
$notify->created_at      = date('Y-m-d H:i:s');
R::store($notify);
$_defer($notify);

// ---- notifyattachment ghost (child of thread + notify) ---------------------
$att = R::dispense('messageattachment');
$att->thread     = $thread;          // → notifyattachment.thread_id (FK + idx)
$att->notify     = $notify;          // → notifyattachment.notify_id (FK + idx)
$att->disk_path  = str_repeat('x', 500);
$att->filename   = str_repeat('x', 255);
$att->mime_type  = str_repeat('x', 128);
$att->size       = 999999999;
$att->created_at = date('Y-m-d H:i:s');
R::store($att);
$_defer($att);

/* ---- TEAM CHAT ------------------------------------------------------------
 *
 * Rooms, DMs and mentions were added on top of the email threads above, and their
 * schema was never seeded — every one of these columns and both of these tables were
 * created by RedBean's fluid mode on first use, at runtime, in whichever install
 * happened to touch the feature first.
 *
 * That is not a theoretical gap. A freshly provisioned instance has no `threadmember`
 * until somebody opens Communications, so anything that reads it first gets nothing
 * back — fluid mode answers a missing table with an empty result rather than an error.
 * The day the schema was frozen to stop typo'd columns, that silence became
 * `no such table: threadmember` on a live instance within minutes. Freezing is not an
 * option while a table can only appear by accident, so the tables are declared here.
 *
 * TYPES MATCH THE LIVE SCHEMA DELIBERATELY, including the inconsistent ones
 * (`mention.read_at` is TEXT while `mention.created_at` is NUMERIC — fluid mode
 * inferred each from whatever it was first handed). Declaring a different type here
 * would make RedBean WIDEN the column, and on SQLite a widen rebuilds the table. This
 * seed runs against installs that already hold real conversations; matching what is
 * there keeps it a no-op instead of a rebuild.
 */

// Parent ghosts for the real foreign keys below (threadmember.member_id -> member,
// mention.member_id -> member, thread.team_id -> team). Assigning a bare 999999 fails
// the constraint on any install that already enforces it; assigning a BEAN both
// satisfies the FK and is what makes RedBean emit the FK column in the first place.
// Deferred first so the builder's reverse-trash removes the children before them.
$memberGhost = R::dispense('member');
$memberGhost->email = 'schema-ghost@example.invalid';
R::store($memberGhost);
$_defer($memberGhost);

// team.name/slug/owner_id are NOT NULL, so the ghost has to satisfy them — a ghost
// that cannot be stored declares no schema at all.
$teamGhost = R::dispense('team');
$teamGhost->name     = 'schema-ghost';
$teamGhost->slug     = 'schema-ghost-' . bin2hex(random_bytes(4));
$teamGhost->owner_id = $memberGhost->id;
R::store($teamGhost);
$_defer($teamGhost);

// ---- chat-era columns on the thread ----------------------------------------
// A room/DM is a thread with a kind, an owning team and a slug; created_by is the
// member who opened it. connection_ref/external_ref are the plain-int + string
// pointers for a thread mirrored from an outside system (see CLAUDE.md on _ref/_eid).
$chatThread = R::dispense('thread');
$chatThread->kind           = str_repeat('x', 16);
$chatThread->team           = $teamGhost;   // → thread.team_id (real FK)
$chatThread->slug           = str_repeat('x', 190);
$chatThread->created_by     = 999999;
$chatThread->connection_ref = 999999;
$chatThread->external_ref   = str_repeat('x', 190);
R::store($chatThread);
$_defer($chatThread);

// ---- chat-era columns on a message -----------------------------------------
// sender_member_id is who typed it (email messages have none); transport says how it
// arrived; external_identity_ref points at the identity it came in through.
$chatMessage = R::dispense('message');
$chatMessage->thread                = $chatThread;
$chatMessage->sender_member_id      = 999999;
$chatMessage->transport             = str_repeat('x', 32);
$chatMessage->external_identity_ref = 999999;
R::store($chatMessage);
$_defer($chatMessage);

// ---- threadmember ghost — who is in a room, and how far they have read -----
$tmember = R::dispense('threadmember');
$tmember->thread       = $chatThread;        // → threadmember.thread_id (FK + idx)
$tmember->member       = $memberGhost;   // → threadmember.member_id (real FK)
$tmember->role         = str_repeat('x', 16);
$tmember->last_read_id = 999999;
$tmember->muted        = 1;
$tmember->joined_at    = date('Y-m-d H:i:s');
$tmember->read_at      = date('Y-m-d H:i:s');
R::store($tmember);
$_defer($tmember);

// ---- mention ghost ---------------------------------------------------------
// thread_ref / message_ref, NOT thread_id / message_id: these are plain integer
// pointers and a real FK would be wrong here, because a message can be hard-deleted
// while its mention row is still being cleaned up. See CLAUDE.md and lib/Mentions.php.
// A _ref column gets no automatic index — declare one in a migration if the unread
// count ever needs it.
$mention = R::dispense('mention');
$mention->thread_ref  = 999999;
$mention->message_ref = 999999;
$mention->member      = $memberGhost;   // → mention.member_id (real FK)
$mention->read_at     = str_repeat('x', 40);   // TEXT in the live schema, not a date
$mention->created_at  = date('Y-m-d H:i:s');
R::store($mention);
$_defer($mention);

// No hand-declared indexes: the schema is 100% bean-derived. RedBean's fluid
// mode already indexes every *_id column by convention (thread_id / notify_id
// as real FKs from the bean refs above; related_id / owner_member_id /
// message_id / provider_id as would-be FKs), which covers the routing-token
// lookup, owner scoping, and message-id matching.
//
// The one thing RedBean cannot express through beans is a UNIQUE constraint on
// a non-*_id column (reply_token). That guarantee is enforced in application
// code instead — NotifyService mints reply_token from random_bytes(16) (128
// bits) and re-rolls on the astronomically unlikely collision. See
// NotifyService::mintReplyToken().
