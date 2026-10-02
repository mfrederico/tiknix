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
// The model and support doors were seeded by the runtime's seed 02 into every app, where no
// Brokerinfo controller exists; they are the control plane's, and are seeded here.
echo '  authcontrol: brokerinfo::modelconnections => ' . \app\PermissionCache::seedRule('brokerinfo', 'modelconnections', 101, "The project owner's opted-in model connections, for pipeline agents (broker key)") . "\n";
echo '  authcontrol: brokerinfo::modelcall => ' . \app\PermissionCache::seedRule('brokerinfo', 'modelcall', 101, "Start a model call on the owner's connection; key stays in core (broker key)") . "\n";
echo '  authcontrol: brokerinfo::support => ' . \app\PermissionCache::seedRule('brokerinfo', 'support', 101, "A project's AI agent escalates to Tiknix support, member agreed (broker key)") . "\n";
echo '  authcontrol: brokerinfo::connectintent => ' . \app\PermissionCache::seedRule('brokerinfo', 'connectintent', 101, 'Instance-driven OAuth connect handoff (broker key)') . "\n";
echo '  authcontrol: brokerinfo::modelresult => ' . \app\PermissionCache::seedRule('brokerinfo', 'modelresult', 101, 'Poll a pipeline model call this project started (broker key)') . "\n";
echo '  authcontrol: concepthub::search => ' . \app\PermissionCache::seedRule('concepthub', 'search', 101, 'Concept catalog search (self-authenticating broker key)') . "\n";
echo '  authcontrol: concepthub::get => ' . \app\PermissionCache::seedRule('concepthub', 'get', 101, 'Concept catalog detail (broker key)') . "\n";
echo '  authcontrol: concepthub::bundle => ' . \app\PermissionCache::seedRule('concepthub', 'bundle', 101, 'Concept catalog download (broker key)') . "\n";
echo '  authcontrol: concepthub::install => ' . \app\PermissionCache::seedRule('concepthub', 'install', 101, 'Queue a concept install into the calling instance (broker key; POST)') . "\n";
echo '  authcontrol: social::* => ' . \app\PermissionCache::seedRule('social', '*', 101, 'Public social showcase front controller') . "\n";
echo '  authcontrol: billing::usage => ' . \app\PermissionCache::seedRule('billing', 'usage', 101, 'Billing service usage pull (Bearer callback_key)') . "\n";
echo '  authcontrol: billing::index => ' . \app\PermissionCache::seedRule('billing', 'index', 100, 'Billing page — projects counted, plan, invoices') . "\n";

// Brokerinfo's doors from when an app's credentials lived on core (lookup, connector list,
// pasted-key connect, disconnect) and the one that handed a credential to a sidecar are removed
// from the controller. Their rows go with them — whoever wrote the row (these were set by hand
// in July 2026, by no seed), a rule for a method that does not exist guards nothing. A method
// that is back is left alone.
foreach (['connections', 'connectors', 'connectkey', 'disconnect', 'connectiontoken'] as $__m) {
    if (method_exists(\app\Brokerinfo::class, $__m)) continue;
    $__row = \app\Bean::findOne('authcontrol', 'control = ? AND method = ?', ['brokerinfo', $__m]);
    if (!$__row || !$__row->id) continue;
    \app\Bean::trash($__row);
    echo "  authcontrol: removed brokerinfo::{$__m} (the method is gone)\n";
}

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
