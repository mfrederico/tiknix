<?php
/**
 * Explorer — core-side launch point for the Architecture Explorer sidecar.
 *
 * The Explorer itself is a separate, gated app at explorer.tiknix.com. This
 * controller is the ONLY explorer surface on core: it gates the member (login +
 * the `explorer` feature grant), mints a short-lived signed handoff token
 * (lib/ExplorerToken, shared `[explorer] sso_secret`), and redirects into the
 * sidecar's SSO consume endpoint. Everything else lives on the sidecar.
 *
 * authcontrol: explorer::* = 100 (MEMBER) — eligibility. The feature grant +
 * ownership scoping (enforced on the sidecar) decide actual access.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Explorer extends Control {

    /** GET /explorer/launch — gate, mint, redirect to the sidecar. */
    public function launch($params = []) {
        if (!$this->requireLogin()) return;

        $memberId = (int) ($this->member->id ?? 0);
        $level    = (int) ($this->member->level ?? 101);

        if (!Feature::isEnabled('explorer', $memberId, $level)) {
            $this->flash('error', 'The Architecture Explorer is not enabled for your account.');
            Flight::redirect('/dashboard');
            return;
        }

        $secret = (string) (Flight::get('explorer.sso_secret') ?? '');
        $base   = rtrim((string) (Flight::get('explorer.url') ?? ''), '/');
        if ($secret === '' || $base === '') {
            $this->flash('error', 'The Architecture Explorer is not configured on this server yet.');
            Flight::redirect('/dashboard');
            return;
        }

        $token = ExplorerToken::mint([
            'member_id' => $memberId,
            'level'     => $level,
            'email'     => (string) ($this->member->email ?? ''),
            'feature'   => 'explorer',
        ], $secret, 'explorer');

        Flight::redirect($base . '/sso/consume?token=' . urlencode($token));
    }
}
