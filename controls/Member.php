<?php
namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\TwoFactorAuth;
use \app\EngineRegistry;
use \app\MemberEnginePrefs;
use \Exception as Exception;
use app\BaseControls\Control;

class Member extends Control {
    
    public function __construct() {
        parent::__construct();
        
        // Require login for all member pages
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
    }
    
    /**
     * Member profile page
     */
    public function profile($params = []) {
        $this->viewData['title'] = 'My Profile';
        $this->render('member/profile', $this->viewData);
    }
    
    /**
     * Edit profile
     */
    public function edit($params = []) {
        if (Flight::request()->method === 'POST') {
            // Validate CSRF token
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
            
            $request = Flight::request();
            $member = Bean::load('member', $this->member->id);
            
            // Validate input
            $email = trim($request->data->email ?? '');
            // What other people see: "X invited you", comments, team lists. Falls back to
            // first+last then username when blank, so leaving it empty is a valid choice.
            $display_name = trim($request->data->display_name ?? '');
            $first_name = trim($request->data->first_name ?? '');
            $last_name = trim($request->data->last_name ?? '');
            $bio = trim($request->data->bio ?? '');
            
            if (empty($email)) {
                $this->viewData['error'] = 'Email is required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->viewData['error'] = 'Invalid email format';
            } else {
                // Check for duplicate email (excluding current member)
                $existingEmail = Bean::findOne('member', 'email = ? AND id != ?', [$email, $member->id]);
                
                if ($existingEmail) {
                    $this->viewData['error'] = 'Email already exists';
                } else {
                    // Update allowed fields
                    $member->email = $email;
                    $member->displayName = $display_name !== '' ? $display_name : null;
                    $member->firstName = $first_name;
                    $member->lastName = $last_name;
                    $member->bio = $bio;
                }
            }
            
            // Change the password ONLY when a new one was actually typed.
            //
            // This used to trigger on current_password being present too, which sounds
            // harmless and is not: browsers and password managers autofill the "current
            // password" box on any form that has one. Someone editing only their NAME got
            // "Please enter a new password" from a field they never touched — and because
            // any error skips the save below, the name change was silently discarded.
            //
            // Entering a new password is the only thing that signals intent to change it.
            if (!empty($request->data->password)) {
                if (empty($request->data->current_password)) {
                    $this->viewData['error'] = 'Enter your current password to set a new one';
                } else if (!password_verify($request->data->current_password, $member->password)) {
                    $this->viewData['error'] = 'Current password is incorrect';
                } else if ($request->data->password !== $request->data->password_confirm) {
                    $this->viewData['error'] = 'New passwords do not match';
                } else if (strlen($request->data->password) < 8) {
                    $this->viewData['error'] = 'Password must be at least 8 characters';
                } else {
                    $member->password = password_hash($request->data->password, PASSWORD_DEFAULT);
                    $this->viewData['success'] = 'Profile and password updated successfully';
                }
            }
            
            if (empty($this->viewData['error'])) {
                $member->updatedAt = date('Y-m-d H:i:s');
                
                try {
                    Bean::store($member);
                    $_SESSION['member'] = $member->export();
                    $this->member = $member; // Update current member object
                    $this->viewData['member'] = $member; // Update view data with new member
                    if (empty($this->viewData['success'])) {
                        $this->viewData['success'] = 'Profile updated successfully';
                    }
                    $this->logger->info('Member profile updated', ['member_id' => $member->id]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to update member profile', [
                        'member_id' => $member->id,
                        'error' => $e->getMessage()
                    ]);
                    $this->viewData['error'] = 'Error updating profile: ' . $e->getMessage();
                }
            }
            }
        }
        
        $this->viewData['title'] = 'Edit Profile';
        $this->render('member/edit', $this->viewData);
    }
    
    /**
     * Member dashboard
     */
    public function dashboard($params = []) {
        $this->viewData['title'] = 'Dashboard';
        $this->render('member/dashboard', $this->viewData);
    }
    
    /**
     * Member settings
     */
    /**
     * May this member set Builder model preferences?
     *
     * Two conditions, both required. The tooling has to exist here at all
     * (builder_tools_enabled() is false on a published app, which has no Builder), and
     * the member has to be an admin — planning models are an operator's decision about
     * what a build costs and how good it is, not a per-user display preference.
     */
    private function canUseBuilderPrefs(): bool {
        return builder_tools_enabled() && (int) $this->member->level <= LEVELS['ADMIN'];
    }

    public function settings($params = []) {
        $request = Flight::request();
        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // AI engine/model tier preferences: enginepref[<engine>][<tier>] = <model>.
                // Handled explicitly (validated + normalized) and skipped by the generic loop.
                //
                // Gated on the SAME condition that decides whether to offer them below.
                // Hiding the fields in the view is not a gate — the POST handler is the
                // gate, and a form field that is merely absent from the page can still be
                // submitted by anyone who knows its name.
                $prefs = $this->canUseBuilderPrefs() ? ($request->data->enginepref ?? null) : null;
                if (is_array($prefs)) {
                    foreach ($prefs as $engine => $tiers) {
                        if (!is_array($tiers)) continue;
                        foreach ($tiers as $tier => $model) {
                            MemberEnginePrefs::set((int)$this->member->id, (string)$engine, (string)$tier, (string)$model);
                        }
                    }
                }
                // Per-engine API keys: enginetoken[<engine>] = <key>. Same gate as the tier
                // prefs — the POST handler is the gate, not the absence of a field.
                //
                // A submitted value that still looks like the mask we rendered means the
                // field was left untouched, so it must NOT be saved: re-encrypting "sk-z…6789"
                // would replace a working key with those literal characters. Only a real
                // edit or a deliberate clear reaches setToken().
                $tokens = $this->canUseBuilderPrefs() ? ($request->data->enginetoken ?? null) : null;
                if (is_array($tokens)) {
                    foreach ($tokens as $engine => $raw) {
                        if (is_array($raw)) continue;
                        $raw = trim((string) $raw);
                        if (str_contains($raw, '…')) continue;   // untouched mask
                        MemberEnginePrefs::setToken((int)$this->member->id, (string)$engine, $raw);
                    }
                }
                /* Save generic settings from an ALLOWLIST, not a denylist.
                   This loop used to write any posted key to the member's own settings row,
                   and Feature::stored() reads `feature.<flag>` rows from that same
                   member-scoped table — so a member could POST feature.mcp=1 (and invites,
                   email, every sidecar) and self-grant capabilities the admin never gave.
                   Only the preference keys the settings form actually offers are writable;
                   anything else — feature.*, plan_tier, level, a mistyped key — is dropped. */
                static $writableSettings = [
                    'date_format', 'timezone', 'email_notifications',
                    'newsletter', 'profile_visibility', 'show_email',
                ];
                foreach ($request->data as $key => $value) {
                    if (!in_array($key, $writableSettings, true)) continue;
                    Flight::setSetting($key, $value, $this->member->id);
                }
                $this->viewData['success'] = 'Settings saved successfully';
            }
        }

        // Selectable engines + each member's effective tier map, for the AI prefs section.
        // Uses menu() (available engines) so a closed-beta engine we can't use is hidden.
        //
        // Only for people who actually have a Builder. Which model plans a build is a
        // control-plane setting for the Builder sidecar; on a finished app it governs
        // nothing its members can reach, so offering it there is a control that does
        // nothing — and an invitation to change something they cannot see the effect of.
        $engines = [];
        $engineKeys = [];
        if ($this->canUseBuilderPrefs()) {
            foreach (array_keys(EngineRegistry::menu()) as $eng) {
                $engines[$eng] = MemberEnginePrefs::effective((int)$this->member->id, $eng);
                // Only engines that authenticate by key get a field. Anthropic's CLI signs
                // in with its own OAuth, so offering it a key box would invite people to
                // paste a credential nothing reads.
                $envVar = EngineRegistry::authTokenEnv($eng);
                if ($envVar !== '') {
                    $engineKeys[$eng] = [
                        'label'  => EngineRegistry::label($eng),
                        'env'    => $envVar,
                        'masked' => MemberEnginePrefs::maskedToken((int)$this->member->id, $eng),
                    ];
                }
            }
        }
        $this->viewData['ai_engines'] = $engines;
        $this->viewData['ai_engine_keys'] = $engineKeys;

        // Model connections (MODEL_CONNECTIONS_PLAN.md): the member's own endpoints + keys,
        // one of which may replace the platform's Claude in the builds they trigger.
        $this->viewData['mc'] = null;
        if (builder_tools_enabled()) {
            $mine = [];
            foreach (\Model_Modelconnection::forMember((int) $this->member->id) as $c) $mine[] = $this->mcSummary($c);
            $chosen = null;
            try { $ch = \Model_Modelconnection::chosenFor((int) $this->member->id); $chosen = $ch ? (int) $ch->id : null; }
            catch (\RuntimeException $e) { $this->viewData['error'] = $e->getMessage(); }
            $this->viewData['mc'] = [
                'connections' => $mine,
                'presets'     => \Model_Modelconnection::PRESETS,
                'chosen'      => $chosen,
                'is_root'     => Flight::hasLevel(LEVELS['ROOT']),
            ];
        }

        // Get user settings
        $this->viewData['settings'] = Bean::findAll('settings', 'member_id = ?', [$this->member->id]);

        // 2FA status
        $this->viewData['twofa_enabled'] = TwoFactorAuth::isEnabled($this->member);
        $this->viewData['twofa_required'] = TwoFactorAuth::isRequired($this->member);
        $this->viewData['twofa_required_reason'] = TwoFactorAuth::getRequiredReason($this->member);
        $this->viewData['recovery_code_count'] = TwoFactorAuth::getRemainingRecoveryCodeCount($this->member);

        $this->viewData['title'] = 'Settings';
        $this->render('member/settings', $this->viewData);
    }

    /* ---- model connections (MODEL_CONNECTIONS_PLAN.md) -------------------------- */

    /** What a page may see of a connection: never the key. */
    private function mcSummary($c): array {
        $m = $c->box();
        $out = ['id' => (int) $c->id, 'name' => (string) $c->name, 'preset' => (string) $c->preset,
                'protocol' => (string) $c->protocol, 'base_url' => (string) $c->baseUrl, 'auth' => (string) $c->auth,
                'key_status' => $m->keyStatus(), 'engine' => $m->engineName(),
                'last_test_at' => (string) $c->lastTestAt, 'last_test_ok' => (bool) $c->lastTestOk, 'last_test_msg' => (string) $c->lastTestMsg];
        foreach (\Model_Modelconnection::TIERS as $t) $out[$t . '_model'] = (string) ($c->{$t . 'Model'} ?? '');
        return $out;
    }

    /** The caller's own connection by posted id, or null (flash already set). */
    private function mcOwned(int $id) {
        $c = $id > 0 ? \Model_Modelconnection::byId($id) : null;
        if (!$c || (int) $c->memberId !== (int) $this->member->id) return null;
        return $c;
    }

    private function mcGate(bool $json = false): bool {
        if (!builder_tools_enabled()) { $json ? Flight::jsonError('Model connections are a builder feature.', 403) : Flight::redirect('/member/settings'); return false; }
        if (Flight::request()->method !== 'POST') { $json ? Flight::jsonError('POST only.', 405) : Flight::redirect('/member/settings'); return false; }
        if (!Flight::csrf()->validateRequest()) { $json ? Flight::jsonError('Invalid CSRF token.', 403) : $this->flash('error', 'Invalid CSRF token.'); if (!$json) Flight::redirect('/member/settings#models'); return false; }
        return true;
    }

    /** POST /member/modelsave — create or update one of my model connections. */
    public function modelsave($params = []) {
        if (!$this->mcGate()) return;
        $d = Flight::request()->data->getData();
        $id = (int) ($d['id'] ?? 0);
        $isRoot = Flight::hasLevel(LEVELS['ROOT']);
        if ($id > 0 && !$this->mcOwned($id)) { $this->flash('error', 'That model connection is not yours.'); Flight::redirect('/member/settings#models'); return; }
        if ($p = \Model_Modelconnection::problems($d, $isRoot, $id ?: null, (int) $this->member->id)) {
            $this->flash('error', 'Not saved: ' . implode('; ', $p) . '.');
            Flight::redirect('/member/settings#models');
            return;
        }
        $memberId = (int) $this->member->id;
        $raw = (string) ($d['api_key'] ?? '');
        $saved = \app\CoreDb::with(function () use ($id, $d, $memberId, $raw) {
            $c = $id > 0 ? \app\Bean::load('modelconnection', $id) : \app\Bean::dispense('modelconnection');
            $c->box()->fill($d, $memberId);
            if (!empty($d['clear_key'])) $c->box()->setKey('');
            elseif (trim($raw) !== '' && !str_contains($raw, '…')) $c->box()->setKey($raw);   // blank / the mask = keep
            return (int) \app\Bean::store($c);
        });
        if (!$saved) { $this->flash('error', 'Could not save: ' . \app\CoreDb::lastError()); Flight::redirect('/member/settings#models'); return; }
        $this->logger->info('Model connection saved', ['id' => $saved, 'member_id' => $memberId]);
        $this->flash('success', 'Model connection saved. Use Test to check it and list its models.');
        Flight::redirect('/member/settings#models');
    }

    /** POST /member/modeldelete — delete one of mine (clears it as my build connection if it was). */
    public function modeldelete($params = []) {
        if (!$this->mcGate()) return;
        $c = $this->mcOwned((int) (Flight::request()->data->id ?? 0));
        if (!$c) { $this->flash('error', 'That model connection is not yours.'); Flight::redirect('/member/settings#models'); return; }
        $memberId = (int) $this->member->id;
        try { $ch = \Model_Modelconnection::chosenFor($memberId); } catch (\RuntimeException $e) { $ch = null; }
        if ($ch && (int) $ch->id === (int) $c->id) \Model_Modelconnection::choose($memberId, 0);
        $id = (int) $c->id;
        \app\CoreDb::with(fn() => \app\Bean::trash(\app\Bean::load('modelconnection', $id)) ?? true);
        $this->flash('success', "Deleted '{$c->name}'." . ($ch && (int) $ch->id === $id ? ' Your builds use the platform\'s Claude again.' : ''));
        Flight::redirect('/member/settings#models');
    }

    /** POST /member/modelchoose — build with this connection (id) or the platform's Claude (0). */
    public function modelchoose($params = []) {
        if (!$this->mcGate()) return;
        $id = (int) (Flight::request()->data->id ?? 0);
        $memberId = (int) $this->member->id;
        if ($id > 0) {
            $c = $this->mcOwned($id);
            if (!$c) { $this->flash('error', 'That model connection is not yours.'); Flight::redirect('/member/settings#models'); return; }
            if ($p = $c->box()->runProblems()) { $this->flash('error', 'Cannot build with it: ' . implode('; ', $p) . '.'); Flight::redirect('/member/settings#models'); return; }
        }
        \Model_Modelconnection::choose($memberId, $id);
        $this->flash('success', $id > 0 ? "Your builds now run on '{$c->name}'." : 'Your builds run on the platform\'s Claude.');
        Flight::redirect('/member/settings#models');
    }

    /** POST /member/modeltest — list the endpoint's models and make one tiny call. JSON. */
    public function modeltest($params = []) {
        if (!$this->mcGate(true)) return;
        $c = $this->mcOwned((int) (Flight::request()->data->id ?? 0));
        if (!$c) { Flight::jsonError('That model connection is not yours.', 404); return; }
        try { $r = $c->box()->test(); }
        catch (\Throwable $e) { $r = ['ok' => false, 'message' => $e->getMessage(), 'models' => []]; }
        \app\CoreDb::with(fn() => \app\Bean::store($c));
        Flight::json($r);
    }

    /**
     * Danger zone — permanently close this account.
     *
     * Deprovisions every project the member owns, disbands the teams they own, then voids
     * their credentials and scrubs their PII so the login no longer works. The member ROW is
     * KEPT (soft-closed, id preserved) so billing history stays intact and referential —
     * "delete my account, but billing records stay". Irreversible from the member's side.
     */
    public function closeaccount($params = []) {
        $request = Flight::request();
        if ($request->method !== 'POST') { Flight::redirect('/member/settings'); return; }
        if (!Flight::csrf()->validateRequest()) { $this->jsonError('Invalid CSRF token', 400); return; }

        $member = Bean::load('member', (int) $this->member->id);
        if (!$member->id) { $this->jsonError('Account not found', 404); return; }
        // The platform owner cannot self-destruct the control plane by accident.
        if ((int) $member->level === LEVELS['ROOT']) {
            $this->jsonError('The owner account cannot be closed from here.', 403); return;
        }

        // Two proofs for an irreversible action: the exact account email, and the password.
        $typed = trim((string) ($request->data->confirm_email ?? ''));
        $pass  = (string) ($request->data->password ?? '');
        if (strcasecmp($typed, (string) $member->email) !== 0) {
            $this->jsonError('Type your account email exactly to confirm.', 400); return;
        }
        if ((string) $member->password === '' || !password_verify($pass, (string) $member->password)) {
            $this->jsonError('That password is not correct.', 403); return;
        }

        // 1) Deprovision every project they own (reports, never aborts on a single failure).
        $del = (new ProvisionService())->deleteAllForMember((int) $member->id);

        // 2) Disband teams they own; drop their memberships in others' teams.
        foreach (Bean::find('team', 'owner_id = ?', [(int) $member->id]) as $t) {
            foreach (Bean::find('teammember', 'team_id = ?', [(int) $t->id]) as $tm) Bean::trash($tm);
            Bean::trash($t);
        }
        foreach (Bean::find('teammember', 'member_id = ?', [(int) $member->id]) as $tm) Bean::trash($tm);

        // 3) Void credentials + scrub PII. The row stays so billing keeps its foreign key.
        $member->status        = 'closed';
        $member->password      = '';                 // no login is possible against ''
        $member->emailVerified = 0;
        $member->totpSecret    = '';
        $member->totpEnabled   = 0;
        $member->recoveryCodes = '';
        $member->email         = 'closed-' . $member->id . '@deleted.invalid';
        $member->username      = 'closed-' . $member->id;
        $member->firstName     = '';
        $member->lastName      = '';
        $member->displayName   = 'Closed account';
        $member->closedAt      = date('Y-m-d H:i:s');
        Bean::store($member);

        $this->logger->info('account closed', [
            'member'           => (int) $member->id,
            'projects_deleted' => count($del['deleted'] ?? []),
            'projects_failed'  => count($del['failed'] ?? []),
        ]);

        // 4) End the session — they are no longer anyone.
        session_destroy();

        $this->jsonSuccess([
            'projects_deleted' => $del['deleted'] ?? [],
            'projects_failed'  => $del['failed'] ?? [],
            'redirect'         => '/',
        ], 'Your account has been closed.');
    }

    /**
     * Start 2FA setup - generate secret and show QR code
     */
    public function setup2fa($params = []) {
        if (TwoFactorAuth::isEnabled($this->member)) {
            Flight::redirect('/member/settings');
            return;
        }

        // Generate new secret
        $secret = TwoFactorAuth::generateSecret();
        $_SESSION['2fa_setup_secret'] = $secret;

        // Generate QR code
        $qrCode = TwoFactorAuth::generateQrCode($secret, $this->member->email);

        $this->viewData['title'] = 'Setup Two-Factor Authentication';
        $this->viewData['secret'] = $secret;
        $this->viewData['qr_code'] = $qrCode;
        $this->render('member/setup2fa', $this->viewData);
    }

    /**
     * Verify and enable 2FA
     */
    public function enable2fa($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::redirect('/member/setup2fa');
            return;
        }

        $secret = $_SESSION['2fa_setup_secret'] ?? null;
        $code = trim($request->data->code ?? '');

        if (!$secret) {
            Flight::redirect('/member/setup2fa');
            return;
        }

        $result = TwoFactorAuth::enable($this->member, $secret, $code);

        if ($result['success']) {
            unset($_SESSION['2fa_setup_secret']);

            // Show recovery codes
            $this->viewData['title'] = 'Two-Factor Authentication Enabled';
            $this->viewData['recovery_codes'] = $result['recovery_codes'];
            $this->render('member/2fa_enabled', $this->viewData);
        } else {
            $this->viewData['error'] = $result['error'];
            $this->viewData['title'] = 'Setup Two-Factor Authentication';
            $this->viewData['secret'] = $secret;
            $this->viewData['qr_code'] = TwoFactorAuth::generateQrCode($secret, $this->member->email);
            $this->render('member/setup2fa', $this->viewData);
        }
    }

    /**
     * Disable 2FA
     */
    public function disable2fa($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::redirect('/member/settings');
            return;
        }

        // Require password confirmation
        $password = $request->data->password ?? '';

        if (!password_verify($password, $this->member->password)) {
            $_SESSION['flash_error'] = 'Incorrect password';
            Flight::redirect('/member/settings');
            return;
        }

        // Check if 2FA is required for this user
        if (TwoFactorAuth::isRequired($this->member)) {
            $_SESSION['flash_error'] = 'Two-factor authentication is required for your account and cannot be disabled';
            Flight::redirect('/member/settings');
            return;
        }

        TwoFactorAuth::disable($this->member);
        $_SESSION['flash_success'] = 'Two-factor authentication has been disabled';
        Flight::redirect('/member/settings');
    }

    /**
     * Regenerate recovery codes
     */
    public function regenerateCodes($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::redirect('/member/settings');
            return;
        }

        if (!TwoFactorAuth::isEnabled($this->member)) {
            Flight::redirect('/member/settings');
            return;
        }

        // Require password confirmation
        $password = $request->data->password ?? '';

        if (!password_verify($password, $this->member->password)) {
            $_SESSION['flash_error'] = 'Incorrect password';
            Flight::redirect('/member/settings');
            return;
        }

        $codes = TwoFactorAuth::regenerateRecoveryCodes($this->member);

        $this->viewData['title'] = 'New Recovery Codes';
        $this->viewData['recovery_codes'] = $codes;
        $this->render('member/2fa_enabled', $this->viewData);
    }
}