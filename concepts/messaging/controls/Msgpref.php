<?php
namespace app\concepts\messaging;

use \Flight as Flight;
use app\BaseControls\Control;

/**
 * "What reaches you": a member's choice per kind of message and channel, their digest, the audiences they belong to —
 * the service's panel, drawn here inside the app. Texts wait for the phone to be proved (the app's phone provider).
 */
class Msgpref extends Control {

    private function unavailable(ServiceException $e): void {
        $this->logger->error('ERROR Msgpref: ' . $e->getMessage());
        Flight::response()->status(503);
        $this->render('messaging/unavailable', ['title' => 'Messaging is not answering', 'why' => $e->getMessage(), 'back' => '/']);
    }

    public function index($params = []) {
        $mid = (int) $this->member->id;
        try {
            Sync::contact($mid);   // the service knows this member as they are now (a new phone, a new name)
            $panel = Reach::panel($mid);
        } catch (ServiceException $e) { $this->unavailable($e); return; }
        $phone = Providers::has('phone');
        $pending = $phone && Providers::can('phone', 'pending') ? Providers::need('phone')::pending($mid) : null;
        $text = ['offered' => $phone && $panel['texts']['offered'], 'verified' => $panel['texts']['verified'], 'canProve' => $phone && Providers::can('phone', 'prove'), 'pending' => $pending];
        $this->render('msgpref/index', ['title' => 'What reaches you', 'rows' => $panel['categories'], 'scopes' => $panel['audiences'], 'digest' => $panel['digest'], 'text' => $text]);
    }

    /** POST: a radio per category and channel, the digest, a switch per audience. Texts wait for the phone to be proved. */
    public function save($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/msgpref'); return; }
        if (!$this->validateCSRF()) return;
        $mid = (int) $this->member->id;
        $choices = ['categories' => [], 'audiences' => []];
        $wantText = [];   // categories whose text switch goes from off to on while the phone is not proved: kept back until it is
        $msg = $this->getParam('msg', []);
        if (!is_array($msg)) throw new \InvalidArgumentException('msg: expected a list of choices');
        try {
            $panel = Reach::panel($mid);
            $verified = (bool) $panel['texts']['verified'];
            $now = [];
            foreach ($panel['categories'] as $r) $now[$r['code']] = $r['channels'];
            foreach ($msg as $code => $perChannel) {
                foreach ((array) $perChannel as $ch => $v) {
                    if ($v !== '1' && $v !== '0') continue;
                    if ($ch === 'text' && $v === '1' && !$verified && empty($now[$code]['text'])) { $wantText[] = (string) $code; continue; }
                    $choices['categories'][(string) $code][(string) $ch] = $v === '1';
                }
            }
            $digest = (string) $this->getParam('digest', '');
            if (($digest === '1' || $digest === '0') && $panel['digest'] !== null) $choices['digest'] = $digest === '1';
            $notify = $this->getParam('notify', []);
            if (!is_array($notify)) throw new \InvalidArgumentException('notify: expected a list of audiences');
            foreach ($notify as $key => $v) if ($v === '1' || $v === '0') $choices['audiences'][(string) $key] = $v === '1';
            $refused = Reach::save($mid, $choices);
        } catch (ServiceException $e) {
            $this->flash('error', 'Your choices could not be saved: ' . Client::said($e));
            Flight::redirect('/msgpref');
            return;
        }
        if ($refused) {
            $this->flash('error', 'Some choices could not be saved: ' . implode('; ', $refused));
            Flight::redirect('/msgpref');
            return;
        }
        if ($wantText) {
            $saved = 'Your other choices are saved, but texts are not on yet: ';
            if (!Providers::has('phone') || !Providers::can('phone', 'prove')) {
                $this->logger->error('ERROR Msgpref: member ' . $mid . ' asked for texts, but the phone provider has no prove(): they cannot confirm a phone.');
                $this->flash('error', $saved . 'this site cannot confirm a phone number yet.');
            } else {
                $r = Providers::need('phone')::prove($mid, $wantText);
                $this->flash($r['ok'] ? 'success' : 'error', $r['ok'] ? $r['message'] : $saved . $r['message']);
            }
            Flight::redirect('/msgpref');
            return;
        }
        $this->flash('success', 'Your message choices are saved.');
        Flight::redirect('/msgpref');
    }

