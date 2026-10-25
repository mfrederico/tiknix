<?php
/**
 * 02_MessagingPermissions.php — the admin pages (messaging) are for admins, except the two an audience's owner uses (compose, message),
 * which are for members and refuse anyone the audiences provider says may not message that audience. The tracking links and the pixel
 * (msgtrack) and the unsubscribe page (msgpref::unsubscribe) are PUBLIC: they are reached from a message. The preferences page is
 * for members. seedRule() corrects a row the framework invented at the ADMIN default and never overrules one a person set; each
 * result is printed ('kept' means somebody's deliberate rule won).
 */
use app\PermissionCache;

foreach ([
    ['messaging', '*', 50, 'Messages across audiences, the sending cap, webhook key and suppression list (admins)'],
    ['messaging', 'compose', 100, 'Write to an audience you own (members; the audiences provider decides who may)'],
    ['messaging', 'message', 100, 'One message to an audience you own (members; the audiences provider decides who may)'],
    ['msgtrack', '*', 101, 'Links and open pixel in a message (public: reached from an email)'],
    ['msgpref', '*', 100, 'What reaches you: a member\'s choices (members)'],
    ['msgpref', 'unsubscribe', 101, 'Unsubscribe from a message (signed link, no sign-in)'],
] as [$control, $method, $level, $why]) {
    echo '  ' . PermissionCache::seedRule($control, $method, $level, $why) . " {$control}::{$method}\n";
}
