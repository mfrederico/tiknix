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
 *   Bean::find('notify', 'thread_id = ? ORDER BY created_at ASC', [$id]).
 * Cascade delete lives in Model_Emailthread::delete().
 *
 * Ghosts are deferred parent-first (thread, notify, attachment); the builder
 * reverse-trashes them (attachment, notify, thread) so FK constraints hold.
 * Idempotent — fluid mode only adds what's missing; ghost rows never persist.
 */

use \RedBeanPHP\R;

// ---- emailthread ghost -----------------------------------------------------
$thread = R::dispense('emailthread');
$thread->subject         = str_repeat('x', 255);
$thread->reply_token     = str_repeat('x', 64);
$thread->related_type    = str_repeat('x', 64);
$thread->related_id      = 999999;
$thread->owner_member_id = 999999;   // polymorphic owner (may be 0) — plain int
$thread->recipient_email = str_repeat('x', 200);
$thread->recipient_name  = str_repeat('x', 150);
$thread->message_count   = 999999;
$thread->unread_count    = 999999;
$thread->last_direction  = str_repeat('x', 8);
$thread->last_preview    = str_repeat('x', 500);
$thread->last_message_at = date('Y-m-d H:i:s');
$thread->status          = str_repeat('x', 16);
$thread->created_at      = date('Y-m-d H:i:s');
$thread->updated_at      = date('Y-m-d H:i:s');
R::store($thread);
$_defer($thread);

// ---- notify ghost (child of thread) ----------------------------------------
$notify = R::dispense('notify');
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
$notify->message_id      = str_repeat('x', 250);
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
$att = R::dispense('notifyattachment');
$att->thread     = $thread;          // → notifyattachment.thread_id (FK + idx)
$att->notify     = $notify;          // → notifyattachment.notify_id (FK + idx)
$att->disk_path  = str_repeat('x', 500);
$att->filename   = str_repeat('x', 255);
$att->mime_type  = str_repeat('x', 128);
$att->size       = 999999999;
$att->created_at = date('Y-m-d H:i:s');
R::store($att);
$_defer($att);

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
