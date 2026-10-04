<?php
use RedBeanPHP\R;
/**
 * 39_Projectreport.php — every hosted app reports its status to /projectreport/ingest with its
 * broker key (the method authenticates: PUBLIC at the gate, like brokerinfo); the pages that
 * read the reports are ADMIN. The table is RedBean-made on the first report; its index on
 * instance_ref (a _ref: the instance is hard-deleted) is declared here, since a _ref gets none.
 */
echo '  authcontrol: projectreport::ingest => ' . \app\PermissionCache::seedRule('projectreport', 'ingest', 101, 'Apps report their status with their broker key (the method authenticates)') . "\n";
echo '  authcontrol: projectreport::* => ' . \app\PermissionCache::seedRule('projectreport', '*', 50, 'Project status reports (admins)') . "\n";
// Declared, not fluid-made: a report's numbers are floats that are sometimes whole (1.0 MB),
// and RedBean types a column from the first value it sees — an INTEGER that later meets 42.3
// is widened, and a widen rebuilds a SQLite table and empties it (every report gone). Each
// type below is what every later value fits; dates are TEXT (see the widen note in memory).
R::exec('CREATE TABLE IF NOT EXISTS projectreport (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_ref INTEGER, slug TEXT, received_at TEXT, reported_at TEXT, why TEXT,
    runtime TEXT, app_commit TEXT, agent_ready INTEGER, providers TEXT, default_agent TEXT, concepts TEXT, members INTEGER,
    disk_mb REAL, disk_code_mb REAL, disk_agent_mb REAL, disk_uploads_mb REAL, db_mb REAL, files INTEGER,
    mem_mb REAL, mem_total_mb REAL, mem_peak_mb REAL, cpu_pct REAL, load1 REAL,
    requests_hour INTEGER, errors_hour INTEGER, report_json TEXT
)');
echo "  projectreport: table declared\n";
R::exec('CREATE INDEX IF NOT EXISTS idx_projectreport_instance ON projectreport(instance_ref, received_at)');
echo "  projectreport: index ok\n";
