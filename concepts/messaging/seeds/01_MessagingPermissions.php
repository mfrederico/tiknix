<?php
/**
 * 01_MessagingPermissions.php — the admin pages (messaging) are for admins, except the two an audience's owner uses (compose, message),
 * which are for members and refuse anyone the audiences provider says may not message that audience. The preferences page (msgpref)
 * is for members; its old unsubscribe address and the old tracking addresses (msgtrack) stay PUBLIC for mail sent before the service:
 * they do what they did, through the service. New mail carries the service's own addresses, and the Mailgun webhook is the service's.
 * seedRule() corrects a row the framework invented at the ADMIN default and never overrules one a person set; each result is printed
 * ('kept' means somebody's deliberate rule won).
 */
use app\PermissionCache;

foreach ([
    ['messaging', '*', 50, 'Messages across audiences, the sending cap, each site\'s words and the suppression list (admins)'],
    ['messaging', 'compose', 100, 'Write to an audience you own (members; the audiences provider decides who may)'],
    ['messaging', 'message', 100, 'One message to an audience you own (members; the audiences provider decides who may)'],
    ['msgpref', '*', 100, 'What reaches you: a member\'s choices (members)'],
    ['msgpref', 'unsubscribe', 101, 'An unsubscribe link from before the service: this app\'s signed token, the switch turned off in the service (public)'],
    ['msgtrack', '*', 101, 'Links and open pixel in mail sent before the service: one hop to the service (public)'],
] as [$control, $method, $level, $why]) {
    echo '  ' . PermissionCache::seedRule($control, $method, $level, $why) . " {$control}::{$method}\n";
}
