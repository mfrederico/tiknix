<?php
use RedBeanPHP\R;
/**
 * 40_DomainCerts.php — the domaincert table (lib/DomainCerts: one row per domain a project
 * serves, refreshed hourly). Declared with its types: days_left is an integer that is null
 * until a probe answers, and a fluid-made column typed from a first value could be widened
 * later — a widen rebuilds a SQLite table and empties it. instance_ref is a _ref (the instance
 * is hard-deleted), so its index is declared here.
 */
R::exec('CREATE TABLE IF NOT EXISTS domaincert (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain TEXT, instance_ref INTEGER, slug TEXT, ok INTEGER, expires_at TEXT, days_left INTEGER,
    subject TEXT, issuer TEXT, error TEXT, checked_at TEXT
)');
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_domaincert_domain ON domaincert(domain)');
R::exec('CREATE INDEX IF NOT EXISTS idx_domaincert_instance ON domaincert(instance_ref)');
echo "  domaincert: table + indexes ok\n";
