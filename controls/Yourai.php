<?php
/**
 * Yourai — /yourai, a member's own AI: connect it once (an API key), tested on the spot, and every project they own builds with
 * it (lib/MemberAi.php). Members' page, linked from the sidebar, the dashboard's first steps and the project bar.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Yourai extends Control {

    public function index($params = []): void {
        if (!$this->requireLogin()) return;
        $me = (int) $this->member->id;
        $chosenId = 0; $problem = '';
        try { $c = MemberAi::chosen($me); $chosenId = $c ? (int) $c->id : 0; } catch (\RuntimeException $e) { $problem = $e->getMessage(); }
        $mine = \Model_Modelconnection::forMember($me);
        $projects = [];
        foreach (MemberAi::projects($me) as $p) {
            $rep = json_decode((string) ($p->reportJson ?? ''), true) ?: [];
            $shared = json_decode((string) ($p->yourAiJson ?? ''), true) ?: [];
            $projects[] = ['id' => (int) $p->id, 'name' => (string) ($p->displayName ?: $p->slug), 'ready' => !empty($rep['agent_ready']),
                           'reported' => array_key_exists('agent_ready', $rep), 'yours' => $chosenId > 0 && (int) ($shared['connection'] ?? 0) === $chosenId];
        }
        $this->render('yourai/index', ['title' => 'Your AI', 'providers' => MemberAi::providers(), 'mine' => $mine, 'chosenId' => $chosenId,
                                       'problem' => $problem, 'projects' => $projects, 'form' => $_SESSION['yourai_form'] ?? [],
                                       'next' => SignInLink::safePath((string) $this->getParam('next', ''))]);   // where to go once it is connected (a plan to build)
        unset($_SESSION['yourai_form']);
    }

    /** POST: connect an AI from a key, test it; it becomes Your AI when it answers. */
    public function save($params = []): void {
        if (!$this->requireLogin() || !$this->validateCSRF()) return;
        $preset = (string) $this->getParam('preset', '');
        if ($preset === '') $preset = MemberAi::detect((string) $this->getParam('api_key', ''));
        try {
            $r = MemberAi::save((int) $this->member->id, $preset, (string) $this->getParam('api_key', ''), (string) $this->getParam('endpoint', ''),
                              (string) $this->getParam('model', ''), Flight::hasLevel(LEVELS['ROOT']));
        } catch (\InvalidArgumentException $e) {
            $_SESSION['yourai_form'] = ['preset' => $preset, 'endpoint' => (string) $this->getParam('endpoint', ''), 'model' => (string) $this->getParam('model', '')];
            $this->flash('error', $e->getMessage());
            Flight::redirect('/yourai'); return;
        }
        $next = SignInLink::safePath((string) $this->getParam('next', ''));
        if (!$r['ok']) {
            $this->flash('error', 'Saved, but it did not answer, so it is not Your AI yet: ' . $r['message']);
            Flight::redirect('/yourai' . ($next !== '' ? '?next=' . urlencode($next) : '')); return;
        }
        // It answered: the member's projects that have no AI working get it now (a project made before it was connected).
        $given = [];
        try { $conn = MemberAi::chosen((int) $this->member->id); } catch (\RuntimeException $e) { $conn = null; }
        if ($conn) foreach (MemberAi::projects((int) $this->member->id) as $p) {
            $rep = json_decode((string) ($p->reportJson ?? ''), true) ?: [];
            if (!array_key_exists('agent_ready', $rep) || !empty($rep['agent_ready'])) continue;
            $s = MemberAi::share($p, $conn);
            if ($s['ok']) $given[] = (string) ($p->displayName ?: $p->slug); else $this->flash('error', $s['message']);
        }
        $this->flash('success', 'It answered. This is Your AI now: new projects build with it.' . ($given ? ' ' . implode(', ', $given) . ' had no AI and ' . (count($given) === 1 ? 'uses' : 'use') . ' it now.' : ''));
        Flight::redirect($next !== '' ? $next : '/yourai');
    }

    /** POST: make one of my saved AIs Your AI. */
    public function choose($params = []): void {
        if (!$this->requireLogin() || !$this->validateCSRF()) return;
        $c = $this->mine((int) $this->getParam('id', 0));
        if (!$c) { $this->flash('error', 'That AI is not yours.'); Flight::redirect('/yourai'); return; }
        if ($p = $c->box()->runProblems()) { $this->flash('error', 'It cannot build: ' . implode('; ', $p) . '.'); Flight::redirect('/yourai'); return; }
        \Model_Modelconnection::choose((int) $this->member->id, (int) $c->id);
        $this->flash('success', "{$c->name} is Your AI now: new projects build with it.");
        Flight::redirect('/yourai');
    }

    /** POST: use Your AI on one project (id) or on all of mine (id=0). */
    public function apply($params = []): void {
        if (!$this->requireLogin() || !$this->validateCSRF()) return;
        $me = (int) $this->member->id;
        try { $c = MemberAi::chosen($me); } catch (\RuntimeException $e) { $this->flash('error', $e->getMessage()); Flight::redirect('/yourai'); return; }
        if (!$c) { $this->flash('error', 'Connect your AI first.'); Flight::redirect('/yourai'); return; }
        $want = (int) $this->getParam('id', 0);
        $done = []; $failed = [];
        foreach (MemberAi::projects($me) as $p) {   // only projects this member owns
            if ($want > 0 && (int) $p->id !== $want) continue;
            $r = MemberAi::share($p, $c);
            if ($r['ok']) $done[] = (string) ($p->displayName ?: $p->slug); else $failed[] = $r['message'];
        }
        if (!$done && !$failed) { $this->flash('error', 'That project is not yours, or it is not running yet.'); Flight::redirect('/yourai'); return; }
        if ($done) $this->flash('success', implode(', ', $done) . ' ' . (count($done) === 1 ? 'builds' : 'build') . " with {$c->name} now.");
        if ($failed) $this->flash('error', implode(' ', $failed));
        Flight::redirect((string) $this->getParam('back', '') === 'projects' ? '/projects' : '/yourai');
    }

    /** POST: forget one of my saved AIs. Projects that have it keep it until it is changed there. */
    public function remove($params = []): void {
        if (!$this->requireLogin() || !$this->validateCSRF()) return;
        $c = $this->mine((int) $this->getParam('id', 0));
        if (!$c) { $this->flash('error', 'That AI is not yours.'); Flight::redirect('/yourai'); return; }
        $me = (int) $this->member->id; $id = (int) $c->id; $name = (string) $c->name;
        try { $ch = \Model_Modelconnection::chosenFor($me); } catch (\RuntimeException $e) { $ch = null; }   // a broken choice: removing is how it is fixed
        if ($ch && (int) $ch->id === $id) \Model_Modelconnection::choose($me, 0);
        Bean::trash(Bean::load('modelconnection', $id));
        $this->flash('success', "{$name} removed. Projects already using it keep it until you change it there.");
        Flight::redirect('/yourai');
    }

    private function mine(int $id): ?object {
        $me = (int) $this->member->id;
        $c = \Model_Modelconnection::byId($id);
        return $c && (int) $c->memberId === $me ? $c : null;
    }
}
