<?php
/**
 * Invites — send and manage invitations to a closed Tiknix.
 *
 * Public registration is off, so this is the only way anyone new gets in. That makes
 * sending a real permission rather than a convenience: it is gated on the `invites`
 * feature flag an admin switches on per member, and a granted member gets a small rolling
 * allowance. See app\Invite for the rules; this class is the door and the screen.
 *
 * The acceptance half lives in Auth (a logged-OUT visitor has to reach it), not here.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\Invite;
use \app\Feature;
use app\BaseControls\Control;

class Invites extends Control {

    public function __construct() {
        parent::__construct();

        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }

        // GRANTED, not merely logged in. Enforced here as well as by the authcontrol row,
        // because that row is data and a route with no row at all is reachable by default.
        // Gated on the GRANT only. The has-built condition is checked at send time and
        // explained on the page — bouncing a granted member with a 403 would tell them
        // nothing about the one thing they need to do to unlock it.
        if (!Feature::allows(Invite::FLAG, (int) $this->member->id, (int) $this->member->level)) {
            $this->logger->warning('Ungranted member attempted to reach invitations', [
                'member_id' => $this->member->id, 'member_level' => $this->member->level,
            ]);
            http_response_code(403);   // see Mcptools: status() alone is not flushed before exit
            Flight::renderView('error/403', ['title' => '403 - Forbidden']);
            exit;
        }
    }

    /** GET /invites — send an invitation, and see the ones you have sent. */
    public function index($params = []) {
        $mid   = (int) $this->member->id;
        $level = (int) $this->member->level;

        $this->viewData['title']     = 'Invitations';
        $this->viewData['isAdmin']   = Invite::isAdmin($level);
        $this->viewData['remaining'] = Invite::remaining($mid, $level);
        $this->viewData['perWindow'] = Invite::MAX_PER_WINDOW;
        $this->viewData['windowDays']= Invite::WINDOW_DAYS;
        $this->viewData['ttlDays']   = Invite::TTL_DAYS;
        $this->viewData['nextSlot']  = Invite::nextSlotAt($mid);
        // Empty when they may send. The page needs the REASON, not just a boolean: "ask an
        // admin" and "go build something" are different next steps.
        $this->viewData['blocked']   = Invite::blockedReason($mid, $level);
        $this->viewData['downline']  = Invite::downline($mid);
        $this->viewData['downlineN'] = Invite::downlineCount($mid);

        // An admin sees every invite (they are accountable for who is in the system);
        // everyone else sees only their own.
        try {
            $this->viewData['invites'] = Invite::isAdmin($level)
                ? Bean::find('invite', 'ORDER BY created_at DESC LIMIT 200')
                : Bean::find('invite', 'invited_by = ? ORDER BY created_at DESC LIMIT 100', [$mid]);
        } catch (\Throwable $e) {
            $this->viewData['invites'] = [];   // no invite table until the first one is sent
        }

        $this->render('invites/index', $this->viewData);
    }

    /** POST /invites/send */
    public function send($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/invites'); return; }
        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/invites');
            return;
        }

        $email = trim((string) $this->getParam('email', ''));
        $note  = trim((string) $this->getParam('note', ''));
        $mid   = (int) $this->member->id;

        $r = Invite::create($email, $mid, (int) $this->member->level);
        if (empty($r['ok'])) {
            $this->flash('error', (string) $r['error']);
            Flight::redirect('/invites');
            return;
        }

        $inv  = $r['invite'];
        // "X has invited you" — asked of the member, so it is the same name they are known
        // by everywhere else. Model_Member::displayName() carries the reason it is picky.
        $from = $this->member->displayName('Someone');

        // Mail can fail (Mailgun down, a bad address). The invite still EXISTS and its link
        // still works, so say what happened and show the link rather than pretending the
        // whole thing failed — the sender can paste it themselves.
        $sent = false;
        try {
            if (Mailer::isConfigured()) {
                $sent = Mailer::sendSiteInvite(
                    (string) $inv->email, $from, Invite::url($inv),
                    date('j M Y', strtotime((string) $inv->expiresAt)), $note
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Invite email failed', ['invite' => (int) $inv->id, 'error' => $e->getMessage()]);
        }

        $inv->emailSent = $sent ? 1 : 0;
        if ($sent) $inv->emailSentAt = date('Y-m-d H:i:s');
        if ($note !== '') $inv->note = mb_substr($note, 0, 500);
        Bean::store($inv);

        $this->logger->info('Invitation sent', [
            'invite' => (int) $inv->id, 'to' => $inv->email, 'by' => $mid, 'emailed' => $sent,
        ]);

        if (!empty($r['resend'])) {
            $this->flash('info', $inv->email . ' already had an open invitation — it has been sent again, and no allowance was used.');
        } elseif ($sent) {
            $this->flash('success', 'Invitation emailed to ' . $inv->email . '. It expires on '
                . date('j M Y', strtotime((string) $inv->expiresAt)) . '.');
        } else {
            $this->flash('error', 'The invitation was created but could NOT be emailed. '
                . 'Send them this link yourself: ' . Invite::url($inv));
        }
        Flight::redirect('/invites');
    }

    /** POST /invites/revoke — withdraw an unaccepted invitation. */
    public function revoke($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/invites'); return; }
        if (!Flight::csrf()->validateRequest()) { Flight::jsonError('Invalid CSRF token', 403); return; }

        $inv = Bean::load('invite', (int) $this->getParam('id', 0));
        if (!$inv->id) { Flight::jsonError('No such invitation.', 404); return; }

        // Yours, or anyone's if you are an admin.
        if ((int) $inv->invitedBy !== (int) $this->member->id && !Invite::isAdmin((int) $this->member->level)) {
            return $this->fail('That is not your invitation.', 403);
        }
        if ($inv->acceptedAt) { Flight::jsonError('That invitation has already been used.', 409); return; }

        $inv->revokedAt = date('Y-m-d H:i:s');
        Bean::store($inv);
        $this->logger->info('Invitation revoked', ['invite' => (int) $inv->id, 'by' => (int) $this->member->id]);

        // Revoking frees the slot — see Invite::usedInWindow. Correcting a typo should not
        // cost you an invite.
        Flight::jsonSuccess(['revoked' => (int) $inv->id], 'Invitation withdrawn — that allowance is back.');
    }

    // ---- leads → invitations (moved from Leads in RUNTIME-SPLIT-MAP.md step 2) ----

    /**
     * POST /invites/lead — turn a lead into a member by inviting them. JSON.
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
    public function lead() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
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
        \app\Leads::bustLeadCache();

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
    public static function leadStates(): array {
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
}
