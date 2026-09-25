<?php
/**
 * Handoff — where a Get-started plan becomes a project (lib/PlanHandoff.php has the story).
 *
 *   POST /handoff/receive         the wizard's instance, with its broker key → {token, claim_url, state_url}
 *   GET  /handoff/state?token=    the wizard's status page → {status, project_slug, project_url}
 *   GET  /handoff/claim/<token>   the visitor: sign in / register, then "start a project from this plan?"
 *   POST /handoff/create          the signed-in member: name + engine → the project, PLAN.md committed
 *
 * receive and state are PUBLIC-reachable and self-authenticate (a broker key; the token
 * itself). claim is public because the visitor has no account yet. create is MEMBER.
 */
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Handoff extends Control {

    public function receive($params = []): void {
        if (Flight::request()->method !== 'POST') { Flight::jsonError('POST required', 405); return; }
        $key = BrokerService::keyFromRequest(true);
        if (!$key) { Flight::jsonError('Forbidden.', 403); return; }
        $instanceId = (int) ($key->instanceId ?? 0);
        if ($instanceId <= 0) { Flight::jsonError('This broker key is not bound to an instance.', 403); return; }
        $in = json_decode((string) Flight::request()->getBody(), true);
        if (!is_array($in)) { Flight::jsonError('A JSON body is required.', 400); return; }
        try {
            $h = PlanHandoff::offer($instanceId, $in);
        } catch (\InvalidArgumentException $e) {
            Flight::jsonError($e->getMessage(), 422); return;
        }
        $this->logger->info('handoff offered', ['token' => substr((string) $h->token, 0, 8) . '…', 'from_instance' => $instanceId, 'name' => (string) $h->name]);
        Flight::jsonSuccess(['token' => (string) $h->token, 'claim_url' => PlanHandoff::claimUrl($h), 'state_url' => PlanHandoff::stateUrl($h)]);
    }

    public function state($params = []): void {
        $h = PlanHandoff::byToken((string) $this->getParam('token', ''));
        if (!$h) { Flight::jsonError('No such hand-off.', 404); return; }
        Flight::jsonSuccess(PlanHandoff::state($h));
    }

    public function claim($params = []): void {
        $token = (string) ($this->opId() ?? '');
        $h = PlanHandoff::byToken($token);
        if (!$h) {
            Flight::response()->status(404);
            $this->render('handoff/claim', ['title' => 'Plan not found', 'state' => 'unknown', 'handoff' => null,
                'brief' => [], 'engines' => [], 'refusal' => null, 'project' => []]);
            return;
        }
        if (!Flight::isLoggedIn()) {
            // The token waits in the session so registration (which has no redirect of its
            // own) lands here too; login carries it explicitly as well.
            $_SESSION['handoff_token'] = $token;
            Flight::redirect('/auth/login?redirect=' . urlencode('/handoff/claim/' . $token));
            return;
        }
        $memberId = (int) $this->member->id;
        $refusal  = (string) $h->status === 'offered' ? ProjectQuota::refusalFor($memberId, 1) : null;
        $this->render('handoff/claim', [
            'title'    => 'Start a project from this plan',
            'state'    => (string) $h->status,           // offered | claimed
            'handoff'  => $h,
            'brief'    => json_decode((string) $h->briefJson, true) ?: [],
            'engines'  => EngineRegistry::menu(),
            'refusal'  => $refusal,
            'project'  => PlanHandoff::state($h),
        ]);
    }

    public function create($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $h = PlanHandoff::byToken((string) $this->getParam('token', ''));
        if (!$h) { $this->jsonError('No such hand-off.', 404); return; }
        try {
            $res = PlanHandoff::create((int) $this->member->id, $h, (string) $this->getParam('name', ''), (string) $this->getParam('engine', 'claude'));
        } catch (\Throwable $e) {
            $this->logger->error('handoff create failed', ['token' => substr((string) $h->token, 0, 8) . '…', 'err' => $e->getMessage()]);
            $this->jsonError('The project was not created: ' . $e->getMessage(), 500); return;
        }
        if (empty($res['ok'])) {
            if (!empty($res['action_url'])) {
                Flight::json(['success' => false, 'message' => (string) $res['error'], 'action_url' => (string) $res['action_url']], (int) ($res['code'] ?? 400));
                return;
            }
            $this->jsonError((string) ($res['error'] ?? 'Could not create the project.'), (int) ($res['code'] ?? 400)); return;
        }
        $this->logger->info('handoff claimed', ['token' => substr((string) $h->token, 0, 8) . '…', 'member' => (int) $this->member->id, 'slug' => $res['slug']]);
        Flight::jsonSuccess(['slug' => $res['slug'], 'url' => $res['url']], 'Project created — PLAN.md is committed.');
    }
}
