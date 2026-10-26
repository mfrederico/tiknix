<?php
/**
 * 57_BrokerinfoMessaging.php — brokerinfo::messaging: a project asks, with its own broker key,
 * where the messaging service is (its app's `clitool --connection-share … --with=messaging`).
 * PUBLIC (101) like the other brokerinfo routes: the method authenticates the key itself.
 */
echo '  authcontrol: brokerinfo::messaging => ' . \app\PermissionCache::seedRule('brokerinfo', 'messaging', 101, 'Where the messaging service is, for a project (self-authenticating: broker key)') . "\n";
