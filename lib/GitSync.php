<?php
/**
 * GitSync — a project kept in step with a GitHub repository, both ways.
 *
 * Owner's decisions (2026-10-20): every request becomes a pull request on the customer's repo,
 * merged at once so the repo's branch always equals the live app (the PR is the record); a push
 * to that branch on GitHub comes back into the app and is checked like a request. GitHub first,
 * a fine-grained token per repository, the app's whole history on the first push.
 *
 * WHERE THE WORK HAPPENS. In a mirror on the platform (<workspace>/.aibuilder/gitsync.git), never
 * in the app's container: the deploy key and the token can push to the customer's repository,
 * and the container holds the app's agent and its code — so neither is ever placed there (the
 * SshTargetDriver rule). The mirror fetches the app's main over core's own SSH access to the
 * container, and GitHub's branch with the deploy key, unsealed for one command at a time.
 *
 *   refs/app/main   the app's main, as last fetched
 *   refs/gh/main    the repository's branch, as last fetched
 *
 * The two meet only by fast-forward or by a merge made IN THE APP, as its owner: a push never
 * rewrites GitHub's branch, and a pull never rewrites the app's. A real conflict is refused, with
 * the files named, and the app left as it was.
 */

namespace app;

use app\Publish\GithubDriver;
use app\Publish\SshKey;

class GitSync {

    public const BRANCH_PREFIX = 'tiknix/request-';
    /** Where the app's side of a conflict is put, for its owner to merge on GitHub. */
    public const APP_BRANCH = 'tiknix/app';
    /** Tests only: a local bare repository standing in for GitHub (no key, no network). */
    public static ?string $remoteForTests = null;
    private const TIMEOUT = 600;
    /** Paths that never leave or enter an app through git: a database, a real config, a key. */
    public const NEVER = '~^(conf/(?![^/]*example)[^/]+\.ini|secure/.+|database/[^/]+\.(db|sqlite3?)|data/[^/]+\.db)$|(^|/)\.env$|\.(pem|key|p12)$~i';

    // ---------------------------------------------------------------- the target

    /** The project's GitHub target (one per project), or null. */
    public static function target(object $inst) {
        return Bean::findOne('deploytarget', 'instance_ref = ? AND driver = ? ORDER BY id ASC', [(int) $inst->id, GithubDriver::key()]) ?: null;
    }

    /** owner/name and branch from a target's settings; throws naming what is wrong. */
    public static function repo(array $cfg): array {
        $repo = trim((string) ($cfg['repo'] ?? ''));
        $repo = preg_replace('~^(https?://github\.com/|git@github\.com:)~i', '', $repo);
        $repo = preg_replace('~\.git$~', '', rtrim($repo, '/'));
        if (!preg_match('~^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$~', $repo)) throw new \InvalidArgumentException("'{$cfg['repo']}' is not a GitHub repository — write it as owner/name");
        $branch = trim((string) ($cfg['branch'] ?? '')) ?: 'main';
        if (!preg_match('~^[A-Za-z0-9._/-]{1,100}$~', $branch) || str_contains($branch, '..')) throw new \InvalidArgumentException("'{$branch}' is not a branch name");
        return [$repo, $branch];
    }

    /** What the two sides last agreed on (deploytarget.sync_json). */
    public static function state($t): array { return json_decode((string) ($t->syncJson ?? ''), true) ?: []; }

    private static function save($t, array $state): void { $t->syncJson = json_encode($state, JSON_UNESCAPED_SLASHES); Bean::store($t); }

    private static function result($t, bool $ok, string $message): void {
        $t->lastRunAt = date('Y-m-d H:i:s'); $t->lastOk = $ok ? 1 : 0; $t->lastMessage = mb_substr($message, 0, 2000);
        Bean::store($t);
    }

    // ---------------------------------------------------------------- push: the app → GitHub

