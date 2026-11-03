<?php
/** 59_YourAi.php — /yourai, a member's own AI (lib/YourAi.php): every signed-in member. */
echo '  authcontrol: yourai::* => ' . \app\PermissionCache::seedRule('yourai', '*', 100, 'Your AI: the AI a member connects once, for every project (members)') . "\n";
