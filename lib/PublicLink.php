<?php
/**
 * PublicLink — resolve a comms reply token to its thread for no-login pages.
 *
 * The comms subsystem owns the *channel + token*; a consuming project owns the
 * *page*. Any controller can expose a token-gated, no-login view tied to a
 * thread/entity by resolving the token here, then rendering its own page and
 * (optionally) posting back into the thread via
 * NotifyService::createSystemMessage() or an outbound send.
 *
 * Comms imposes no TTL — expiry, if a consumer needs it, is the consumer's
 * concern (e.g. compare against a domain deadline on the related entity).
 *
 * Usage:
 *   $thread = \app\PublicLink::resolveThread($token);
 *   if (!$thread) { Flight::notFound(); return; }
 *   $order = $thread->poly('relatedType')->related;   // polymorphic context
 *   // ...render the consumer's own token-gated page...
 */

namespace app;

use \app\Bean;

class PublicLink {

    /**
     * Look up an emailthread by its reply token.
     *
     * @param string $token The reply token (32+ hex chars from the reply-{token}@ address).
     * @return object|null The emailthread bean, or null if the token is unknown/malformed.
     */
    public static function resolveThread(string $token): ?object {
        $token = strtolower(trim($token));
        if ($token === '' || !preg_match('/^[a-f0-9]{32,}$/', $token)) {
            return null;
        }
        $thread = Bean::findOne('emailthread', 'reply_token = ?', [$token]);
        return ($thread && $thread->id) ? $thread : null;
    }
}
