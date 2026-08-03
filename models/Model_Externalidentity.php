<?php
/**
 * External identity FUSE model — somebody who reaches you from a connected
 * platform without a tiknix account.
 *
 * A handle, not a person and not an account: one row is "this Telegram user id,
 * in this connection". The same human on Telegram and Slack is two rows, and
 * once they sign up both point at the one member — which is the case a shadow
 * member row cannot express without inventing two accounts for one person.
 *
 * Deliberately NOT in the `member` table. 31 of the 35 member lookups in this
 * codebase do not filter on status and two of them resolve credentials, so a row
 * that must never authenticate has no business living there. Keeping it separate
 * means that is true by construction rather than by remembering a WHERE clause.
 *
 * Precedence, which is the whole question this design had to answer: while
 * member_ref is null the handle speaks for itself, and the moment it is linked
 * the ACCOUNT wins for name and permissions. The handle stays as the record of
 * how that person reached you, so history keeps making sense.
 */

class Model_Externalidentity extends \RedBeanPHP\SimpleModel {

    /**
     * How many distinct handles one connection may accumulate.
     *
     * A public Telegram group is joinable by anyone, so "somebody who can post in
     * a channel you connected" is not a small set. Rows are tiny — you would need
     * millions to trouble a disk — but an unbounded table that grows from the
     * outside should still have a number on it, and the number should be visible
     * rather than discovered during an incident.
     *
     * Overridable per install: [integrations] max_identities_per_connection.
     */
    public const MAX_PER_CONNECTION = 5000;

    public static function maxPerConnection(): int {
        $n = (int) \Flight::get('integrations.max_identities_per_connection');
        return $n > 0 ? $n : self::MAX_PER_CONNECTION;
    }

    // ---- lookup ----------------------------------------------------------------------

