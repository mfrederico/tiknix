<?php
/**
 * 19_LeadCapture.php — the columns Model_Lead::capture() writes beyond the originals, and the
 * index its one-per-email rule needs. Typed, so RedBean never widens them (a widen rebuilds
 * the SQLite table and drops its rows).
 *
 *   lead.source      where the person first came from: website | appointment | checkout | …
 *   lead.phone       filled in from whichever form first has it
 *   lead.updated_at  last time capture() touched the row
 *
 * Existing rows: source '' means "before this seed" — they came from the landing form, but
 * that is not written here as fact, because on some installs they did not.
 */
use \RedBeanPHP\R;

if ($_tableCheck('lead')) {
    $cols = R::inspect('lead');
    if (!array_key_exists('source', $cols)) {
        R::exec("ALTER TABLE lead ADD COLUMN source TEXT DEFAULT ''");
        echo "  lead.source added\n";
    }
    if (!array_key_exists('phone', $cols)) {
        R::exec("ALTER TABLE lead ADD COLUMN phone TEXT DEFAULT ''");
        echo "  lead.phone added\n";
    }
    if (!array_key_exists('updated_at', $cols)) {
        R::exec("ALTER TABLE lead ADD COLUMN updated_at TEXT DEFAULT ''");
        echo "  lead.updated_at added\n";
    }
    R::exec('CREATE INDEX IF NOT EXISTS idx_lead_email ON lead (email)');
}
