<?php
/**
 * 22_ConnectionBindings.php — `connectionbinding`: which connection fills a concept's role, per
 * site (lib/ConnectionBindings.php; CONNECTOR-CATALOG-PLAN.md §3.4). Configuration, not secrets: it
 * holds the connection's id in this install's connection store and the alias it had when bound.
 *
 * Unique per (concept, role, site, scope, entity): one answer to "which Stripe does Denver's
 * storefront use", never two.
 */
use \RedBeanPHP\R;

if (!$_tableCheck('connectionbinding')) {
    $b = R::dispense('connectionbinding');
    $b->concept        = str_repeat('x', 40);
    $b->role           = str_repeat('x', 40);
    $b->site_ref       = 1;
    $b->scope          = str_repeat('x', 16);    // install | entity
    $b->entity_type    = str_repeat('x', 40);
    $b->entity_ref     = 1;
    $b->connection_ref = 1;
    $b->alias_snapshot = str_repeat('x', 120);
    $b->bound_by       = str_repeat('x', 40);    // auto | member:<id> | seed
    $b->created_at     = str_repeat('x', 40);
    $b->updated_at     = str_repeat('x', 40);
    R::store($b);
    $_defer($b);
    echo "  connectionbinding table created\n";
}
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_connectionbinding ON connectionbinding (concept, role, site_ref, scope, entity_type, entity_ref)');
R::exec('CREATE INDEX IF NOT EXISTS idx_connectionbinding_connection ON connectionbinding (connection_ref)');
