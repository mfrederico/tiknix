<?php
/**
 * 46_BrokerinfoPlan.php — brokerinfo::plan: a project's terminal agent hands a plan to the
 * Builder (app\TerminalPlan). PUBLIC at the route like the rest of brokerinfo: the method
 * authenticates the app's broker key itself.
 */
echo '  authcontrol: brokerinfo::plan => ' . \app\PermissionCache::seedRule('brokerinfo', 'plan', 101, "A project's terminal hands a plan to the Builder as a draft (broker key)") . "\n";
