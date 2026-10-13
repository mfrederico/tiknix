<?php
/**
 * 47_PageUse.php — /usage: the pruning report (app\PageUse), for Tiknix's admins. It only reads.
 */
echo '  authcontrol: usage::* => ' . \app\PermissionCache::seedRule('usage', '*', 50, 'What is used: pages and rules nobody opens (admins)') . "\n";
