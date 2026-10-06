<?php
/**
 * Knowledge — the platform knowledge base's desk (app\KnowledgeBase), for Tiknix's admins:
 * what agents are told when they ask about the platform, the candidates waiting for a person
 * (from answered support tickets and from lessons projects' agents wrote down), and a box to try
 * a question as an agent would ask it.
 *
 * Nothing reaches an agent until someone here publishes it. ADMIN (seed 45); each method checks.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Knowledge extends BaseControls\Control {

    private const SHOW = ['candidate' => 'Waiting for you', 'published' => 'Published', 'retired' => 'Retired', 'dismissed' => 'Dismissed'];

    /** GET /knowledge?show=&q= — the entries in one state, and what a question would be answered with. */
    public function index() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        $counts = array_fill_keys(array_keys(self::SHOW), 0);
        foreach (Bean::getAll('SELECT status, COUNT(*) n FROM kbentry GROUP BY status') as $r) if (isset($counts[$r['status']])) $counts[$r['status']] = (int) $r['n'];
        $show = (string) $this->getParam('show', '');
        if (!isset(self::SHOW[$show])) $show = $counts['candidate'] > 0 ? 'candidate' : 'published';
        $q = trim((string) $this->getParam('q', ''));
        $this->render('knowledge/index', [
            'title'   => 'Knowledge base',
            'show'    => $show,
            'labels'  => self::SHOW,
            'counts'  => $counts,
            'rows'    => array_values(Bean::find('kbentry', 'status = ? ORDER BY ' . ($show === 'published' ? 'hits DESC, ' : '') . 'id DESC', [$show])),
            'q'       => $q,
            // search(), not ask(): trying a question here must not count as an agent having been helped
            'answers' => $q !== '' ? KnowledgeBase::search($q, 5) : null,
            'tickets' => $this->answeredTickets(),
        ]);
    }

    /** GET /knowledge/edit?id= | ?ticket= — write or change an entry; POST saves it. */
    public function edit() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        $id = (int) $this->getParam('id', 0);
        $entry = $id ? Bean::load('kbentry', $id) : null;
        if ($id && !$entry->id) { $this->flash('error', "There is no entry #{$id}."); Flight::redirect('/knowledge'); return; }
        $problems = [];
        $form = $entry ? ['title' => (string) $entry->title, 'symptom' => (string) $entry->symptom, 'cause' => (string) $entry->cause, 'fix' => (string) $entry->fix, 'status' => (string) $entry->status]
                       : ['title' => '', 'symptom' => '', 'cause' => '', 'fix' => '', 'status' => 'published'];
        $from = '';
        if (!$entry && ($ticketId = (int) $this->getParam('ticket', 0)) > 0) {
            [$form, $from] = $this->fromTicket($ticketId, $form);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->validateCSRF()) return;
            foreach (array_keys($form) as $k) $form[$k] = trim((string) $this->getParam($k, ''));
            $from = trim((string) $this->getParam('source_note', ''));
            $problems = KnowledgeBase::problems($form);
            if (!$problems) {
                $saved = KnowledgeBase::save($id ?: null, $form + ($from !== '' ? ['source' => 'support', 'source_note' => $from] : []), (int) $this->member->id);
                $this->flash('success', $form['status'] === 'published' ? 'Published — agents that ask about this now get it.' : 'Saved.');
                Flight::redirect('/knowledge?show=' . rawurlencode($form['status']) . '#entry-' . $saved);
                return;
            }
        }
        $this->render('knowledge/edit', ['title' => $entry ? 'Edit entry' : 'New entry', 'entry' => $entry, 'form' => $form, 'problems' => $problems, 'from' => $from]);
    }

    /** POST /knowledge/status {id, to} — publish, retire, dismiss, or put back as a candidate. */
    public function status() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->requirePost() || !$this->validateCSRF()) return;
        $to = (string) $this->getParam('to', '');
        try {
            KnowledgeBase::setStatus((int) $this->getParam('id', 0), $to);
            $this->flash('success', ['published' => 'Published — agents that ask about this now get it.', 'retired' => 'Retired — agents no longer get it.',
                                     'dismissed' => 'Dismissed — it will not be offered again.', 'candidate' => 'Back with the candidates.'][$to] ?? 'Done.');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        Flight::redirect('/knowledge?show=' . rawurlencode((string) $this->getParam('back', 'candidate')));
    }

    /** POST /knowledge/delete {id} — remove an entry for good (a dismissed candidate could then be offered again). */
    public function delete() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->requirePost() || !$this->validateCSRF()) return;
        $b = Bean::load('kbentry', (int) $this->getParam('id', 0));
        if ($b->id) { Bean::trash($b); $this->flash('success', 'Deleted.'); }
        Flight::redirect('/knowledge?show=' . rawurlencode((string) $this->getParam('back', 'published')));
    }

    /**
     * Support tickets someone has answered, newest first — each one is a question that was real
     * and an answer a person wrote, which is what an entry is made of.
     * @return array<int,array{id:int,subject:string,project:string,at:string}>
     */
    private function answeredTickets(): array {
        $out = [];
        foreach (Bean::getAll("SELECT c.id, c.subject, c.project_slug, c.member_id, c.created_at, t.id AS thread FROM contact c JOIN thread t ON t.related_type = 'contact' AND t.related_id = c.id ORDER BY c.id DESC LIMIT 60") as $c) {
            $answered = (int) Bean::getCell('SELECT COUNT(*) FROM message WHERE thread_id = ? AND sender_member_id IS NOT NULL AND sender_member_id != ? AND sender_member_id != 0', [(int) $c['thread'], (int) $c['member_id']]);
            if (!$answered) continue;
            $out[] = ['id' => (int) $c['id'], 'subject' => (string) $c['subject'], 'project' => (string) $c['project_slug'], 'at' => (string) $c['created_at']];
            if (count($out) >= 8) break;
        }
        return $out;
    }

    /** A new entry's form started from a ticket: its subject and question, and the last thing support answered. */
    private function fromTicket(int $ticketId, array $form): array {
        $c = Bean::load('contact', $ticketId);
        if (!$c->id) { $this->flash('error', "There is no ticket #{$ticketId}."); return [$form, '']; }
        $thread = Bean::findOne('thread', 'related_type = ? AND related_id = ?', ['contact', $ticketId]);
        $answer = $thread ? Bean::findOne('message', 'thread_id = ? AND sender_member_id IS NOT NULL AND sender_member_id != ? AND sender_member_id != 0 ORDER BY id DESC', [(int) $thread->id, (int) $c->memberId]) : null;
        $form['title']   = (string) $c->subject;
        $form['symptom'] = trim((string) $c->message);
        $form['fix']     = $answer ? trim((string) ($answer->bodyPlain ?: strip_tags((string) $answer->content))) : '';
        $form['status']  = 'published';
        return [$form, "ticket #{$ticketId}" . ($c->projectSlug ? " ({$c->projectSlug})" : '')];
    }
}
