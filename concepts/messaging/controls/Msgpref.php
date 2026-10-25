<?php
namespace app\concepts\messaging;

use \Flight as Flight;
use app\Bean;
use app\BaseControls\Control;
use app\Sms;

/** "What reaches you": a member's choice per kind of message and channel, their digest, the audiences they belong to — and the signed unsubscribe page. */
class Msgpref extends Control {

    public function index($params = []) {
        $mid = (int) $this->member->id;
        $this->render('msgpref/index', ['title' => 'What reaches you'] + $this->panel($mid));
    }

    /** Everything the page draws: a row per category, the audiences the member is in, the digest switch, the text state. */
    private function panel(int $mid): array {
        $phone = Providers::has('phone');
        $rows = [];
        foreach (\Model_Msgcategory::all() as $cat) {
            $on = [];
            foreach ($cat->box()->channels() as $ch) {
                if ($ch === 'text' && !$phone) continue;   // no phone provider: texts are not offered
                $on[$ch] = Reach::switchedOn($mid, $cat, $ch);
            }
            $rows[] = ['code' => (string) $cat->code, 'label' => (string) $cat->label, 'description' => (string) $cat->description,
                'canOptOut' => (int) $cat->canOptOut === 1, 'channels' => $on];
        }
        $scopes = [];
        $scopeCategory = null;
        if (Providers::has('audiences')) {
            $audiences = Providers::need('audiences');
            $scopeCategory = (string) $audiences::category();
            foreach ($audiences::scopes($mid) as $s) {
                $switch = $audiences::allowsFor($mid, (string) $s['key']);
                if ($switch === null) continue;   // no per-audience switch: nothing to show
                $scopes[] = ['key' => (string) $s['key'], 'label' => (string) $s['label'], 'on' => $switch];
            }
        }
        $digestCategory = Digest::available() ? Reach::digestCategory() : null;
        $pending = $phone && Providers::can('phone', 'pending') ? Providers::need('phone')::pending($mid) : null;
        $text = ['offered' => $phone, 'connected' => Sms::isConnected(), 'verified' => $phone && Reach::phone($mid) !== null,
            'canProve' => $phone && Providers::can('phone', 'prove'), 'pending' => $pending];
        return ['rows' => $rows, 'scopes' => $scopes, 'scopeCategory' => $scopeCategory, 'digest' => $digestCategory === null ? null : Reach::digestOn($mid),
            'digestCategory' => $digestCategory, 'text' => $text];
    }

    /** POST: a radio per category and channel, the digest, a switch per audience. Texts wait for the phone to be proved. */
    public function save($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/msgpref'); return; }
        if (!$this->validateCSRF()) return;
        $mid = (int) $this->member->id;
        $choices = $this->getParam('msg', []);
        if (!is_array($choices)) throw new \InvalidArgumentException('msg: expected a list of choices');
        $wantText = [];   // categories whose text switch goes from off to on: kept back until the phone is proved
        try {
            foreach (\Model_Msgcategory::all() as $cat) {
                if ((int) $cat->canOptOut !== 1) continue;
                foreach ($cat->box()->channels() as $ch) {
                    if ($ch === 'text' && !Providers::has('phone')) continue;
                    $v = $choices[$cat->code][$ch] ?? null;
                    if ($ch === 'text' && $v === '1' && !Reach::switchedOn($mid, $cat, 'text') && Reach::phone($mid) === null) { $wantText[] = (string) $cat->code; continue; }
                    if ($v === '1' || $v === '0') \Model_Msgpref::set($mid, (string) $cat->code, $ch, $v === '1');
                }
            }
            $digest = (string) $this->getParam('digest', '');
            if (($digest === '1' || $digest === '0') && Digest::available()) {
                $dc = Reach::digestCategory();
                if ($dc === null) throw new \InvalidArgumentException('digest: there is no digest');
                \Model_Msgpref::set($mid, $dc, \Model_Msgpref::DIGEST, $digest === '1');
            }
            $notify = $this->getParam('notify', []);
            if (!is_array($notify)) throw new \InvalidArgumentException('notify: expected a list of audiences');
            if ($notify) {
                $audiences = Providers::need('audiences');
                $mine = [];
                foreach ($audiences::scopes($mid) as $s) $mine[(string) $s['key']] = true;
                foreach ($notify as $key => $v) {
                    if (($v !== '1' && $v !== '0') || !isset($mine[(string) $key])) continue;
                    if ($audiences::allowsFor($mid, (string) $key) !== ($v === '1')) $audiences::allow($mid, (string) $key, $v === '1');
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', 'Some choices could not be saved: ' . $e->getMessage());
            Flight::redirect('/msgpref');
            return;
        }
        if ($wantText) {
            $saved = 'Your other choices are saved, but texts are not on yet: ';
            if (!Providers::can('phone', 'prove')) {
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

    /** POST: the code that proves the phone. Right: the waiting text choices are saved. Wrong: nothing is. */
    public function textcode($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/msgpref'); return; }
        if (!$this->validateCSRF()) return;
        $mid = (int) $this->member->id;
        if (!Providers::can('phone', 'confirm')) { $this->flash('error', 'This site cannot confirm a phone number.'); Flight::redirect('/msgpref'); return; }
        $phone = Providers::need('phone');
        if ((string) $this->getParam('cancel', '') === '1') { $phone::cancel($mid); $this->flash('success', 'Texts stay off.'); Flight::redirect('/msgpref'); return; }
        $r = $phone::confirm($mid, (string) $this->getParam('code', ''));
        if (!$r['ok']) { $this->flash('error', $r['message']); Flight::redirect('/msgpref'); return; }
        foreach ($r['categories'] as $code) \Model_Msgpref::set($mid, (string) $code, 'text', true);
        $this->flash('success', $r['message']);
        Flight::redirect('/msgpref');
    }

    // nav: none — reached from the link at the foot of an email; signed token, no sign-in
    /** GET: one button naming what will stop. POST: turn that switch off and say so. A bad token is 400. */
    public function unsubscribe($params = []) {
        $token = (string) $this->getParam('t', '');
        $t = Reach::readToken($token);
        if ($t === null) {
            Flight::response()->status(400);
            $this->render('msgpref/unsubscribe', ['title' => 'Link not valid', 'state' => 'invalid', 'token' => '', 'what' => '']);
            return;
        }
        $cat = \Model_Msgcategory::byCode($t['category']);
        $what = (string) $cat->label;
        if ($t['scope'] !== null) $what .= ' from ' . Providers::need('audiences')::label($t['scope']);
        if ($t['channel'] === 'text') $what .= ' (texts)';
        if (Flight::request()->method === 'POST') {
            if (!$this->validateCSRF()) return;
            $was = Reach::tokenSwitchOn($t);
            if ($was) Reach::tokenSwitchOff($t);
            $this->render('msgpref/unsubscribe', ['title' => 'Unsubscribed', 'state' => $was ? 'done' : 'already', 'token' => $token, 'what' => $what]);
            return;
        }
        $this->render('msgpref/unsubscribe', ['title' => 'Stop these messages', 'state' => Reach::tokenSwitchOn($t) ? 'ask' : 'already', 'token' => $token, 'what' => $what]);
    }
}
