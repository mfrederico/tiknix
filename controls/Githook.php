<?php
/**
 * Githook — GitHub's push notices for a project kept in step with a repository (app\GitSync).
 *
 *   POST /githook/push?t=<target>   PUBLIC at the route (seed 54); the request proves itself:
 *                                   X-Hub-Signature-256 is the HMAC of the body with the target's
 *                                   own webhook secret (Publish\GithubDriver::webhookSecret).
 *
 * A push to the target's branch starts a pull in the background (scripts/git-sync.php) and is
 * answered at once — GitHub waits ten seconds at most. Any other branch (the platform's own
 * tiknix/request-* branches among them) and any other event are acknowledged and ignored. The
 * platform's own pushes come back here too; the pull finds nothing new and does nothing.
 */
// nav: none — GitHub posts here; people never open it

namespace app;

use \Flight as Flight;
use app\Publish\GithubDriver;

class Githook extends BaseControls\Control {

    public function push($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::jsonError('GitHub posts here.', 405); return; }
        $t = Bean::load('deploytarget', (int) $this->getParam('t', 0));
        if (!$t->id || (string) $t->driver !== GithubDriver::key()) { Flight::jsonError('No such repository connection.', 404); return; }
        $inst = Bean::load('instance', (int) $t->instanceRef);
        if (!$inst->id || !\Model_Instance::tenantRow($inst)) { Flight::jsonError('That project is gone.', 404); return; }

        $body = (string) file_get_contents('php://input');
        $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        try { $secret = GithubDriver::webhookSecret($inst); }
        catch (\Throwable $e) { $this->logger->error('Githook: the webhook secret could not be read', ['instance' => $inst->slug, 'error' => $e->getMessage()]); Flight::jsonError('Not ready.', 503); return; }
        if ($sig === '' || !hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $sig)) {
            $this->logger->warning('Githook: a notice with a wrong signature was refused', ['instance' => $inst->slug, 'target' => (int) $t->id]);
            Flight::jsonError('Signature does not match.', 401);
            return;
        }

        $event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
        if ($event === 'ping') { Flight::jsonSuccess([], 'Connected.'); return; }
        if ($event !== 'push') { Flight::jsonSuccess([], "Ignored: {$event}."); return; }
        $p = json_decode($body, true);
        if (!is_array($p)) { Flight::jsonError('Not JSON — set the webhook\'s content type to application/json.', 400); return; }
        $branch = (string) ((json_decode((string) $t->configJson, true) ?: [])['branch'] ?? 'main') ?: 'main';
        if ((string) ($p['ref'] ?? '') !== 'refs/heads/' . $branch) { Flight::jsonSuccess([], 'Ignored: another branch.'); return; }
        if (!empty($p['deleted'])) { Flight::jsonSuccess([], 'Ignored: the branch was deleted.'); return; }

        GitSync::launch($inst, 'pull');
        $this->logger->info('Githook: push to the branch, bringing it in', ['instance' => $inst->slug, 'after' => substr((string) ($p['after'] ?? ''), 0, 12), 'by' => (string) ($p['pusher']['name'] ?? '')]);
        Flight::json(['success' => true, 'message' => 'Bringing it in.'], 202);
    }
}
