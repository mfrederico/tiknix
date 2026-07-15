<?php
/**
 * Leads Controller
 * Admin-only view of leads captured from the public "Coming Soon" page.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\LeadSpamCheck;
use \RedBeanPHP\R;
use app\BaseControls\Control;

class Leads extends Control {

    const ADMIN_LEVEL = 50;

    public function __construct() {
        parent::__construct();

        // Must be logged in
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }

        // Must be an admin (level 50 or lower number = higher privilege)
        if ($this->member->level > self::ADMIN_LEVEL) {
            $this->logger->warning('Unauthorized leads access attempt', [
                'member_id' => $this->member->id,
                'member_level' => $this->member->level
            ]);
            Flight::redirect('/');
            exit;
        }
    }

    /**
     * List captured leads (newest first).
     */
    /**
     * The leads list is admin data where a deleted row MUST disappear at once.
     * The query cache doesn't reliably observe a cross-request DELETE (writes can
     * run on a non-cached adapter after R::selectDatabase swaps), leaving stale
     * rows on screen. Bust the lead table version before every read and after
     * every write so the view is always authoritative.
     */
    private function bustLeadCache(): void {
        $ad = Flight::get('cachedDatabaseAdapter');
        if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('lead');
    }

    public function index() {
        $this->bustLeadCache();

        // Count how many stored leads trip the bot-name heuristic so the view can
        // offer a one-click "purge flagged" action. Names only — cheap scan.
        $flagged = 0;
        foreach (R::getAll('SELECT first_name, last_name FROM lead') as $r) {
            if (LeadSpamCheck::isSuspicious($r['first_name'] ?? '', $r['last_name'] ?? '')) $flagged++;
        }

        $this->render('leads/index', [
            'title'   => 'Leads',
            'total'   => Bean::count('lead'),
            'flagged' => $flagged,
        ]);
    }

    /**
     * AJAX feed for the leads table — speaks the DataTables server-side protocol
     * via the shared DataTableResponse primitive (SQL paging / search / sort).
     * Column order MUST match the <thead> in views/leads/index.php.
     */
    public function data() {
        $this->bustLeadCache();   // never serve deleted leads from a stale cache

        $columns = [
            ['db' => 'first_name', 'search' => 'like'],   // 0  First Name
            ['db' => 'last_name',  'search' => 'like'],   // 1  Last Name
            ['db' => 'email',      'search' => 'like'],   // 2  Email
            ['db' => 'created_at', 'search' => null],     // 3  Signed Up
            ['db' => null,         'orderable' => false], // 4  Actions
        ];

        $resp = DataTableResponse::build('lead', $columns, $this->getParams(), [
            'globalCols' => ['first_name', 'last_name', 'email'],
            'row' => function (array $r): array {
                $id    = (int)($r['id'] ?? 0);
                $email = h($r['email'] ?? '');
                $first = (string)($r['first_name'] ?? '');
                $last  = (string)($r['last_name'] ?? '');
                $name  = trim($first . ' ' . $last);

                // Flag garbage/bot names (low vowel ratio / consonant mash) so an
                // admin can spot and delete them at a glance.
                $spam = LeadSpamCheck::evaluate($first, $last);
                $flag = $spam['suspicious']
                    ? ' <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"'
                      . ' title="' . h(implode('; ', $spam['reasons'])) . '"><i class="bi bi-robot"></i> likely bot</span>'
                    : '';

                $emailBtn = $email !== ''
                    ? '<button type="button" class="btn btn-sm btn-outline-primary lead-email-btn me-1"'
                      . ' data-email="' . $email . '" data-name="' . h($name) . '" title="Email lead">'
                      . '<i class="bi bi-envelope"></i></button>'
                    : '';
                $deleteBtn = '<button type="button" class="btn btn-sm btn-outline-danger lead-delete-btn"'
                    . ' data-id="' . $id . '" data-name="' . h($name !== '' ? $name : ($email !== '' ? $email : 'this lead')) . '"'
                    . ' title="Delete lead"><i class="bi bi-trash"></i></button>';

                return [
                    h($first) . $flag,
                    h($last),
                    $email !== '' ? '<a href="mailto:' . $email . '">' . $email . '</a>' : '—',
                    '<span class="ui-mono small text-secondary">' . h($r['created_at'] ?? '') . '</span>',
                    '<div class="text-end text-nowrap">' . $emailBtn . $deleteBtn . '</div>',
                ];
            },
        ]);

        Flight::json($resp);
    }

    /**
     * Delete a lead (JSON). Two modes:
     *   - id=<n>        delete one lead
     *   - mode=flagged  purge EVERY lead whose name trips the bot heuristic
     * Admin-only (enforced by the constructor) + CSRF.
     */
    public function delete() {
        if (Flight::request()->method !== 'POST') { Flight::jsonError('POST required', 405); return; }
        if (!$this->validateCSRF()) return;   // AJAX-aware: emits JSON 403 on failure

        if ((string)$this->getParam('mode', '') === 'flagged') {
            $deleted = 0;
            foreach (Bean::findAll('lead') as $lead) {
                if (LeadSpamCheck::isSuspicious($lead->firstName, $lead->lastName)) {
                    Bean::trash($lead);
                    $deleted++;
                }
            }
            $this->bustLeadCache();
            $this->logger->info('Leads purged (flagged as bot)', ['count' => $deleted, 'by' => $this->member->id]);
            Flight::jsonSuccess(['deleted' => $deleted], $deleted . ' flagged lead' . ($deleted === 1 ? '' : 's') . ' deleted.');
            return;
        }

        $id   = (int)$this->getParam('id', 0);
        $lead = $id ? Bean::load('lead', $id) : null;
        if (!$lead || !$lead->id) { Flight::jsonError('Lead not found', 404); return; }
        Bean::trash($lead);
        $this->bustLeadCache();
        $this->logger->info('Lead deleted', ['lead_id' => $id, 'by' => $this->member->id]);
        Flight::jsonSuccess(['deleted' => 1], 'Lead deleted.');
    }
}
