<?php
/**
 * @mentions — "this one is for you", inside a conversation you may not be reading.
 *
 * Unread already tells you a room has new messages. In a busy room that stops meaning
 * anything, which is exactly when someone needs to get your attention specifically. A
 * mention is that: a durable record that a particular message named a particular person.
 *
 * Stored rather than derived, for two reasons. It has its own read state (you can catch
 * up on a room without clearing the fact that you were asked something), and searching
 * every message body for every name on every page load is not a thing anyone should do.
 *
 * Only people who can already SEE the message can be mentioned in it. Mentioning someone
 * outside a conversation would otherwise be a way to ping anyone in the system, and the
 * team-scope rules exist precisely to prevent that.
 */

namespace app;

use \app\Bean;
use \RedBeanPHP\R as R;

class Mentions {

    /** Usernames are the handle. Kept deliberately narrow so parsing is unambiguous. */
    private const HANDLE = '[A-Za-z0-9][A-Za-z0-9_.-]{1,39}';

    /**
     * Names written in a body, lowercased and deduped. Text only — anything inside an
     * HTML tag is skipped, so an email address in an href never reads as a mention.
     */
    public static function parse(string $html): array {
        // Drop tags entirely rather than trying to match "@ but not inside <...>": the
        // negative form is the kind of regex that works until someone writes a quoted
        // attribute containing a bracket.
        $text = preg_replace('/<[^>]*>/', ' ', $html);
        $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');

        // An @ must start a word — "user@example.com" is an address, not a mention.
        if (!preg_match_all('/(?<![A-Za-z0-9._%+-])@(' . self::HANDLE . ')/u', $text, $m)) {
            return [];
        }
        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** Load member rows by id, as beans. Kept in one place so the id-list binding is too. */
    private static function membersByIds(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        // genSlots builds the "?,?,?" list; array_values keeps the binding positional,
        // because an id-KEYED array maps its keys to parameter positions. See CLAUDE.md.
        return array_values(Bean::find('member', 'id IN (' . R::genSlots($ids) . ')', $ids));
    }

    /** The handles a member answers to: their username, and their display name slugged. */
    private static function handlesOf(object $m): array {
        $out = [];
        $u = strtolower(trim((string) ($m->username ?? '')));
        if ($u !== '') $out[] = $u;

        $d = strtolower(trim((string) ($m->displayName ?? '')));
        if ($d !== '') {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $d), '-');
            if ($slug !== '') $out[] = $slug;
        }
        return array_values(array_unique($out));
    }

    /**
     * Record who a message named.
     *
     * Candidates are the thread's participants, so a name that is not in the conversation
     * simply does not resolve — no error, no notification, nothing leaked about whether
     * that account exists.
     *
     * @return int[] member ids actually mentioned
     */
    public static function record(int $threadId, int $messageId, int $senderId, string $html): array {
        $handles = self::parse($html);
        if (!$handles) return [];

        $hit = [];
        foreach (self::membersByIds(ThreadMembers::participants($threadId)) as $m) {
            $mid = (int) $m->id;
            if ($mid === $senderId) continue;          // naming yourself is not a mention
            if (array_intersect(self::handlesOf($m), $handles)) $hit[] = $mid;
        }
        $hit = array_values(array_unique($hit));

        foreach ($hit as $mid) {
            $row = Bean::dispense('mention');
            $row->threadRef  = $threadId;     // not *_id: RedBean reads that suffix as an FK
            $row->messageRef = $messageId;
            $row->memberId   = $mid;
            $row->readAt     = '';
            $row->createdAt  = date('Y-m-d H:i:s');
            Bean::store($row);
        }
        return $hit;
    }

    /** Unread mentions for a person, across everything. */
    public static function unreadCount(int $memberId): int {
        if ($memberId <= 0) return 0;
        return (int) R::getCell(
            'SELECT COUNT(*) FROM mention WHERE member_id = ? AND (read_at IS NULL OR read_at = ?)',
            [$memberId, '']
        );
    }

    /** Unread mentions for a person in one thread — drives the @ badge on a rail row. */
    public static function unreadInThread(int $threadId, int $memberId): int {
        if ($threadId <= 0 || $memberId <= 0) return 0;
        return (int) R::getCell(
            'SELECT COUNT(*) FROM mention WHERE thread_ref = ? AND member_id = ? AND (read_at IS NULL OR read_at = ?)',
            [$threadId, $memberId, '']
        );
    }

    /** Thread ids where this person has an unread mention. */
    public static function threadsWithUnread(int $memberId): array {
        if ($memberId <= 0) return [];
        return array_values(array_map('intval', R::getCol(
            'SELECT DISTINCT thread_ref FROM mention WHERE member_id = ? AND (read_at IS NULL OR read_at = ?)',
            [$memberId, '']
        )));
    }

    /** Reading the thread clears its mentions for that person. */
    public static function markRead(int $threadId, int $memberId): int {
        if ($threadId <= 0 || $memberId <= 0) return 0;
        $n = 0;
        foreach (Bean::find('mention', 'thread_ref = ? AND member_id = ?', [$threadId, $memberId]) as $r) {
            if (!empty($r->readAt)) continue;
            $r->readAt = date('Y-m-d H:i:s');
            Bean::store($r);
            $n++;
        }
        return $n;
    }

    /**
     * Wrap resolved mentions in markup so they are visible as mentions when read.
     *
     * Only names that actually resolved are highlighted — highlighting every @word would
     * make an unmatched name look like it had reached somebody.
     */
    public static function highlight(string $html, array $memberIds, int $viewerId = 0): string {
        foreach (self::membersByIds($memberIds) as $m) {
            $class = ((int) $m->id === $viewerId) ? 'comms-mention comms-mention-me' : 'comms-mention';
            foreach (self::handlesOf($m) as $name) {
                $html = (string) preg_replace(
                    '/(?<![A-Za-z0-9._%+-])@' . preg_quote($name, '/') . '\b/i',
                    '<span class="' . $class . '">@' . htmlspecialchars($name, ENT_QUOTES) . '</span>',
                    $html
                );
            }
        }
        return $html;
    }
}
