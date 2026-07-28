<?php
/**
 * Projects — the interstitial picker, and the only place a project is chosen.
 *
 * Selecting here sets ProjectContext for the member, and every other surface (core, the
 * workbench sidecar, the pipeline editor, the store) then works on THAT project until
 * the member comes back here. No other page should offer an instance switcher: the
 * duplicated pickers are what produced the flip/flop, where moving between AI Projects
 * and AI Builder silently changed which instance you were editing.
 */
namespace app;

use \Flight as Flight;
use RedBeanPHP\R;

class Projects extends BaseControls\Control {

    /** The picker. Search and sort are client-side; the list is small by nature. */
    public function index($params = []): void {
        if (!$this->requireLogin()) return;

        $memberId = (int) $this->member->id;
        $projects = [];
        foreach (ProjectContext::accessible($memberId) as $inst) {
            $projects[] = $this->card($inst, $memberId);
        }
        // Alphabetical by display name — the picker is for recognition, not recency.
        usort($projects, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $current = ProjectContext::current($memberId);

        // Where picking a project should DROP YOU. You came here to work, so the answer is
        // the build surface, not the dashboard — the dashboard is where you land at login,
        // and routing through it again means a second click to get to the thing you just
        // chose a project for.
        //
        // Conditional, because /sidecar/app/workbench bounces to the dashboard with an
        // error flash for a member without the feature. Sending someone to a door that
        // turns them away is worse than the extra click.
        $workUrl = $this->pluginUrl('workbench') ?: '/dashboard';

        // The project you are ON gets its own row, with what it is actually doing. The
        // grid answers "which one?"; this answers "how is it going?" — which is the
        // question you have once you have already chosen.
        $currentCard = null;
        if ($current) {
            foreach ($projects as $p) {
                if ($p['id'] === (int) $current->id) { $currentCard = $p; break; }
            }
            if ($currentCard) {
                $currentCard['build'] = $this->buildState((string) $current->slug, (string) ($current->app ?: 'tiknix'));
                $currentCard['links'] = array_values(array_filter([
                    ['url' => '/connections', 'label' => 'Connections', 'icon' => 'plug'],
                    ($u = $this->pluginUrl('pipelines')) ? ['url' => $u, 'label' => 'Pipelines', 'icon' => 'diagram-2'] : null,
                    ($u = $this->pluginUrl('workbench')) ? ['url' => $u, 'label' => 'Builder', 'icon' => 'hammer'] : null,
                ]));
            }
        }

        $this->render('projects/index', [
            'title'       => 'Projects',
            'projects'    => $projects,
            'currentId'   => $current ? (int) $current->id : 0,
            'currentCard' => $currentCard,
            'workUrl'     => $workUrl,
        ]);
    }

    /** A sidecar's launch URL, or '' when this member cannot open it. */
    private function pluginUrl(string $name): string {
        if (!class_exists('\\app\\Sidecar\\Registry')) return '';
        if (!isset(\app\Sidecar\Registry::launchable()[$name])) return '';
        return \app\Feature::isEnabled($name, (int) $this->member->id, (int) $this->member->level)
            ? '/sidecar/app/' . $name : '';
    }

    /**
     * What the selected project's builder is doing, read from that project's OWN
     * workbench.db — the file the task board reads.
     *
     * Read-only, with a plain PDO rather than RedBean: this is a web request whose
     * connection belongs to core, and pointing the ORM at another database mid-request to
     * read three numbers is how plans ended up written to the wrong one twice today.
     * Every failure is silent by design — a project with no builds yet has no file, and
     * that is not an error worth showing anyone.
     */
    private function buildState(string $slug, string $app): array {
        $out = ['plan' => '', 'status' => '', 'done' => 0, 'total' => 0, 'running' => [], 'at' => ''];
        $file = '/var/www/html/default/' . $slug . '.' . $app . '/data/workbench.db';
        if (!is_file($file)) return $out;

        try {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $plan = $pdo->query('SELECT id, title, plan_status, status, updated_at
                                 FROM workbenchtask WHERE parent_task_id IS NULL
                                 ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
            if (!$plan) return $out;

            $out['plan']   = (string) $plan['title'];
            $out['status'] = (string) ($plan['plan_status'] ?: $plan['status']);
            $out['at']     = (string) ($plan['updated_at'] ?? '');

            $st = $pdo->prepare('SELECT status, COUNT(*) c FROM workbenchtask
                                 WHERE parent_task_id = ? GROUP BY status');
            $st->execute([(int) $plan['id']]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out['total'] += (int) $row['c'];
                if (preg_match('/merged|completed|done/i', (string) $row['status'])) $out['done'] += (int) $row['c'];
            }

            $st = $pdo->prepare('SELECT title FROM workbenchtask
                                 WHERE parent_task_id = ? AND status = ? ORDER BY id LIMIT 3');
            $st->execute([(int) $plan['id'], 'running']);
            $out['running'] = array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable $e) { /* no board yet, or mid-write; the row just shows less */ }

        return $out;
    }

    /**
     * Create a project, then immediately select it.
     *
     * Creation belongs here rather than in a sidecar: the sidecars now work on whatever
     * project is selected, so a "new instance" button inside one would create something
     * you were not yet working on. Create-and-select keeps the loop closed — you leave
     * this page already inside the thing you just made.
     */
    public function create($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $memberId = (int) $this->member->id;
        $res = (new ProvisionService())->create($memberId, [
            'slug'    => (string) $this->getParam('slug', ''),
            'name'    => (string) $this->getParam('name', ''),
            'engine'  => (string) $this->getParam('engine', 'claude'),
            'is_root' => (int) $this->member->level === LEVELS['ROOT'],
        ]);
        if (empty($res['ok'])) {
            $this->jsonError((string) ($res['error'] ?? 'Could not create the project.'), (int) ($res['code'] ?? 400));
            return;
        }

        // Select it straight away — the point of creating one is to work on it.
        $id = (int) ($res['id'] ?? 0);
        if ($id > 0) ProjectContext::set($memberId, $id);

        // A project can come out usable-but-incomplete — a broker key that could not be
        // written leaves it unable to publish. Say so here, plainly, rather than letting
        // it be discovered later at the first publish.
        $warning = (string) ($res['warning'] ?? '');
        if ($warning !== '') $this->logger->warning('project created with a warning', ['id' => $id, 'warning' => $warning]);

        $this->jsonSuccess(['id' => $id, 'slug' => (string) ($res['slug'] ?? ''), 'warning' => $warning],
            $warning !== '' ? $warning : 'Created and selected. Provisioning can take a minute.');
    }

    /**
     * Delete a project, permanently.
     *
     * This lives here because the picker is where a project's whole life is visible —
     * it could be created here but only removed from inside the builder's danger zone,
     * which meant the one place that lists your projects was the one place that could
     * not get rid of one.
     *
     * The teardown itself is ProvisionService::delete: it kills the jailed session,
     * unlinks connectors, archives the folder to a zip and then trashes the registry
     * row. Two guards are its, not ours, and are worth naming: you must own the project
     * (or be ROOT), and you must type its domain back exactly. The (default) instance
     * cannot be deleted at all.
     */
    public function delete($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $memberId = (int) $this->member->id;
        $id       = (int) $this->getParam('id', 0);

        $res = (new ProvisionService())->delete($memberId, [
            'id'      => $id,
            'confirm' => (string) $this->getParam('confirm', ''),
            'is_root' => (int) $this->member->level === LEVELS['ROOT'],
        ]);
        if (empty($res['ok'])) {
            $this->jsonError((string) ($res['error'] ?? 'Could not delete the project.'), (int) ($res['code'] ?? 400));
            return;
        }

        // If it was the one you were working on, stop saying so. Reading the selection is
        // enough — ProjectContext forgets a project that no longer exists — but it has to
        // be read HERE, or the next page still renders a chip for a project that is gone.
        ProjectContext::current($memberId);

        $this->logger->info('project deleted', ['id' => $id, 'slug' => $res['slug'] ?? '', 'member' => $memberId]);
        $this->jsonSuccess(['slug' => (string) ($res['slug'] ?? ''), 'steps' => $res['steps'] ?? []],
            'Deleted ' . ($res['domain'] ?? 'the project') . '.');
    }

    /** Choose the project to work on. Everything else follows from this. */
    public function select($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $id = (int) $this->getParam('id', 0);
        if (!ProjectContext::set((int) $this->member->id, $id)) {
            $this->jsonError('That project is not available to you.', 403);
            return;
        }
        $inst = ProjectContext::current((int) $this->member->id);
        $this->jsonSuccess(['id' => (int) $inst->id, 'slug' => (string) $inst->slug],
            'Now working on ' . ($inst->displayName ?: $inst->slug) . '.');
    }

    /**
     * One card's worth of data.
     *
     * Sourced from where the truth actually lives rather than from columns that would
     * have to be kept in sync: the working copy for authorship, the registry for
     * hosting, the team tables for people. Everything is best-effort — a project whose
     * directory has gone missing must still render, because that is precisely when you
     * need to see it.
     */
    private function card(object $inst, int $memberId): array {
        $dir  = '/var/www/html/default/' . $inst->slug . '.tiknix';
        $last = $this->lastCommit($dir);
        $owned = (int) $inst->memberId === $memberId;

        return [
            'id'           => (int) $inst->id,
            'slug'         => (string) $inst->slug,
            // Only what you may actually delete gets the affordance, and the phrase comes
            // from the service that will check it. The (default) instance is exempt: it is
            // this control plane, and deleting it is refused.
            'deletable'    => $owned && empty($inst->isDefault),
            'confirm'      => (new ProvisionService())->confirmPhrase((string) $inst->slug),
            'name'         => (string) ($inst->displayName ?: $inst->slug),
            'owned'        => $owned,
            'status'       => (string) $inst->status,
            'created'      => (string) $inst->createdAt,
            // Hosting: a container is the strongest signal of "published"; fall back to
            // nothing rather than inventing a date we cannot substantiate.
            'hostedDomain' => (string) ($inst->ctDomain ?: ''),
            'published'    => $inst->ctVmid ? 'container ' . (int) $inst->ctVmid : '',
            'lastUpdate'   => $last['when'],
            'lastBy'       => $last['who'],
            'lastSubject'  => $last['subject'],
            'teams'        => $this->teams($inst),
        ];
    }

    /** Authorship from the instance's working copy — the AI Builder commits here. */
    private function lastCommit(string $dir): array {
        $out = ['when' => '', 'who' => '', 'subject' => ''];
        if (!is_dir($dir . '/.git')) return $out;
        $raw = [];
        exec('git -C ' . escapeshellarg($dir) . ' log -1 --format=%aI%x1f%an%x1f%s 2>/dev/null', $raw);
        if (empty($raw[0])) return $out;
        $parts = explode("\x1f", $raw[0]);
        return ['when' => $parts[0] ?? '', 'who' => $parts[1] ?? '', 'subject' => $parts[2] ?? ''];
    }

    /** Teams this project is shared with, for the "who else is on this" link. */
    private function teams(object $inst): array {
        $out  = [];
        $rows = R::find('instance_team', 'instance_id = ?', [(int) $inst->id]);
        foreach ($rows as $row) {
            $team = R::load('team', (int) $row->teamId);
            if (!$team->id) continue;
            $out[] = [
                'id'      => (int) $team->id,
                'name'    => (string) ($team->name ?: 'Team ' . $team->id),
                'members' => (int) R::count('teammember', 'team_id = ?', [(int) $team->id]),
            ];
        }
        return $out;
    }
}
