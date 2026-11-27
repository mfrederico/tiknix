<?php
/** 64_AgenciesRoute.php — /agencies, "Tiknix for agencies" (controls/Agencies.php), public since the front page became the done-for-you offer (2026-11-27). */
echo '  authcontrol: agencies::* => ' . \app\PermissionCache::seedRule('agencies', '*', 101, 'Tiknix for agencies, the former front page (public)') . "\n";
