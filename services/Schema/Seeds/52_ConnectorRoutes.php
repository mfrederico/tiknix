<?php
/**
 * 52_ConnectorRoutes.php — which project connected which company of a provider whose app is the
 * PLATFORM's (QuickBooks): core does the sign-in and pushes the connection into the project
 * (ConnectorPush), and records here only WHERE it went — never a token. It is what routes a
 * webhook (Intuit names the company, not the project) and what limits renewals to the companies a
 * project really connected (app\ConnectorRoutes).
 *
 *   connectorroute   instance_ref (the project; _ref, a project is hard-deleted), connector,
 *                    environment, company_eid (the provider's id: Intuit's realmId), remote_ref
 *                    (the connection's id IN the project), created_at, renew_window (the hour it is
 *                    counting renewals in), renew_count
 */

use \RedBeanPHP\R;

// Padded sample, deferred so the build trashes it; dates as text (see 01_Member.php).
if (!$_tableCheck('connectorroute')) {
    $r = R::dispense('connectorroute');
    $r->instance_ref = 999999999;
    $r->connector    = str_repeat('x', 40);
    $r->environment  = str_repeat('x', 20);
    $r->company_eid  = str_repeat('x', 64);
    $r->remote_ref   = 999999999;
    $r->created_at   = str_repeat('x', 19);
    $r->renew_window = str_repeat('x', 19);
    $r->renew_count  = 999999999;
    R::store($r);
    $_defer($r);
    echo "  connectorroute: created\n";
}
R::exec('CREATE INDEX IF NOT EXISTS connectorroute_company ON connectorroute (connector, company_eid)');
R::exec('CREATE INDEX IF NOT EXISTS connectorroute_instance ON connectorroute (instance_ref, connector)');
echo '  authcontrol: webhook::quickbooks => ' . \app\PermissionCache::seedRule('webhook', 'quickbooks', 101, 'Intuit\'s QuickBooks change notices (verified by their signature), routed to the project that connected each company') . "\n";
