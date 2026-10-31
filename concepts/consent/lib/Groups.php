<?php
/**
 * Groups — the kinds of outside service a visitor says yes or no to, in the words the banner uses.
 * "necessary" is never asked: the site does not work without it (its own storage, video, payments, security check).
 */

namespace app\concepts\consent;

class Groups {

    public const NECESSARY = 'necessary';

    /** key => [name, one line a visitor reads, asked?] — in the order the banner shows them */
    public const ALL = [
        'necessary'   => ['Needed', 'Keeps the site working: signing in, your uploads, payments, the check that you are not a robot.', false],
        'preferences' => ['Your settings', 'Remembers choices you make, like your language.', true],
        'analytics'   => ['Statistics', 'Counts visits so we can see what helps and what does not.', true],
        'media'       => ['Videos, maps and widgets', 'Shows things from other sites inside ours, like a video or a map.', true],
        'marketing'   => ['Ads', 'Lets ad companies follow your visit here and show you ads elsewhere.', true],
    ];

    /** The groups a visitor is asked about. @return string[] */
    public static function asked(): array {
        return array_keys(array_filter(self::ALL, fn($g) => $g[2]));
    }

    public static function exists(string $key): bool { return isset(self::ALL[$key]); }

    public static function name(string $key): string {
        if (!isset(self::ALL[$key])) throw new \InvalidArgumentException("no consent group called '{$key}' (" . implode(', ', array_keys(self::ALL)) . ')');
        return self::ALL[$key][0];
    }

    public static function line(string $key): string { self::name($key); return self::ALL[$key][1]; }
}
