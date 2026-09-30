<?php
/**
 * 28_DomainRoutes.php — the Domains card on Connections (Deploy): a project in its own
 * container answers on other domains, each a site of its own (lib/TenantDomains.php).
 * MEMBER level: the controller lets anyone on the project read the list and only the
 * project's owner add or remove (Connections::tenantProject).
 */

foreach ([
    ['domains',      "Domains: a container project's addresses and certificates (anyone on the project)"],
    ['domainadd',    'Domains: point another domain at the project as its own site (owner)'],
    ['domainremove', 'Domains: stop serving a domain; its data stays in the app (owner)'],
] as [$method, $desc]) {
    echo "  authcontrol: connections::{$method} => " . \app\PermissionCache::seedRule('connections', $method, 100, $desc) . "\n";
}