    /**
     * Find the handle for a platform user on a connection, creating it the first
     * time that person says anything.
     *
     * @param array $profile display_name, external_handle, avatar_url — refreshed
     *                       when the platform reports a change, since people rename
     *                       themselves and the stale name is the one in your inbox.
     * @return \RedBeanPHP\OODBBean|null null when the connection is at its cap
     */
    public static function resolve(int $connectionRef, string $externalUserId, array $profile = []): ?\RedBeanPHP\OODBBean {
        $externalUserId = trim($externalUserId);
        if ($connectionRef <= 0 || $externalUserId === '') return null;

        $found = self::find($connectionRef, $externalUserId);
        if ($found) {
            $found->box()->refresh($profile);
            return $found;
        }

        $count = \app\Bean::count('externalidentity', 'connection_ref = ?', [$connectionRef]);
        if ($count >= self::maxPerConnection()) {
            // Loud. Silently dropping people is how a channel quietly stops working
            // and nobody can say when it started.
            \Flight::get('log')?->error('External identity cap reached; handle not created', [
                'connection_ref' => $connectionRef,
                'count'          => $count,
                'cap'            => self::maxPerConnection(),
            ]);
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $row = \app\Bean::dispense('externalidentity');
        $row->connectionRef   = $connectionRef;
        $row->externalUserId  = $externalUserId;
        $row->externalHandle  = (string) ($profile['external_handle'] ?? '');
        $row->displayName     = (string) ($profile['display_name'] ?? '');
        $row->avatarUrl       = (string) ($profile['avatar_url'] ?? '');
        $row->memberRef       = null;
        $row->messageCount    = 0;
        $row->firstSeenAt     = $now;
        $row->lastSeenAt      = $now;
        $row->createdAt       = $now;
        $row->updatedAt       = $now;

        try {
            \app\Bean::store($row);
        } catch (\Throwable $e) {
            // idx_extid_unique. Two webhook retries can arrive at once, both miss
            // above, and both insert; the database refuses the second. That is the
            // index doing its job, so re-read rather than fail the message.
            $again = self::find($connectionRef, $externalUserId);
            if ($again) return $again;
            throw $e;
        }

        return $row;
    }

    private static function find(int $connectionRef, string $externalUserId): ?\RedBeanPHP\OODBBean {
        $row = \app\Bean::findOne('externalidentity',
            'connection_ref = ? AND external_user_id = ?', [$connectionRef, $externalUserId]);
        return ($row && $row->id) ? $row : null;
    }

    // ---- identity --------------------------------------------------------------------

    /** The linked account, or null while this handle belongs to nobody. */
    public function member(): ?\RedBeanPHP\OODBBean {
        $ref = (int) ($this->bean->memberRef ?? 0);
        if ($ref <= 0) return null;
        $m = \app\Bean::load('member', $ref);
        return $m->id ? $m : null;
    }

    public function isLinked(): bool {
        return $this->member() !== null;
    }

    /**
     * The name to show.
     *
     * The ACCOUNT wins once linked — that is the precedence rule, in one place, so
     * a person who signs up stops appearing under an old platform nickname
     * everywhere at once. Model_Member::displayName() owns the rest of the chain.
     */
    public function displayName(string $fallback = 'Someone'): string {
        $member = $this->member();
        if ($member) return $member->displayName($fallback);

        $named = fn(string $s) => $s !== '' && preg_match('/\p{L}/u', $s);

        $name = trim((string) ($this->bean->displayName ?? ''));
        if ($named($name)) return $name;

        $handle = trim((string) ($this->bean->externalHandle ?? ''));
        if ($named($handle)) return '@' . ltrim($handle, '@');

        return $fallback;
    }

    /**
     * Attach this handle to an account.
     *
     * The conversion the whole design exists for: one column, rather than
     * rewriting the 26 tables that carry a member id. Everything this handle ever
     * said keeps pointing at the handle and starts reading as the member.
     */
    public function linkTo(int $memberId): bool {
        if ($memberId <= 0) return false;
        $m = \app\Bean::load('member', $memberId);
        if (!$m->id) return false;

        $this->bean->memberRef = $memberId;
        $this->bean->updatedAt = date('Y-m-d H:i:s');
        \app\Bean::store($this->bean);
        return true;
    }

    public function unlink(): void {
        $this->bean->memberRef = null;
        $this->bean->updatedAt = date('Y-m-d H:i:s');
        \app\Bean::store($this->bean);
    }

    /** Muted at the handle, which needs no account to act on. */
    public function isBlocked(): bool {
        return !empty($this->bean->blockedAt);
    }

    // ---- activity --------------------------------------------------------------------

    /** Note that this handle just spoke. Drives both the roster and the purge. */
    public function touch(): void {
        $this->bean->lastSeenAt   = date('Y-m-d H:i:s');
        $this->bean->messageCount = (int) $this->bean->messageCount + 1;
        \app\Bean::store($this->bean);
    }

    /** Refresh the profile when the platform reports a different one. */
    public function refresh(array $profile): void {
        $changed = false;
        foreach ([
            'displayName'    => 'display_name',
            'externalHandle' => 'external_handle',
            'avatarUrl'      => 'avatar_url',
        ] as $prop => $key) {
            $new = trim((string) ($profile[$key] ?? ''));
            if ($new !== '' && $new !== (string) $this->bean->$prop) {
                $this->bean->$prop = $new;
                $changed = true;
            }
        }
        if ($changed) {
            $this->bean->updatedAt = date('Y-m-d H:i:s');
            \app\Bean::store($this->bean);
        }
    }

    /**
     * Forget handles that never became anybody and have not been seen for a while.
     *
     * Linked handles are never purged: they are part of an account's history. This
     * is the answer to a channel that fills up with drive-by senders — the rows are
     * cheap, but they should not be permanent.
     *
     * @return int how many were removed
     */
    public static function purgeIdle(int $connectionRef, int $days = 90): int {
        if ($connectionRef <= 0 || $days < 1) return 0;
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

        $stale = \app\Bean::find('externalidentity',
            'connection_ref = ? AND member_ref IS NULL AND last_seen_at < ? AND message_count = 0',
            [$connectionRef, $cutoff]);

        $n = count($stale);
        if ($n) \app\Bean::trashAll($stale);
        return $n;
    }
}
