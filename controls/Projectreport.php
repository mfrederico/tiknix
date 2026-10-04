<?php
/**
 * Projectreport — what every hosted app reports about itself, kept.
 *
 * The app is the one that knows whether it is ready and what it uses; core used to ask over
 * ssh, one page at a time, and could not ask about disk or memory at all. Now each app POSTs
 * a StatusReport (runtime lib/StatusReport.php) here — hourly from its platform pipeline, and
 * at once when a readiness element changes — with its own broker key. The method authenticates
 * the key itself (like Brokerinfo), so the route is PUBLIC at the gate.
 *
 * Every report is a `projectreport` row (the headline numbers as columns, the whole report as
 * JSON) and the instance row carries the latest (last_reported_at, report_json): the Projects
 * page says when an app last spoke and what it uses, the Builder gate reads agent readiness
 * from it, and a hosting tier can one day be recommended from the history. Rows older than
 * KEEP_DAYS go when the next report for that instance arrives.
 */
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Projectreport extends Control {

    public const KEEP_DAYS = 90;
    /** A latest report older than this is stale: the Builder asks the app directly instead. */
    public const FRESH_SECONDS = 1500;

    /** POST /projectreport/ingest — the app's report, with its broker key. */
    public function ingest($params = []) {
        $key = BrokerService::keyFromRequest();
        if (!$key) { Flight::jsonError('Forbidden.', 403); return; }
        $iid = (int) ($key->instanceId ?? 0);
        if ($iid <= 0) { Flight::jsonError('This broker key is not bound to an instance.', 403); return; }
        $inst = Bean::load('instance', $iid);
        if (!$inst->id) { Flight::jsonError('That project is gone.', 404); return; }

        $raw = (string) file_get_contents('php://input');
        $rep = json_decode($raw, true);
        if (!is_array($rep) || (int) ($rep['version'] ?? 0) !== 1 || !isset($rep['readiness'], $rep['resources'])) {
            $this->logger->error('ERROR Projectreport: a report that is not version 1', ['instance' => $inst->slug, 'head' => mb_substr($raw, 0, 200)]);
            Flight::jsonError('Not a version-1 status report.', 400); return;
        }
        if (strlen($raw) > 262144) { Flight::jsonError('Report too large (256 KB max).', 413); return; }

        $row = \Model_Projectreport::fromReport($inst, $rep);
        Bean::store($row);
        $inst->lastReportedAt = $row->receivedAt;
        $inst->reportJson = json_encode(\Model_Projectreport::headline($rep), JSON_UNESCAPED_SLASHES);
        Bean::store($inst);
        $pruned = Bean::exec('DELETE FROM projectreport WHERE instance_ref = ? AND received_at < ?', [$iid, date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
        $this->logger->info('Project report received', ['instance' => $inst->slug, 'why' => (string) ($rep['why'] ?? ''), 'agent_ready' => (bool) ($rep['readiness']['agent_ready'] ?? false),
            'disk_mb' => $row->diskMb, 'mem_mb' => $row->memMb, 'cpu_pct' => $row->cpuPct, 'pruned' => (int) $pruned]);
        Flight::jsonSuccess(['id' => (int) $row->id, 'received_at' => $row->receivedAt]);
    }

    /** GET /projectreport — every hosted project's latest report (ADMIN). */
    public function index($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        $rows = [];
        foreach (Bean::find('instance', "(ct_kind = 'tenant' OR is_default = 1) AND (status IS NULL OR status != 'deleted') ORDER BY is_default DESC, slug") as $inst) {
            $h = $inst->reportJson ? json_decode((string) $inst->reportJson, true) : null;
            $rows[] = ['inst' => $inst, 'at' => (string) ($inst->lastReportedAt ?? ''), 'h' => is_array($h) ? $h : null,
                       'age' => $inst->lastReportedAt ? time() - strtotime((string) $inst->lastReportedAt) : null];
        }
        $this->render('projectreport/index', ['title' => 'Project reports', 'rows' => $rows, 'fresh' => self::FRESH_SECONDS]);
    }

    /** GET /projectreport/show/<id> — one project's reports, newest first (ADMIN). */
    public function show($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        $inst = Bean::load('instance', (int) ($params['operation']->name ?? $this->getParam('id', 0)));   // /projectreport/show/<id>
        if (!$inst->id) { Flight::notFound(); return; }
        $reports = Bean::find('projectreport', 'instance_ref = ? ORDER BY received_at DESC LIMIT 200', [(int) $inst->id]);
        $this->render('projectreport/show', ['title' => 'Reports — ' . ($inst->displayName ?: $inst->slug), 'inst' => $inst, 'reports' => array_values($reports)]);
    }

    /**
     * The latest report's readiness for an instance, if it is fresh — null when there is none
     * or it is older than FRESH_SECONDS. The Builder gate's first source; ssh is the fallback.
     *
     * @return ?array{agent_ready:bool, agent_problem:string, at:string}
     */
    public static function freshReadiness(object $inst): ?array {
        $at = (string) ($inst->lastReportedAt ?? '');
        if ($at === '' || time() - strtotime($at) > self::FRESH_SECONDS) return null;
        $h = json_decode((string) ($inst->reportJson ?? ''), true);
        if (!is_array($h) || !array_key_exists('agent_ready', $h)) return null;
        return ['agent_ready' => (bool) $h['agent_ready'], 'agent_problem' => (string) ($h['agent_problem'] ?? ''), 'at' => $at];
    }
}
