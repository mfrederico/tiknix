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
        $this->render('projects/index', [
            'title'     => 'Projects',
            'projects'  => $projects,
            'currentId' => $current ? (int) $current->id : 0,
        ]);
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
