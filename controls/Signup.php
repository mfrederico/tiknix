<?php
/**
 * Signup — the PLATFORM's own sign-up gate (RUNTIME-SPLIT-MAP.md step 2: moved out of Auth,
 * which is the runtime's login/registration for any app):
 *
 *   GET|POST /signup/invite?token=…   accept a tiknix invitation and create the account
 *   GET      /signup/complete?token=… card-on-file sign-up: wait for the billing service
 *
 * Auth::register hands a sign-up here through Auth::$registerVia when card-on-file is on.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;

class Signup extends BaseControls\Control {

    /**
     * GET|POST /signup/invite?token=… — accept an invitation and create the account.
     *
     * This route deliberately IGNORES registrationEnabled(). An invite is not a way to
     * open the front door; it is its own key, and sign-ups staying closed is the whole
     * point of having invites at all.
     *
     * The email comes from the invite and is NOT editable. A link forwarded to a friend
     * therefore still only ever creates the account it was addressed to — the token proves
     * possession of the link, the bound email is what makes it an invitation to a person.
     */
    public function invite($params = []) {
        if (Flight::isLoggedIn()) { Flight::redirect('/dashboard'); return; }

        $request = Flight::request();
        $token   = (string) ($request->query->token ?? $request->data->token ?? '');

        $r = \app\Invite::resolve($token);
        if (!$r['invite']) {
            $this->render('signup/invite', ['title' => 'Invitation', 'fatal' => $r['error']]);
            return;
        }
        $inv   = $r['invite'];
        $email = (string) $inv->email;

        if ($request->method !== 'POST') {
            $this->render('signup/invite', ['title' => 'Accept your invitation', 'invite' => $inv, 'email' => $email]);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->render('signup/invite', ['title' => 'Accept your invitation', 'invite' => $inv,
                'email' => $email, 'errors' => ['Your session expired — please try again.']]);
            return;
        }

        $username = trim((string) $this->getParam('username', ''));
        $password = (string) $this->getParam('password', '');
        $confirm  = (string) $this->getParam('password_confirm', '');

        $errors = [];
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            $errors[] = 'Choose a username of 3–32 letters, numbers, dots, dashes or underscores.';
        } elseif (Bean::findOne('member', 'LOWER(username) = ?', [strtolower($username)])) {
            $errors[] = 'That username is already taken.';
        }
        if (strlen($password) < 8)      $errors[] = 'Your password must be at least 8 characters.';
        if ($password !== $confirm)     $errors[] = 'The two passwords do not match.';

        if ($errors) {
            $this->render('signup/invite', ['title' => 'Accept your invitation', 'invite' => $inv,
                'email' => $email, 'errors' => $errors, 'username' => $username]);
            return;
        }

        try {
            $member = Bean::dispense('member');
            $member->email     = $email;              // from the INVITE, never from the form
            $member->username  = $username;
            $member->password  = password_hash($password, PASSWORD_DEFAULT);
            $member->level     = LEVELS['MEMBER'];
            $member->status    = 'active';
            $member->planTier  = 'free';
            $member->createdAt = date('Y-m-d H:i:s');
            // ACCEPTING AN INVITE IS A LOGIN. The line below signs them straight in, so
            // the same two columns dologin() and GoogleAuth stamp have to be stamped here
            // too. They were not, and an invited member therefore read "Last login: Never,
            // Logins: 0" on /admin/editMember for as long as that first session lasted —
            // about a real person who was signed in and building at that moment.
            $member->lastLogin  = date('Y-m-d H:i:s');
            $member->loginCount = 1;
            $id = (int) Bean::store($member);

            /* An invited member is a billing subject like any other — per-member billing,
               their own tenant, their own invoice. Registered here so an invite produces a
               complete account rather than one that has to be repaired later.
               Deliberately NOT fatal: nobody is turned away from an invitation because a
               billing service is unreachable. The failure is logged as an ERROR naming the
               member, and the billing page registers one on demand for anyone who slipped
               through. */
            \app\SignupFlow::ensureTenantFor($id);

            \app\Invite::markAccepted($inv, $id);

            // An invited member arrives able to BUILD. Being invited to a place where you
            // then have to ask for permission to do the thing you were invited for is a
            // poor welcome; the other capabilities stay off until an admin grants them.
            \app\Feature::setEnabled('workbench', true, $id);

            Flight::get('log')->info('Invitation accepted', [
                'invite' => (int) $inv->id, 'member' => $id, 'invited_by' => (int) $inv->invitedBy,
            ]);

            $_SESSION['member'] = $member->export();
            $_SESSION['member']['id'] = $id;

            $this->flash('success', 'Welcome to ' . Flight::get('app.name') . ' — your account is ready.');
            Flight::redirect('/dashboard');
        } catch (\Throwable $e) {
            Flight::get('log')->error('Invitation accept failed: ' . $e->getMessage());
            $this->render('signup/invite', ['title' => 'Accept your invitation', 'invite' => $inv,
                'email' => $email, 'errors' => ['Something went wrong creating your account. Please try again.']]);
        }
    }

    /**
     * GET /auth/complete?token=… — the card step, and the account creation behind it.
     *
     * Reached only when card-on-file signup is on. Three states, and the page shows one:
     *
     *   - still waiting  → the card form link, and a Check again button
     *   - confirmed      → the member now exists; sign them in and go to the builder
     *   - error/expired  → say so plainly, and offer to start again
     *
     * PUBLIC by necessity: there is no account yet, so there can be no session. The token
     * in the URL is a lookup key, NOT a credential — holding it grants nothing, because
     * SignupFlow::complete decides on what the billing service says about the card, not on
     * who is asking. A guessed token reaches somebody else's unfinished signup and gets
     * exactly what its owner would: a card form for a Stripe customer with no card.
     */
    public function complete() {
        if (!SignupFlow::enabled()) {
            // Not a 404: a stale bookmark from a period when this was on should say what
            // happened rather than look broken.
            $this->render('signup/complete', [
                'title' => 'Sign up',
                'state' => 'error',
                'error' => 'Card-on-file signup is not enabled. Please register normally.',
                'portalUrl' => '', 'token' => '',
            ]);
            return;
        }

        $token  = trim((string) (Flight::request()->query->token ?? ''));
        $result = SignupFlow::complete($token);

        if ($result['ok']) {
            $member = Bean::load('member', (int) $result['member_id']);
            if (!$member->id) {
                // complete() said it created one. If it is not here, something is wrong in
                // a way that must not be papered over with a login attempt.
                Flight::get('log')->error('Auth::complete — member reported created but not found', [
                    'member_id' => (int) $result['member_id'],
                ]);
                $this->render('signup/complete', [
                    'title' => 'Sign up', 'state' => 'error', 'token' => $token, 'portalUrl' => '',
                    'error' => 'Your account was set up but we could not sign you in. Please use the login page.',
                ]);
                return;
            }

            session_regenerate_id(true);   // new account, new session id
            $_SESSION['member'] = $member->export();
            $_SESSION['member']['id'] = (int) $member->id;

            $this->flash('success', 'Welcome to ' . Flight::get('app.name') . '! Your card is on file — '
                                  . 'your first project is free.');
            Flight::redirect(\app\PlanHandoff::afterLoginTarget() ?? '/dashboard');
            return;
        }

        if ($result['pending']) {
            $this->render('signup/complete', [
                'title'     => 'Add your card',
                'state'     => 'waiting',
                'error'     => '',
                'token'     => $token,
                'portalUrl' => SignupFlow::portalUrlForToken($token),
            ]);
            return;
        }

        $this->render('signup/complete', [
            'title' => 'Sign up', 'state' => 'error', 'token' => $token, 'portalUrl' => '',
            'error' => $result['error'],
        ]);
    }
}
