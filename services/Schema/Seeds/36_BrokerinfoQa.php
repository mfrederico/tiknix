<?php
/**
 * 36_BrokerinfoQa.php — brokerinfo::qa: a project asks, with its own broker key, where its QA
 * Testing is (the runtime's `qa` pipeline step). PUBLIC at the route like the rest of
 * brokerinfo: the method authenticates the key itself.
 */

echo '  authcontrol: brokerinfo::qa => ' . \app\PermissionCache::seedRule('brokerinfo', 'qa', 101, "A project's pipeline asks where its QA Testing is (broker key)") . "\n";
