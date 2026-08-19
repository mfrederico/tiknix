#!/usr/bin/env php
<?php
/**
 * member.google_id -> member.google_eid.
 *
 * The Google subject id is a string id issued by another system, so it belongs under
 * the _eid convention; _id is reserved for RedBean's integer foreign keys. The old
 * name had a real cost beyond tidiness: 01_Member.php could not size a *_id column
 * (RedBean reads it as an FK to a bean type 'google'), so the column was left to be
 * invented by whatever wrote it first — the same unsized state that cost mileage its
 * member table.
 *
 * ADD COLUMN + copy. The old column is deliberately LEFT IN PLACE: SQLite cannot drop
 * a column without rebuilding the whole table, and that rebuild is the exact failure
 * this convention exists to avoid. It is nullable and nothing reads it after this.
 *
 * Idempotent. Usage: php database/migrate-google-eid.php [/path/to/other.db]
 */

require_once __DIR__ . '/../bootstrap.php';

use app\Bean;

$app = new \app\Bootstrap();

if (!Bean::getCell("SELECT COUNT(*) FROM pragma_table_info('member') WHERE name='google_id'")) {
    echo "no google_id column on member — nothing to migrate\n";
    exit(0);
}

if (!Bean::getCell("SELECT COUNT(*) FROM pragma_table_info('member') WHERE name='google_eid'")) {
    // Declared TEXT explicitly rather than let a first write type it.
    Bean::exec("ALTER TABLE member ADD COLUMN google_eid TEXT");
    echo "added member.google_eid (TEXT)\n";
}

$pending = Bean::getCell(
    "SELECT COUNT(*) FROM member WHERE google_id IS NOT NULL AND google_id <> ''
       AND (google_eid IS NULL OR google_eid = '')"
);

if ($pending) {
    Bean::exec(
        "UPDATE member SET google_eid = google_id
          WHERE google_id IS NOT NULL AND google_id <> ''
            AND (google_eid IS NULL OR google_eid = '')"
    );
    echo "copied {$pending} google id(s) into google_eid\n";
} else {
    echo "no google ids to copy\n";
}

// google_id was UNIQUE via an inline constraint; the new column needs that stated.
// Partial, because most members have no Google id and would all collide on NULL...
// (NULLs do not collide in SQLite, but the partial index also keeps it small.)
Bean::exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_member_google_eid
              ON member(google_eid) WHERE google_eid IS NOT NULL AND google_eid <> ''");
echo "unique index idx_member_google_eid in place\n";

echo "done. google_id left in place on purpose — dropping it means a table rebuild.\n";
