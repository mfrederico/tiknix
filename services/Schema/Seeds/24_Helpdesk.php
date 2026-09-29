<?php
/**
 * 24_Helpdesk.php — the member support desk (controls/Helpdesk.php) split out of Contact in
 * the runtime split (RUNTIME-SPLIT-MAP.md step 2): /helpdesk and /helpdesk/ask are for
 * signed-in members, as /contact/ask was. Control plane only (this seed is core's).
 */
echo '  authcontrol: helpdesk::* => ' . \app\PermissionCache::seedRule('helpdesk', '*', 100, 'Member support desk (signed-in members)') . "\n";
