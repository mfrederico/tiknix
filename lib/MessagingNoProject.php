<?php
/**
 * MessagingNoProject — Tiknix's own trigger for the messaging plugin (concepts/messaging):
 * a member who signed up $days or more days ago and still owns no project. The nudge says
 * how the first one starts. Registered from lib/controlplane.php as trigger code
 * `no_project_days`; the plugin calls qualifies() for every active member each hour and
 * sends once per member (the ledger), only while the campaign is active.
 */
namespace app;

class MessagingNoProject {

    public static function label(): string { return 'Signed up this many days ago and has no project yet'; }

    /** Signed up at least $days days ago, a member (not staff), and owns no active project. */
    public static function qualifies(int $memberId, int $days): bool {
        $m = Bean::load('member', $memberId);
        if (!$m->id || (int) $m->level < LEVELS['MEMBER']) return false;   // staff are not nudged
        $since = strtotime((string) $m->createdAt);
        if ($since === false || $since > time() - $days * 86400) return false;
        return (int) Bean::getCell("SELECT COUNT(*) FROM instance WHERE member_id = ? AND status = 'active'", [$memberId]) === 0;
    }

    /** What the nudge carries: the one step that starts a project, in the member's words. */
    public static function content(int $memberId): ?array {
        $url = rtrim((string) \Flight::get('app.baseurl'), '/') . '/projects';
        $html = '<p>Your first project starts with one sentence about what you want built — a booking page, a stock list, a member site. '
              . 'Tiknix plans it, builds it, checks it in a browser and gives you the address. '
              . 'The first project is free.</p><p><a href="' . htmlspecialchars($url) . '">Start a project</a></p>';
        $text = "Your first project starts with one sentence about what you want built. Tiknix plans it, builds it, checks it and gives you the address. The first project is free.\n\nStart one: {$url}";
        return ['headline' => 'Your first app is one sentence away', 'html' => $html, 'text' => $text];
    }
}
