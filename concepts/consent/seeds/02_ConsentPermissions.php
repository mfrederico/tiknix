<?php
/**
 * 02_ConsentPermissions.php — the owner's page and its actions are for admins; what every visitor's browser needs
 * (the script, the answer, the report, the page of what is used) is public.
 */

use app\PermissionCache;

foreach ([
    ['*', 50, 'Cookie consent: the list and its settings (admins)'],
    ['js', 101, 'Cookie consent: the page script (public)'],
    ['choose', 101, 'Cookie consent: a visitor\'s answer (public; checked by its Origin)'],
    ['scanresult', 101, 'Cookie consent: the platform posts what its page scan saw (public; checked by the app\'s broker key)'],
    ['report', 101, 'Cookie consent: what a browser reports a page tried to load (public)'],
    ['cookies', 101, 'Cookie consent: what this site uses, and your choices (public)'],
] as [$method, $level, $note]) {
    echo "  consent::{$method}  " . PermissionCache::seedRule('consent', $method, $level, $note) . "\n";
}
