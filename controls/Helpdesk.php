<?php
/**
 * Helpdesk — a signed-in member's support desk on the PLATFORM: their tickets, and a form
 * that opens a conversation with support (Support::open) about one of their projects.
 * Split out of Contact in RUNTIME-SPLIT-MAP.md step 2: an app's /contact is its own public
 * form; this desk is the control plane's (projects, ProjectContext). Contact sends a
 * signed-in member here (Contact::$memberDesk, set in lib/controlplane.php).
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Helpdesk extends BaseControls\Control {

    public function index() {
        if (!$this->requireLogin()) return;
        $this->render('helpdesk/index', [
            'title'   => 'Support',
            'project' => $this->projectParam((string) $this->getParam('project', '')) ?? ProjectContext::current((int) $this->member->id),
            'tickets' => array_map(fn($c) => [
                'ticket' => $c,
                'thread' => (int) (Bean::findOne('thread', 'related_type = ? AND related_id = ?', ['contact', (int) $c->id])->id ?? 0),
            ], array_values(Bean::find('contact', 'member_id = ? ORDER BY id DESC LIMIT 20', [(int) $this->member->id]))),
        ]);
    }

    /**
     * A project named by id or slug that this member can reach — the ticket is about it.
     * Anything else (unknown, someone else's) is null: it is never named on a ticket.
     */
    private function projectParam(int|string $ref): ?object {
        if ($ref === 0 || $ref === '') return null;
        $inst = is_int($ref) ? Bean::load('instance', $ref) : Bean::findOne('instance', 'slug = ?', [$ref]);
        if (!$inst || !$inst->id) return null;
        return ProjectContext::canAccess((int) $this->member->id, $inst) ? $inst : null;
    }

    /**
     * POST /helpdesk/ask — a signed-in member writes to support. Same queue as the public
     * form (/contact/admin, the Support badge, the alert email), but the member is seated
     * in the conversation, so the answer and any follow-up happen in Communications.
     * No Turnstile: they are signed in, and the form carries a CSRF token.
     */
    public function ask() {
        if (!$this->requireLogin()) return;
        if (!is_core_install()) { Flight::redirect('/helpdesk'); return; }
        if (!$this->validateCSRF()) return;
        $subject  = trim((string) $this->getParam('subject', ''));
        $message  = trim((string) $this->getParam('message', ''));
        $category = (string) $this->getParam('category', 'general');
        if ($subject === '' || $message === '') {
            $this->flash('error', 'A subject and a message are both needed.');
            Flight::redirect('/helpdesk');
            return;
        }
        $mid = (int) $this->member->id;
        try {
            $r = Support::open($mid, $subject, $message, $category, $this->projectParam((int) $this->getParam('about_project', 0)), 'app');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/helpdesk');
            return;
        }
        $threadId = $r['thread'];
        if (!$threadId) {
            // Stored and in the admin queue, but not a conversation the member can open.
            $this->flash('warning', 'Your message reached support, but its conversation could not be opened — you will get the answer by email.');
            Flight::redirect('/helpdesk');
            return;
        }
        $this->flash('success', 'Sent to support. The answer will appear here and in your email.');
        Flight::redirect('/communications/thread/' . $threadId);
    }
}
