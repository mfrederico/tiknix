<?php
/**
 * Changerequests — changes asked for on the sites of a member's projects (app\SiteChanges), for the project's team.
 *
 *   /changerequests              the requests of your projects (?status=new|building|done|declined)
 *   /changerequests/view?id=     one: what was asked, the screenshots, and Build it / Not going ahead / Done
 *   /changerequests/build        POST: into the project's Ask, to be planned
 *   /changerequests/decline      POST {reason}: not going ahead; the asker is sent the reason
 *   /changerequests/done         POST {note}: done; the asker is told
 *   /changerequests/shot?id=&n=  one screenshot
 *
 * Answering the asker is the request's conversation in Communications (an email conversation: a reply reaches them).
 * Linked from the sidebar when a member's projects have any (views/platform/chrome_prepare.php).
 * authcontrol: changerequests::* = MEMBER (services/Schema/Seeds/61_ChangeRequests.php); each request checks the project.
 */

namespace app;

use app\BaseControls\Control;
use Flight;

class Changerequests extends Control {

    public function index($params = []) {
        if (!$this->requireLogin()) return;
        $status = (string) $this->getParam('status', '');
        if ($status !== '' && !in_array($status, SiteChanges::STATES, true)) $status = '';
        $rows = SiteChanges::forMember((int) $this->member->id, (int) $this->member->level, $status);
        $names = [];
        foreach ($rows as $r) $names[(int) $r->instanceId] ??= (function ($id) { $i = Bean::load('instance', $id); return $i->id ? (string) ($i->displayName ?: $i->slug) : "#{$id}"; })((int) $r->instanceId);
        $this->render('changerequests/index', ['title' => 'Change requests', 'rows' => $rows, 'status' => $status, 'projects' => $names]);
    }

    public function view($params = []) {
        if (!$cr = $this->request()) return;
        $inst = Bean::load('instance', (int) $cr->instanceId);
        $by = (int) $cr->decidedRef > 0 ? Bean::load('member', (int) $cr->decidedRef) : null;
        $this->render('changerequests/view', ['title' => 'Change request', 'cr' => $cr, 'project' => $inst->id ? (string) ($inst->displayName ?: $inst->slug) : '(gone)',
            'decidedBy' => $by && $by->id ? $by->displayName('someone') : '']);
    }

    public function build($params = []) {
        if (!$cr = $this->request(true)) return;
        try {
            SiteChanges::build($cr, (int) $this->member->id);
            $this->flash('success', 'Sent to the Builder to be planned. ' . $cr->fromName . ' was told it is being built; it moves to Done by itself when the build finishes.');
        } catch (\Throwable $e) {
            $this->logger->error('Change request: Build it failed', ['id' => (int) $cr->id, 'error' => $e->getMessage()]);
            $this->flash('error', 'It was not sent to the Builder: ' . $e->getMessage());
        }
        Flight::redirect('/changerequests/view?id=' . (int) $cr->id);
    }

    public function decline($params = []) {
        if (!$cr = $this->request(true)) return;
        try {
            SiteChanges::decline($cr, (int) $this->member->id, (string) $this->getParam('reason', ''));
            $this->flash('success', $cr->fromName . ' was sent your reason.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        Flight::redirect('/changerequests/view?id=' . (int) $cr->id);
    }

    public function done($params = []) {
        if (!$cr = $this->request(true)) return;
        try {
            SiteChanges::done($cr, (int) $this->member->id, (string) $this->getParam('note', ''));
            $this->flash('success', 'Marked done; ' . $cr->fromName . ' was told.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        Flight::redirect('/changerequests/view?id=' . (int) $cr->id);
    }

    public function shot($params = []) {
        if (!$cr = $this->request()) return;
        $s = SiteChanges::shot($cr, (int) $this->getParam('n', 0));
        if (!$s) { Flight::halt(404, 'Not found'); return; }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $s[1]);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($s[0]));
        header('Cache-Control: private, max-age=3600');
        readfile($s[0]);
        exit;
    }

    /** The request named by ?id=, when this member may see it (a POST: and the CSRF token). */
    private function request(bool $post = false): ?object {
        if (!$this->requireLogin()) return null;
        if ($post && !$this->requirePost()) return null;   // the method, and the CSRF token
        $cr = Bean::load('changerequest', (int) $this->getParam('id', 0));
        if (!$cr->id || !SiteChanges::may($cr, (int) $this->member->id, (int) $this->member->level)) {
            $this->flash('error', 'That change request is not one of your projects\'.');
            Flight::redirect('/changerequests');
            return null;
        }
        return $cr;
    }
}
