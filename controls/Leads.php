<?php
/**
 * Leads Controller
 * Admin-only view of leads captured from the public "Coming Soon" page.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\LeadSpamCheck;
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
     * run on a non-cached adapter after Bean::selectDatabase swaps), leaving stale
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
        foreach (Bean::getAll('SELECT first_name, last_name FROM lead') as $r) {
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
            ['db' => null,         'orderable' => false], // 4  Invite state (derived)
            ['db' => null,         'orderable' => false], // 5  Actions
        ];

        // Read once, outside the row callback, instead of a query per rendered row.
        $invites = $this->inviteStates();

        $resp = DataTableResponse::build('lead', $columns, $this->getParams(), [
            'globalCols' => ['first_name', 'last_name', 'email'],
            'row' => function (array $r) use ($invites): array {
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

                // Invite state comes from the invite table, never from a column on the
                // lead — a copy would go stale the moment an invite is revoked or expires.
                $state  = $invites[strtolower(trim((string)($r['email'] ?? '')))] ?? '';
                $badges = [
                    'accepted' => ['success',           'bi-person-check', 'Joined'],
                    'pending'  => ['info',              'bi-envelope-check', 'Invited'],
                    'expired'  => ['secondary',         'bi-hourglass-bottom', 'Invite expired'],
                    'revoked'  => ['secondary',         'bi-slash-circle', 'Invite revoked'],
                ];
                if (isset($badges[$state])) {
                    [$colour, $icon, $label] = $badges[$state];
                    $stateCell = '<span class="badge bg-' . $colour . '-subtle text-' . $colour
                        . '-emphasis border border-' . $colour . '-subtle"><i class="bi ' . $icon
                        . '"></i> ' . h($label) . '</span>';
                } else {
                    $stateCell = '<span class="text-secondary small">—</span>';
                }

                // Offer the invite only where it can do something: an address that has
                // already joined needs nothing, and a live invite is resent by the same
                // button rather than duplicated (Invite::create handles that).
                $inviteBtn = ($email !== '' && $state !== 'accepted')
                    ? '<button type="button" class="btn btn-sm btn-outline-success lead-invite-btn me-1"'
                      . ' data-id="' . $id . '" data-email="' . $email . '"'
                      . ' title="' . ($state === 'pending' ? 'Resend invitation' : 'Invite this lead to create an account')
                      . '"><i class="bi bi-person-plus"></i></button>'
                    : '';

                return [
                    h($first) . $flag,
                    h($last),
                    $email !== '' ? '<a href="mailto:' . $email . '">' . $email . '</a>' : '—',
                    '<span class="ui-mono small text-secondary">' . h($r['created_at'] ?? '') . '</span>',
                    $stateCell,
                    '<div class="text-end text-nowrap">' . $inviteBtn . $emailBtn . $deleteBtn . '</div>',
                ];
            },
        ]);

        Flight::json($resp);
    }

    /**
     * POST /leads/invite — turn a lead into a member by inviting them. JSON.
     *
     * A lead is an email address that asked to be told about the product; a member is an
     * account. The only supported way to cross that gap is the invitation flow, so this
     * adds no account-creation path of its own: Invite::create() applies the rules
     * (valid address, permission, already-a-member, an outstanding invite is RESENT
     * rather than duplicated, per-window allowance which admins bypass), Mailer sends the
     * same site-invite template, and /auth/invite creates the account and stamps
     * accepted_member_id. Nothing here writes the member table.
     *
     * The lead row is left untouched. Whether a lead was invited is not a fact about the
     * lead, it is a fact about the invite table, and storing a copy on the lead would be
     * a second version of the truth that goes stale the moment an invite is revoked or
     * expires. inviteStates() reads it from the invites themselves.
     */
    public function invite() {
        if (Flight::request()->method !== 'POST') { Flight::jsonError('POST required', 405); return; }
        if (!$this->validateCSRF()) return;

        $lead = Bean::load('lead', (int) $this->getParam('id', 0));
        if (!$lead->id) { Flight::jsonError('No such lead.', 404); return; }

        $email = strtolower(trim((string) $lead->email));
        if ($email === '') { Flight::jsonError('That lead has no email address to invite.', 409); return; }

        $r = Invite::create($email, (int) $this->member->id, (int) $this->member->level);
        if (empty($r['ok'])) { Flight::jsonError((string) $r['error'], 409); return; }

        $inv  = $r['invite'];
        $from = $this->member->displayName('Someone');

        // The invite EXISTS and its link works even if the mail fails, so report that
        // honestly and hand back the URL rather than calling the whole thing a failure.
        $sent = false;
        try {
            if (Mailer::isConfigured()) {
                $sent = Mailer::sendSiteInvite(
                    $email, $from, Invite::url($inv),
                    date('j M Y', strtotime((string) $inv->expiresAt)), ''
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Lead invite email failed', [
                'lead' => (int) $lead->id, 'invite' => (int) $inv->id, 'error' => $e->getMessage(),
            ]);
        }

        $inv->emailSent = $sent ? 1 : 0;
        if ($sent) $inv->emailSentAt = date('Y-m-d H:i:s');
        Bean::store($inv);
        $this->bustLeadCache();

        $this->logger->info('Lead invited', [
            'lead' => (int) $lead->id, 'invite' => (int) $inv->id,
            'to' => $email, 'by' => (int) $this->member->id, 'emailed' => $sent,
            'resend' => !empty($r['resend']),
        ]);

        Flight::jsonSuccess(
            ['invite_id' => (int) $inv->id, 'url' => Invite::url($inv), 'emailed' => $sent],
            !empty($r['resend'])
                ? $email . ' already had an open invitation — it has been sent again, and no allowance was used.'
                : ($sent
                    ? 'Invitation emailed to ' . $email . '. It expires on '
                      . date('j M Y', strtotime((string) $inv->expiresAt)) . '.'
                    : 'Invitation created but could NOT be emailed. Send them this link: ' . Invite::url($inv))
        );
    }

    /**
     * Invite state per lead email, in ONE query rather than one per row.
     *
     * @return array<string,string> lowercased email => 'accepted'|'pending'|'expired'|'revoked'
     */
    private function inviteStates(): array {
        $out = [];
        foreach (Bean::find('invite') as $inv) {
            $email = strtolower(trim((string) $inv->email));
            if ($email === '') continue;
            $state = !empty($inv->acceptedAt) ? 'accepted'
                   : (!empty($inv->revokedAt) ? 'revoked'
                   : (strtotime((string) $inv->expiresAt) <= time() ? 'expired' : 'pending'));
            // accepted always wins, then pending — a revoked or expired invite must not
            // hide the fact that the same address later joined.
            $rank = ['accepted' => 3, 'pending' => 2, 'expired' => 1, 'revoked' => 0];
            if (!isset($out[$email]) || $rank[$state] > $rank[$out[$email]]) $out[$email] = $state;
        }
        return $out;
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
