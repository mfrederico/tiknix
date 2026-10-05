<?php
/**
 * Projectreport FUSE Model — one status report from one hosted app (controls/Projectreport.php).
 *
 * The headline numbers are columns so a query can rank projects by disk, memory or CPU
 * without decoding JSON; the whole report is kept in report_json for whatever the next
 * question turns out to be. `instance_ref`, not `instance_id`: a report outlives nothing —
 * it is pruned with the instance — but the instance is hard-deleted and RedBean would
 * otherwise try to keep a foreign key to a row that is gone.
 */

class Model_Projectreport extends \RedBeanPHP\SimpleModel {

    /** The columns a report fills, from the report's own fields (null stays null: "not measured"). */
    public static function fromReport(object $inst, array $rep): \RedBeanPHP\OODBBean {
        $r = \app\Bean::dispense('projectreport');
        $h = self::headline($rep);
        $r->instanceRef   = (int) $inst->id;
        $r->slug          = (string) $inst->slug;
        $r->receivedAt    = date('Y-m-d H:i:s');
        $r->reportedAt    = (string) ($rep['at'] ?? '');
        $r->why           = mb_substr((string) ($rep['why'] ?? ''), 0, 120);
        $r->runtime       = (string) $h['runtime'];
        $r->appCommit     = (string) $h['commit'];
        $r->agentReady    = $h['agent_ready'] ? 1 : 0;
        $r->providers     = implode(',', $h['providers']);
        $r->defaultAgent  = (string) $h['default_agent'];
        $r->concepts      = implode(',', $h['concepts']);
        $r->members       = $h['members'];
        $r->diskMb        = $h['disk_mb'];
        $r->diskCodeMb    = $h['disk_code_mb'];
        $r->diskAgentMb   = $h['disk_agent_mb'];
        $r->diskUploadsMb = $h['disk_uploads_mb'];
        $r->dbMb          = $h['db_mb'];
        $r->files         = $h['files'];
        $r->memMb         = $h['mem_mb'];
        $r->memTotalMb    = $h['mem_total_mb'];
        $r->memPeakMb     = $h['mem_peak_mb'];
        $r->cpuPct        = $h['cpu_pct'];
        $r->load1         = $h['load1'];
        $r->requestsHour  = $h['requests_h'];
        $r->errorsHour    = $h['errors_h'];
        $r->reportJson    = json_encode($rep, JSON_UNESCAPED_SLASHES);
        return $r;
    }

    /** The numbers worth a column, pulled from a report; the same shape the instance row keeps as its latest. */
    public static function headline(array $rep): array {
        $rd = (array) ($rep['readiness'] ?? []); $rs = (array) ($rep['resources'] ?? []); $app = (array) ($rep['app'] ?? []);
        $mb = fn($b) => $b === null ? null : round(((float) $b) / 1048576, 1);
        $parts = (array) ($rs['disk']['parts'] ?? []);
        $dbs = (array) ($rs['disk']['databases'] ?? []);
        $providers = [];
        $names = [];   // handle => what people call it (an agent with no human name is absent)
        $builders = [];      // the agents that can plan and build, and are ready to (the Builder's picker)
        $taskMinutes = [];   // handle => the agent's own task time limit (PlanExecutor budgets a plan with it)
        foreach ((array) ($rd['providers'] ?? []) as $p) {
            if (!empty($p['ready'])) $providers[] = (string) ($p['preset'] ?: $p['name']);
            if (trim((string) ($p['display_name'] ?? '')) !== '') $names[(string) $p['name']] = (string) $p['display_name'];
            if ((int) ($p['task_minutes'] ?? 0) > 0) $taskMinutes[(string) $p['name']] = (int) $p['task_minutes'];
            if (($p['type'] ?? '') === 'build' && !empty($p['ready'])) $builders[] = (string) $p['name'];
        }
        if (!empty($rd['anthropic'])) $providers[] = 'anthropic';
        return [
            'at'              => (string) ($rep['at'] ?? ''),
            'runtime'         => (string) ($app['runtime'] ?? ''),
            'commit'          => (string) ($app['commit'] ?? ''),
            'uncommitted'     => (int) ($app['uncommitted'] ?? 0),
            'uncommitted_files' => array_values(array_map('strval', (array) ($app['uncommitted_files'] ?? []))),
            'guidance'        => (string) ($app['guidance'] ?? ''),   // the generated AGENTS.md: ok | uncommitted | missing | '' (an older runtime does not say)
            'agent_ready'     => (bool) ($rd['agent_ready'] ?? false),
            'agent_problem'   => (string) ($rd['agent_problem'] ?? ''),
            'providers'       => array_values(array_unique($providers)),
            'default_agent'   => (string) ($rd['default_agent'] ?? ''),
            'agent_names'     => $names,
            'task_minutes'    => $taskMinutes,
            'build_agents'    => $builders,
            // false only when the app said so; an app on an older runtime says nothing, which is not "off"
            'tiknix_mcp'      => array_key_exists('tiknix_mcp', $rd) ? (bool) $rd['tiknix_mcp'] : null,
            'concepts'        => array_values((array) ($rd['concepts'] ?? [])),
            'pipelines'       => $rd['pipelines'] ?? null,
            'cron_last_tick'  => $rd['cron_last_tick'] ?? null,
            'members'         => isset($rd['members']) ? (int) $rd['members'] : null,
            'disk_mb'         => $mb($rs['disk']['app_bytes'] ?? null),
            'disk_code_mb'    => $mb(($parts['code'] ?? 0) + ($parts['vendor'] ?? 0)),
            'disk_agent_mb'   => $mb(($parts['.aibuilder'] ?? 0) + ($parts['bin'] ?? 0)),
            'disk_uploads_mb' => $mb($parts['public/uploads'] ?? 0),
            'db_mb'           => $mb(array_sum($dbs)),
            'files'           => isset($rs['disk']['files']) ? (int) $rs['disk']['files'] : null,
            'mem_mb'          => $mb($rs['memory']['used'] ?? null),
            'mem_total_mb'    => $mb($rs['memory']['total'] ?? null),
            'mem_peak_mb'     => $mb($rs['memory']['peak'] ?? null),
            'cpu_pct'         => isset($rs['cpu']['pct_of_core']) ? (float) $rs['cpu']['pct_of_core'] : null,
            'load1'           => isset($rs['cpu']['load1']) ? (float) $rs['cpu']['load1'] : null,
            'requests_h'      => $rs['last_hour']['requests'] ?? null,
            'errors_h'        => $rs['last_hour']['errors'] ?? null,
        ];
    }
}