    /** POST: the code that proves the phone. Right: the phone goes to the service and the waiting text choices are saved. Wrong: nothing is. */
    public function textcode($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/msgpref'); return; }
        if (!$this->validateCSRF()) return;
        $mid = (int) $this->member->id;
        if (!Providers::has('phone') || !Providers::can('phone', 'confirm')) { $this->flash('error', 'This site cannot confirm a phone number.'); Flight::redirect('/msgpref'); return; }
        $phone = Providers::need('phone');
        if ((string) $this->getParam('cancel', '') === '1') { $phone::cancel($mid); $this->flash('success', 'Texts stay off.'); Flight::redirect('/msgpref'); return; }
        $r = $phone::confirm($mid, (string) $this->getParam('code', ''));
        if (!$r['ok']) { $this->flash('error', $r['message']); Flight::redirect('/msgpref'); return; }
        try {
            Sync::contact($mid);
            $choices = ['categories' => []];
            foreach ($r['categories'] as $code) $choices['categories'][(string) $code] = ['text' => true];
            $refused = Reach::save($mid, $choices);
        } catch (ServiceException $e) {
            $this->flash('error', 'Your phone is confirmed, but the text choices could not be saved: ' . Client::said($e));
            Flight::redirect('/msgpref');
            return;
        }
        $this->flash($refused ? 'error' : 'success', $refused ? 'Your phone is confirmed, but: ' . implode('; ', $refused) : $r['message']);
        Flight::redirect('/msgpref');
    }

    // nav: none — the address at the foot of emails sent before the service (signed by this app, which the service cannot read)
    /**
     * An old unsubscribe link: its token was signed with this app's key and names the member, a kind of message (by its old
     * id, still in the msgcategory table until the move is finished) and maybe an audience. GET: one button naming what will
     * stop; POST: the switch is turned off in the service. A token that cannot be read any more points to the choices (410).
     */
    public function unsubscribe($params = []) {
        $token = (string) $this->getParam('t', '');
        $t = self::readOldToken($token);
        if ($t === null) {
            Flight::response()->status(410);
            $this->render('msgpref/unsubscribe', ['title' => 'This link has moved', 'state' => 'moved', 'token' => '', 'what' => '']);
            return;
        }
        $what = $t['label'];
        if (Flight::request()->method === 'POST') {
            if (!$this->validateCSRF()) return;
            try {
                $choices = $t['scope'] !== null ? ['audiences' => [$t['scope'] => false]] : ['categories' => [$t['category'] => [$t['channel'] => false]]];
                $refused = Reach::save($t['member'], $choices);
            } catch (ServiceException $e) { $this->unavailable($e); return; }
            $this->render('msgpref/unsubscribe', ['title' => $refused ? 'Not changed' : 'Unsubscribed', 'state' => $refused ? 'refused' : 'done', 'token' => $token, 'what' => $refused ? implode('; ', $refused) : $what]);
            return;
        }
        $this->render('msgpref/unsubscribe', ['title' => 'Stop these messages', 'state' => 'ask', 'token' => $token, 'what' => $what]);
    }

    /**
     * @return array{member:int,category:string,scope:?string,channel:string,label:string}|null null when the token is malformed, its signature
     *         does not match this app's key, or the old category table is gone
     */
    public static function readOldToken(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        [$body, $sig] = $parts;
        if (!in_array(strlen($sig), [32, 12], true)) return null;
        $key = \app\EncryptionService::deriveKey('unsubscribe');
        if (!hash_equals(substr(hash_hmac('sha256', $body, $key), 0, strlen($sig)), $sig)) return null;
        $raw = base64_decode(strtr($body, '-_', '+/'), true);
        if ($raw === false || !preg_match('/^(\d+)\.(\d+)\.(0|\d+|[a-z][a-z0-9_]*:[A-Za-z0-9_-]+)(\.t)?$/', $raw, $m)) return null;
        if (!in_array('msgcategory', \app\Bean::inspect(), true)) return null;   // the old table is gone: the id names nothing
        $row = \app\Bean::getRow('SELECT code, label, can_opt_out FROM msgcategory WHERE id = ?', [(int) $m[2]]);
        if (!$row || (int) $row['can_opt_out'] === 0) return null;
        if (!\app\Bean::load('member', (int) $m[1])->id) return null;
        $scope = $m[3] === '0' ? null : (ctype_digit($m[3]) ? 'community:' . $m[3] : $m[3]);
        $label = (string) $row['label'];
        if ($scope !== null && Providers::has('audiences')) { try { $label .= ' from ' . Providers::need('audiences')::label($scope); } catch (\InvalidArgumentException $e) {} }
        if (!empty($m[4])) $label .= ' (texts)';
        return ['member' => (int) $m[1], 'category' => (string) $row['code'], 'scope' => $scope, 'channel' => empty($m[4]) ? 'email' : 'text', 'label' => $label];
    }
}
