<?php
/**
 * 25_ControlPlaneRoutes.php — permission rows for the control plane's own controllers.
 * They lived in the runtime's seed 02 until the runtime became a package
 * (RUNTIME-SPLIT-MAP.md step 3): an app built on the runtime has none of these
 * controllers, so it must not carry their rows either. Each through seedRule(), which
 * corrects a row the framework invented and keeps one a person set.
 */
echo '  authcontrol: pricing::* => ' . \app\PermissionCache::seedRule('pricing', '*', 101, 'Public marketing pricing page (flagship-gated in-controller)') . "\n";
echo '  authcontrol: stories::* => ' . \app\PermissionCache::seedRule('stories', '*', 101, 'Public founder stories page (flagship-gated in-controller)') . "\n";
echo '  authcontrol: neosaas::* => ' . \app\PermissionCache::seedRule('neosaas', '*', 101, 'Public NeoSaaS manifesto page (flagship-gated in-controller)') . "\n";
echo '  authcontrol: about::* => ' . \app\PermissionCache::seedRule('about', '*', 101, 'Public Who-we-are page (flagship-gated in-controller)') . "\n";
echo '  authcontrol: sidecar::* => ' . \app\PermissionCache::seedRule('sidecar', '*', 100, 'Sidecar plugin launcher (per-plugin feature-gated)') . "\n";
echo '  authcontrol: brokerinfo::connections => ' . \app\PermissionCache::seedRule('brokerinfo', 'connections', 101, 'Instance connection lookup (self-authenticating broker key)') . "\n";
echo '  authcontrol: brokerinfo::connectors => ' . \app\PermissionCache::seedRule('brokerinfo', 'connectors', 101, 'Available connectors for the instance connect flow (broker key)') . "\n";
echo '  authcontrol: brokerinfo::connectkey => ' . \app\PermissionCache::seedRule('brokerinfo', 'connectkey', 101, 'Instance-driven api_key connect (broker key)') . "\n";
echo '  authcontrol: brokerinfo::disconnect => ' . \app\PermissionCache::seedRule('brokerinfo', 'disconnect', 101, 'Instance-driven disconnect (broker key)') . "\n";
echo '  authcontrol: brokerinfo::connectintent => ' . \app\PermissionCache::seedRule('brokerinfo', 'connectintent', 101, 'Instance-driven OAuth connect handoff (broker key)') . "\n";
echo '  authcontrol: brokerinfo::modelresult => ' . \app\PermissionCache::seedRule('brokerinfo', 'modelresult', 101, 'Poll a pipeline model call this project started (broker key)') . "\n";
echo '  authcontrol: concepthub::search => ' . \app\PermissionCache::seedRule('concepthub', 'search', 101, 'Concept catalog search (self-authenticating broker key)') . "\n";
echo '  authcontrol: concepthub::get => ' . \app\PermissionCache::seedRule('concepthub', 'get', 101, 'Concept catalog detail (broker key)') . "\n";
echo '  authcontrol: concepthub::bundle => ' . \app\PermissionCache::seedRule('concepthub', 'bundle', 101, 'Concept catalog download (broker key)') . "\n";
echo '  authcontrol: concepthub::install => ' . \app\PermissionCache::seedRule('concepthub', 'install', 101, 'Queue a concept install into the calling instance (broker key; POST)') . "\n";
echo '  authcontrol: social::* => ' . \app\PermissionCache::seedRule('social', '*', 101, 'Public social showcase front controller') . "\n";
echo '  authcontrol: billing::usage => ' . \app\PermissionCache::seedRule('billing', 'usage', 101, 'Billing service usage pull (Bearer callback_key)') . "\n";
echo '  authcontrol: billing::index => ' . \app\PermissionCache::seedRule('billing', 'index', 100, 'Billing page — projects counted, plan, invoices') . "\n";

// Core's Connections and Integrations are its own (recorded overrides): the builder's hub,
// where MEMBERS connect services for their projects. The runtime seeds these routes at 50
// for an app's admin-only pages; here they are moved to 100 — only when the row still says
// what the runtime's seed wrote, so a level a person set on this install is left alone.
foreach ([
    ['connections', 'index', 'Connections: what this app is connected to (admins)', 'Integrations hub (owner-scoped)'],
    ['integrations', 'index', 'Integrations: what this app automates (admins)', 'Integrations hub: automations for your projects (members)'],
] as [$__c, $__m, $__runtimeDesc, $__coreDesc]) {
    $__row = \app\Bean::findOne('authcontrol', 'control = ? AND method = ?', [$__c, $__m]);
    if (!$__row || !$__row->id) { echo "  authcontrol: {$__c}::{$__m} missing — seed 02 should have written it\n"; continue; }
    if ((int) $__row->level === 100) continue;
    if ((string) $__row->description !== $__runtimeDesc) { echo "  authcontrol: {$__c}::{$__m} is at {$__row->level}, set by someone — left alone (the hub wants 100)\n"; continue; }
    $__row->level = 100; $__row->description = $__coreDesc; $__row->updatedAt = date('Y-m-d H:i:s');
    \app\Bean::store($__row);
    echo "  authcontrol: {$__c}::{$__m} => 100 (the control plane's hub is for members)\n";
}
\app\PermissionCache::clear();