    /**
     * Bring GitHub's branch up to the app's main. With $planId (a request that just ended) and a
     * token, the request goes as a pull request first — branch tiknix/request-<id>, described from
     * the request — and the branch is then fast-forwarded, which GitHub records as that PR merged.
     * When GitHub's branch has moved on its own, nothing is pushed: pull() first (needsPull).
     *
     * @return array{ok:bool, message:string, needsPull?:bool, pr?:string}
     */
    public static function push(object $inst, ?int $planId = null): array {
        $t = self::target($inst);
        if (!$t) return ['ok' => false, 'message' => "{$inst->slug} is not connected to a GitHub repository"];
        return self::locked($inst, function () use ($inst, $t, $planId) {
            try {
                [$repo, $branch] = self::repo(json_decode((string) $t->configJson, true) ?: []);
                $m = self::mirror($inst);
                self::fetchApp($inst, $m);
                $gh = self::fetchRemote($inst, $m, $repo, $branch);
                $app = self::sha($m, 'refs/app/main');
                if ($app === '') throw new \RuntimeException("{$inst->slug} has no main branch to push");
                if ($gh !== '' && $gh === $app) { self::result($t, true, "GitHub's {$branch} is up to date"); return ['ok' => true, 'message' => "Up to date with {$repo}."]; }
                if ($gh !== '' && !self::isAncestor($m, $gh, $app)) {
                    return ['ok' => false, 'needsPull' => true, 'message' => "{$repo}'s {$branch} has commits the app does not have — they are brought in first."];
                }
                if ($bad = self::neverFiles($m, $gh === '' ? 'refs/app/main' : "{$gh}..refs/app/main")) {
                    throw new \RuntimeException('Not pushed: the app\'s history holds files that must never leave it (' . implode(', ', array_slice($bad, 0, 5)) . '). Remove them from its history first.');
                }
                $state = self::state($t);
                $pr = '';
                $token = GithubDriver::token($inst);
                if ($planId && $token !== '' && $gh !== '') {
                    $head = self::BRANCH_PREFIX . $planId;
                    self::gitRemote($inst, $m, ['push', '-q', '--force', self::remoteUrl($repo), "{$app}:refs/heads/{$head}"]);
                    $pr = self::openPullRequest($token, $repo, $branch, $head, self::describe($inst, $planId, $gh, $app, $m));
                }
                self::gitRemote($inst, $m, ['push', '-q', self::remoteUrl($repo), "{$app}:refs/heads/{$branch}"]);
                $state['pushed'] = $app; $state['pushed_at'] = date('c');
                if ($pr !== '') { $state['prs'][(string) $planId] = $pr; $state['prs'] = array_slice($state['prs'], -50, null, true); }
                self::save($t, $state);
                $what = $gh === '' ? "The app's history (" . self::count($m, 'refs/app/main') . ' commits) is on ' . $repo . " {$branch}." : "Pushed to {$repo} {$branch}.";
                $msg = $what . ($pr !== '' ? " Pull request: {$pr}" : ($planId && $token === '' ? ' No pull request: add a token to open one for each request.' : ''));
                self::result($t, true, $msg);
                return ['ok' => true, 'message' => $msg, 'pr' => $pr];
            } catch (\Throwable $e) {
                self::result($t, false, $e->getMessage());
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        });
    }

    // ---------------------------------------------------------------- pull: GitHub → the app

    /**
     * Bring commits pushed to GitHub's branch into the app's main, as the app's owner: fast-forward
     * when the app has nothing GitHub lacks, a merge commit when both moved, and nothing at all on
     * a conflict (the files are named). Never while the project is building — then it says so
     * (deferred) and is tried again. After a merge: a restore point first, then packages when
     * composer.lock changed, the seeds, an entry in Ask checked like any request, and GitHub's
     * branch brought up to the merge.
     *
     * @return array{ok:bool, message:string, deferred?:bool, plan?:int}
     */
    public static function pull(object $inst): array {
        $t = self::target($inst);
        if (!$t) return ['ok' => false, 'message' => "{$inst->slug} is not connected to a GitHub repository"];
        $busy = BuildQueue::busyWith(\Model_Instance::dirOf($inst), (string) $inst->slug);
        if ($busy !== '') {
            $s = self::state($t); $s['pending_pull'] = date('c'); self::save($t, $s);
            return ['ok' => true, 'deferred' => true, 'message' => "Changes from GitHub wait: {$busy}."];
        }
        return self::locked($inst, function () use ($inst, $t) {
            $state = self::state($t);
            try {
                [$repo, $branch] = self::repo(json_decode((string) $t->configJson, true) ?: []);
                $m = self::mirror($inst);
                $gh = self::fetchRemote($inst, $m, $repo, $branch);
                self::fetchApp($inst, $m);
                $app = self::sha($m, 'refs/app/main');
                unset($state['pending_pull']);
                if ($gh === '' || self::isAncestor($m, $gh, $app)) { self::save($t, $state); return ['ok' => true, 'message' => 'Nothing new on GitHub.']; }
                if (trim(self::git($m, ['merge-base', $gh, $app], false)) === '') {
                    throw new \RuntimeException("{$repo}'s {$branch} does not share any history with the app — it was not created from it. Use an empty repository (no README, licence or .gitignore), or one this app was pushed to.");
                }
                if ($bad = self::neverFiles($m, "{$app}..{$gh}")) {
                    throw new \RuntimeException('Not brought in: the commits on GitHub add files that must never enter the app (' . implode(', ', array_slice($bad, 0, 5)) . '). Remove them from that branch first.');
                }
                $commits = self::commitsBetween($m, $app, $gh);
                $files = array_values(array_filter(explode("\n", trim(self::git($m, ['diff', '--name-only', $app, $gh])))));
                $author = TenantHost::author((int) $inst->memberId);

                // The app as it is, kept to go back to; and work left uncommitted in it, made part of its history first.
                $cp = TenantHost::checkpoint($inst, 'checkpoint-github-' . date('Ymd-His'), 'Before changes from GitHub (' . count($commits) . ' commits)', $author);
                if (empty($cp['ok'])) throw new \RuntimeException('No restore point could be taken, so nothing was changed: ' . ($cp['error'] ?? ''));
                $saved = TenantHost::saveWork($inst, $author);
                if (empty($saved['ok'])) throw new \RuntimeException('The app has work in progress that could not be saved, so nothing was changed: ' . ($saved['error'] ?? ''));

                self::gitApp($inst, $m, ['push', '-q', '--force', self::appUrl($inst), "{$gh}:refs/tiknix/incoming"]);
                $git = TenantHost::gitAs($author);
                $script = 'cd /srv/app; before=$(git rev-parse HEAD); '
                    . 'if git merge-base --is-ancestor HEAD refs/tiknix/incoming; then git merge -q --ff-only refs/tiknix/incoming || exit 6; echo HOW=ff; '
                    . 'else if ! ' . $git . ' merge -q --no-ff --no-edit -m ' . escapeshellarg("Merge changes from GitHub ({$repo} {$branch})") . ' refs/tiknix/incoming >/dev/null 2>&1; then '
                    . 'echo CONFLICT=$(git diff --name-only --diff-filter=U | tr "\n" " "); git merge --abort; exit 5; fi; echo HOW=merge; fi; '
                    . 'git update-ref -d refs/tiknix/incoming; '
                    . 'if git diff --name-only "$before" HEAD | grep -qx composer.lock; then composer install --no-interaction --no-progress -q >/tmp/tiknix-gitsync-composer.log 2>&1 || { tail -5 /tmp/tiknix-gitsync-composer.log; exit 7; }; echo COMPOSER=1; fi; '
                    . 'php scripts/clitool.php --build >/tmp/tiknix-gitsync-build.log 2>&1 || { tail -20 /tmp/tiknix-gitsync-build.log; exit 8; }; '
                    . 'echo NOW=$(git rev-parse HEAD)';
                [$code, $out] = TenantHost::ssh($inst, 'app', $script . ' 2>&1', null, self::TIMEOUT);
                $out = (string) $out;
                if ($code === 5) {
                    preg_match('/^CONFLICT=(.*)$/m', $out, $c);
                    // The app's side goes to a branch of its own on GitHub, so the conflict can be
                    // settled there; merged into the branch, the result comes back in by itself.
                    $side = '';
                    try { self::gitRemote($inst, $m, ['push', '-q', '--force', self::remoteUrl($repo), "{$app}:refs/heads/" . self::APP_BRANCH]); $side = ' The app\'s version is on the branch ' . self::APP_BRANCH . ": merge it into {$branch} on GitHub, settling those files, and the result is brought in by itself."; }
                    catch (\Throwable $e) { $side = ' (The app\'s version could not be put on a branch of GitHub for you to merge: ' . $e->getMessage() . ')'; }
                    throw new \RuntimeException("Not brought in: the commits on {$repo} {$branch} conflict with the app in " . (trim($c[1] ?? '') ?: 'some files')
                        . '. The app was left as it was.' . $side);
                }
                if ($code !== 0) throw new \RuntimeException("The app could not take the changes (exit {$code}): " . mb_substr(trim($out), -600) . ' — its restore point is ' . $cp['tag']);
                preg_match('/^NOW=(\S+)/m', $out, $now);
                $merged = str_contains($out, 'HOW=merge');

                // A merge commit made here is pushed back, so both sides are the same again.
                if ($merged) {
                    self::fetchApp($inst, $m);
                    self::gitRemote($inst, $m, ['push', '-q', self::remoteUrl($repo), self::sha($m, 'refs/app/main') . ":refs/heads/{$branch}"]);
                }
                $plan = self::askEntry($inst, $repo, $branch, $commits, $files, (string) ($now[1] ?? ''), (string) $cp['tag']);
                $state['pulled'] = (string) ($now[1] ?? $gh); $state['pulled_at'] = date('c');
                self::save($t, $state);
                $msg = count($commits) . ' commit' . (count($commits) === 1 ? '' : 's') . " from {$repo} {$branch} " . ($merged ? 'merged into' : 'fast-forwarded onto') . ' the app'
                     . (str_contains($out, 'COMPOSER=1') ? '; packages reinstalled' : '') . '; seeds run' . ($plan > 0 ? '; being checked as request #' . $plan : '') . '.';
                self::result($t, true, $msg);
                return ['ok' => true, 'message' => $msg, 'plan' => $plan];
            } catch (\Throwable $e) {
                self::save($t, $state);
                self::result($t, false, $e->getMessage());
                self::tellOwner($inst, 'Changes from GitHub were not brought in', $e->getMessage());
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        });
    }

    /**
     * The changes as an entry in Ask — a finished request with one merged task, written to the
     * project's own board — then the check after a build, launched on it like on any request.
     * Its failures become problems Tiknix found (and, with automatic fixes on, fix themselves).
     */
    private static function askEntry(object $inst, string $repo, string $branch, array $commits, array $files, string $head, string $checkpoint): int {
        $dir = \Model_Instance::dirOf($inst);
        $db = $dir . '/data/workbench.db';
        // A project never built has no board: the changes are in, there is just no Ask to show them in.
        if (!is_file($db)) { error_log("INFO GitSync {$inst->slug}: changes brought in; no Builder board to show them on"); return 0; }
        $who = array_values(array_unique(array_map(fn($c) => $c['author'], $commits)));
        $title = 'Changes from GitHub: ' . count($commits) . ' commit' . (count($commits) === 1 ? '' : 's') . ' by ' . implode(', ', array_slice($who, 0, 3));
        $list = implode("\n", array_map(fn($c) => "- {$c['short']} {$c['subject']} ({$c['author']})", array_slice($commits, 0, 40)));
        $goal = "These commits were pushed to {$repo} ({$branch}) and brought into the app:\n\n{$list}\n\nFiles changed: " . implode(', ', array_slice($files, 0, 60))
              . "\n\nCheck the pages these files make or change, as each kind of user. Nothing was built by an agent here: this is a person's change.";
        $was = Bean::currentDatabaseKey();
        if (!Bean::hasDatabase('gitsyncws:' . $inst->slug)) Bean::addDatabase('gitsyncws:' . $inst->slug, 'sqlite:' . $db);
        Bean::selectDatabase('gitsyncws:' . $inst->slug);
        try {
            Bean::freeze(false);
            $now = date('Y-m-d H:i:s');
            $p = Bean::dispense('workbenchtask');
            $p->title = mb_substr($title, 0, 90); $p->description = $goal; $p->planGoal = $goal;
            $p->taskType = 'feature'; $p->priority = 2; $p->status = 'completed'; $p->planStatus = 'done';
            $p->instanceId = (int) $inst->id; $p->instanceTag = $inst->slug . '.' . ($inst->app ?: 'tiknix');
            $p->memberId = (int) $inst->memberId; $p->source = 'github'; $p->planUid = bin2hex(random_bytes(8));
            $p->planCheckpoint = $checkpoint; $p->createdAt = $now; $p->updatedAt = $now; $p->completedAt = $now;
            $planId = (int) Bean::store($p);
            $k = Bean::dispense('workbenchtask');
            $k->title = mb_substr('Brought in ' . count($commits) . ' commit' . (count($commits) === 1 ? '' : 's') . " from {$repo}", 0, 120);
            $k->description = $goal; $k->taskType = 'feature'; $k->priority = 1; $k->status = 'merged';
            $k->instanceId = (int) $inst->id; $k->instanceTag = $p->instanceTag; $k->memberId = (int) $inst->memberId;
            $k->parentTaskId = $planId; $k->relatedFiles = json_encode(array_slice($files, 0, 200)); $k->source = 'github';
            $k->createdAt = $now; $k->startedAt = $now; $k->completedAt = $now; $k->updatedAt = $now;
            $k->progressMessage = 'Merged into main at ' . substr($head, 0, 12);
            Bean::store($k);
        } finally {
            Bean::selectDatabase($was);
        }
        // the project's notebook: the next agent should know the code moved under it
        try {
            TenantHost::notebookAdd($inst, [['kind' => 'built', 'doc' => 'built', 'text' => mb_substr($title . ' — ' . implode('; ', array_map(fn($c) => $c['subject'], array_slice($commits, 0, 5))), 0, 400)]],
                'request #' . $planId, TenantHost::author((int) $inst->memberId));
        } catch (\Throwable $e) { error_log("ERROR GitSync {$inst->slug}: the notebook line for the changes from GitHub was not written: " . $e->getMessage()); }
        $aib = @parse_ini_file(Paths::root() . '/conf/aibuilder.ini', true) ?: [];
        if (filter_var($aib['audit']['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            exec('nohup env -u TIKNIX_WORKBENCH_DB php ' . escapeshellarg(Paths::root() . '/scripts/plan-audit.php') . ' --plan=' . $planId
                . ' --slug=' . escapeshellarg((string) $inst->slug) . ' --dir=' . escapeshellarg($dir) . ' --level=50'
                . ' > ' . escapeshellarg($dir . '/.aibuilder/audit-driver.log') . ' 2>&1 &');
        }
        return $planId;
    }

    // ---------------------------------------------------------------- after a request

    /**
     * What a request's end sets off for a project kept in step with GitHub: changes waiting on
     * GitHub come in first (they could not while it built), then the request goes out as a PR.
     */
    public static function afterRequest(object $inst, int $planId): array {
        $said = [];
        $t = self::target($inst);
        if (!$t) return $said;
        $s = self::state($t);
        if (!empty($s['pending_pull'])) { $r = self::pull($inst); $said[] = 'pull: ' . $r['message']; }
        $r = self::push($inst, $planId);
        if (!empty($r['needsPull'])) {
            $p = self::pull($inst); $said[] = 'pull: ' . $p['message'];
            if (!empty($p['ok']) && empty($p['deferred'])) $r = self::push($inst, $planId);
        }
        $said[] = 'push: ' . $r['message'];
        if (empty($r['ok'])) self::tellOwner($inst, 'Request #' . $planId . ' was not pushed to GitHub', $r['message']);
        return $said;
    }

    /** Start the background script for $verb on a project (the web request does not wait for git). */
    public static function launch(object $inst, string $verb, int $planId = 0): void {
        if (!in_array($verb, ['push', 'pull', 'after'], true)) throw new \InvalidArgumentException("not a git sync step: {$verb}");
        $log = \Model_Instance::dirOf($inst) . '/.aibuilder/gitsync.log';
        exec('nohup env -u TIKNIX_WORKBENCH_DB -u GIT_DIR -u GIT_INDEX_FILE php ' . escapeshellarg(Paths::root() . '/scripts/git-sync.php')
            . ' --' . $verb . ' --instance=' . (int) $inst->id . ($planId > 0 ? ' --plan=' . $planId : '')
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &');
    }

    // ---------------------------------------------------------------- GitHub's API

    /** Open a pull request; returns its address. Throws with GitHub's own reason. */
    private static function openPullRequest(string $token, string $repo, string $base, string $head, array $about): string {
        $r = self::api($token, 'POST', "/repos/{$repo}/pulls", ['title' => $about['title'], 'body' => $about['body'], 'head' => $head, 'base' => $base]);
        if ($r['status'] === 422 && str_contains(json_encode($r['body']), 'already exists')) {
            $open = self::api($token, 'GET', "/repos/{$repo}/pulls?head=" . rawurlencode(explode('/', $repo)[0] . ':' . $head) . '&state=all');
            if (!empty($open['body'][0]['html_url'])) return (string) $open['body'][0]['html_url'];
        }
        if ($r['status'] !== 201) throw new \RuntimeException("GitHub did not open the pull request (HTTP {$r['status']}): " . self::apiReason($r));
        return (string) ($r['body']['html_url'] ?? '');
    }

    /** One call to GitHub's REST API with the project's token. @return array{status:int, body:mixed} */
    public static function api(string $token, string $method, string $path, ?array $json = null): array {
        $ch = curl_init('https://api.github.com' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: Tiknix', 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $json !== null ? json_encode($json, JSON_UNESCAPED_SLASHES) : null]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) throw new \RuntimeException('GitHub did not answer: ' . curl_error($ch));
        return ['status' => $status, 'body' => json_decode((string) $raw, true)];
    }

    public static function apiReason(array $r): string {
        $b = $r['body'] ?? [];
        $why = trim((string) ($b['message'] ?? ''));
        foreach ((array) ($b['errors'] ?? []) as $e) $why .= ' ' . (is_array($e) ? (string) ($e['message'] ?? json_encode($e)) : (string) $e);
        return $why !== '' ? $why : 'no reason given';
    }

    /** A request said as a pull request: what was asked, what it became, what the check found. */
    private static function describe(object $inst, int $planId, string $from, string $to, string $m): array {
        $dir = \Model_Instance::dirOf($inst);
        $pdo = new \PDO('sqlite:' . $dir . '/data/workbench.db', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        $plan = $pdo->query('SELECT * FROM workbenchtask WHERE id = ' . (int) $planId)->fetch(\PDO::FETCH_ASSOC) ?: [];
        $kids = $pdo->query('SELECT * FROM workbenchtask WHERE parent_task_id = ' . (int) $planId . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $asked = trim(GoalBrief::split((string) (($plan['plan_goal'] ?? '') !== '' ? $plan['plan_goal'] : ($plan['description'] ?? '')))['goal']);
        $asked = trim(AskTriage::splitAsked($asked)['said']) ?: (string) ($plan['title'] ?? '');
        $marks = ['merged' => '✅', 'resolved' => '➖', 'failed' => '❌', 'conflict' => '⚠️'];
        $tasks = implode("\n", array_map(function ($k) use ($marks) {
            $rk = json_decode((string) ($k['rank_json'] ?? ''), true) ?: [];
            $risk = isset($rk['risk']) ? ' — risk: ' . (PlanRank::LABELS[(int) $rk['risk']] ?? $rk['risk']) : '';
            return '- ' . ($marks[(string) $k['status']] ?? '•') . ' ' . $k['title'] . $risk;
        }, $kids));
        $audit = (string) ($plan['audit_status'] ?? '');
        $check = $audit === 'passed' ? '✅ The check after the build passed: each changed page was opened as every kind of user, at desktop and phone width.'
               : ($audit === 'failed' ? '⚠️ The check after the build found problems: ' . mb_strimwidth((string) ($plan['audit_failures'] ?? ''), 0, 600, '…') : 'The check after the build has not reported.');
        $commits = self::commitsBetween($m, $from, $to);
        $body = "## What was asked\n\n" . self::quote($asked) . "\n\n## What it became\n\n" . ($tasks ?: '- (no tasks recorded)') . "\n\n## The check\n\n{$check}\n\n"
              . '## Commits (' . count($commits) . ")\n\n" . implode("\n", array_map(fn($c) => "- `{$c['short']}` {$c['subject']} — {$c['author']}", array_slice($commits, 0, 50)))
              . "\n\n---\nBuilt with Tiknix — request #{$planId} of {$inst->slug}. This pull request is the record of a change that is already live: it was merged when it was opened.";
        return ['title' => mb_substr('Request #' . $planId . ': ' . (string) ($plan['title'] ?? 'changes'), 0, 200), 'body' => mb_substr($body, 0, 60000)];
    }

    private static function quote(string $s): string { return implode("\n", array_map(fn($l) => '> ' . $l, explode("\n", mb_substr($s, 0, 4000)))); }

    // ---------------------------------------------------------------- git, on the mirror

    private static function mirror(object $inst): string {
        $m = \Model_Instance::dirOf($inst) . '/.aibuilder/gitsync.git';
        if (!is_file($m . '/HEAD')) {
            if (!is_dir(dirname($m)) && !mkdir(dirname($m), 0775, true)) throw new \RuntimeException('could not make ' . dirname($m));
            exec('env -u GIT_DIR -u GIT_INDEX_FILE git init -q --bare ' . escapeshellarg($m) . ' 2>&1', $o, $c);
            if ($c !== 0) throw new \RuntimeException("could not make the mirror {$m}: " . implode(' ', $o));
        }
        return $m;
    }

    private static function appUrl(object $inst): string {
        if (!ProxmoxService::isTenantIp((string) $inst->ctIp)) throw new \RuntimeException("{$inst->slug} has no container address");
        return 'app@' . $inst->ctIp . ':/srv/app';
    }

    private static function remoteUrl(string $repo): string { return self::$remoteForTests ?? 'git@github.com:' . $repo . '.git'; }

    private static function fetchApp(object $inst, string $m): void {
        self::gitApp($inst, $m, ['fetch', '-q', '--no-tags', self::appUrl($inst), '+refs/heads/main:refs/app/main']);
    }

    /** GitHub's branch into refs/gh/main; '' when the repository is empty or has no such branch. */
    private static function fetchRemote(object $inst, string $m, string $repo, string $branch): string {
        $heads = self::gitRemote($inst, $m, ['ls-remote', '--heads', self::remoteUrl($repo), $branch]);
        if (!preg_match('~^([0-9a-f]{40})\s+refs/heads/' . preg_quote($branch, '~') . '$~m', $heads, $h)) return '';
        self::gitRemote($inst, $m, ['fetch', '-q', '--no-tags', self::remoteUrl($repo), "+refs/heads/{$branch}:refs/gh/main"]);
        return self::sha($m, 'refs/gh/main');
    }

    /** git with core's own SSH access to the container. */
    private static function gitApp(object $inst, string $m, array $args): string {
        $ssh = 'ssh -i ' . escapeshellarg(TenantHost::key()) . ' -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile='
             . escapeshellarg(TenantHost::knownHosts()) . ' -o LogLevel=ERROR';
        return self::run($m, $args, $ssh);
    }

    /** git with the project's deploy key, unsealed into a 0600 file for this one command. */
    private static function gitRemote(object $inst, string $m, array $args): string {
        if (self::$remoteForTests !== null) return self::run($m, $args, null);
        $row = GithubDriver::keyRow($inst, false);
        if (!$row) throw new \RuntimeException("{$inst->slug} has no deploy key yet — test the GitHub connection on the Deploy page to make one");
        return SshKey::withKeyFile((string) $row->privateKey, function (string $keyFile) use ($inst, $m, $args, $row) {
            $ssh = 'ssh -i ' . escapeshellarg($keyFile) . ' -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile='
                 . escapeshellarg(GithubDriver::knownHosts($inst)) . ' -o LogLevel=ERROR';
            try { $out = self::run($m, $args, $ssh); $row->lastUsedAt = date('Y-m-d H:i:s'); $row->lastError = null; Bean::store($row); return $out; }
            catch (\RuntimeException $e) { $row->lastError = mb_substr($e->getMessage(), 0, 500); Bean::store($row); throw new \RuntimeException(GithubDriver::explainGit($e->getMessage(), $inst)); }
        });
    }

    private static function git(string $m, array $args, bool $strict = true): string { return self::run($m, $args, null, $strict); }

    /** Run git on the mirror; throws with its output unless $strict is false. */
    private static function run(string $m, array $args, ?string $ssh, bool $strict = true): string {
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => (string) (getenv('HOME') ?: '/tmp'), 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8', 'GIT_TERMINAL_PROMPT' => '0'];
        if ($ssh !== null) $env['GIT_SSH_COMMAND'] = $ssh;
        $p = proc_open(array_merge(['timeout', (string) self::TIMEOUT, 'git', '-C', $m], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
        if (!is_resource($p)) throw new \RuntimeException('git could not be started');
        $out = (string) stream_get_contents($pipes[1]); fclose($pipes[1]);
        $code = proc_close($p);
        if ($code !== 0 && $strict) throw new \RuntimeException('git ' . $args[0] . ' failed: ' . trim($out));
        return $out;
    }

    private static function sha(string $m, string $ref): string {
        return trim(self::git($m, ['rev-parse', '-q', '--verify', $ref], false));
    }

    private static function isAncestor(string $m, string $a, string $b): bool {
        $p = proc_open(['git', '-C', $m, 'merge-base', '--is-ancestor', $a, $b], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin']);
        return proc_close($p) === 0;
    }

    private static function count(string $m, string $ref): int { return (int) trim(self::git($m, ['rev-list', '--count', $ref])); }

    /** Commits in $to that $from lacks, oldest first: short, subject, author. */
    private static function commitsBetween(string $m, string $from, string $to): array {
        $range = $from !== '' ? "{$from}..{$to}" : $to;
        $out = [];
        foreach (array_filter(explode("\n", self::git($m, ['log', '--reverse', '--no-merges', '--format=%h%x09%an%x09%s', $range]))) as $l) {
            [$short, $author, $subject] = array_pad(explode("\t", $l, 3), 3, '');
            $out[] = ['short' => $short, 'author' => $author, 'subject' => $subject];
        }
        return $out;
    }

    /** Files that must never move through git (NEVER), added anywhere in $range. */
    public static function neverFiles(string $m, string $range): array {
        $bad = [];
        foreach (array_unique(array_filter(explode("\n", self::git($m, ['log', '--format=', '--name-only', '--diff-filter=A', $range])))) as $f) {
            if (preg_match(self::NEVER, $f)) $bad[] = $f;
        }
        return array_values($bad);
    }

    /** One sync at a time per project: a push and a pull racing would each see the other half-done. */
    private static function locked(object $inst, callable $fn): array {
        $f = fopen(\Model_Instance::dirOf($inst) . '/.aibuilder/gitsync.lock', 'c');
        if (!$f || !flock($f, LOCK_EX)) throw new \RuntimeException("could not take {$inst->slug}'s git sync lock");
        try { return $fn(); } finally { flock($f, LOCK_UN); fclose($f); }
    }

    /**
     * A failure the owner must hear about, by email (PlanNotifier: a booted core's mail connection).
     * Not a Communications notice: those come from the platform's admin, who may be the owner.
     */
    private static function tellOwner(object $inst, string $subject, string $why): void {
        $m = Bean::load('member', (int) $inst->memberId);
        if (!$m->id || !filter_var((string) $m->email, FILTER_VALIDATE_EMAIL)) { error_log("ERROR GitSync {$inst->slug}: the owner (member #{$inst->memberId}) has no email to tell: {$subject}"); return; }
        $line = PlanNotifier::mailAddress((string) $m->email, (string) ($m->username ?? ''), $subject . ' — ' . ($inst->displayName ?: $inst->slug),
            '<p>' . nl2br(htmlspecialchars($why)) . '</p><p>The Deploy page shows the GitHub connection and its last result.</p>');
        if (str_starts_with($line, 'email FAILED')) error_log("ERROR GitSync {$inst->slug}: the owner could not be told ({$subject}): {$line}");
    }
}
