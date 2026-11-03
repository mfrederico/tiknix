<?php
/**
 * Signin — passwordless sign-in by emailed link (lib/SignInLink.php). Public: someone signed out asks for a link here.
 *   GET  /signin              ask for a link (?email= fills it in)
 *   POST /signin/send         send it (the answer never says whether the address has an account)
 *   GET  /signin/link/<token> the link: a page with a Sign in button (mail scanners fetch links; a GET changes nothing)
 *   POST /signin/go           the button: the link is used, once
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Signin extends Control {

    public function index($params = []): void {
        if (Flight::isLoggedIn()) { Flight::redirect('/dashboard'); return; }
        $email = trim((string) $this->getParam('email', ''));
        $this->render('signin/index', ['title' => 'Sign in with an email link', 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
                                       'to' => SignInLink::safePath((string) $this->getParam('to', ''))]);
    }

    public function send($params = []): void {
        if (!$this->validateCSRF()) return;
        $email = trim((string) $this->getParam('email', ''));
        $to = SignInLink::safePath((string) $this->getParam('to', ''));
        try { SignInLink::request($email, (string) (Flight::request()->ip ?? ''), $to); }
        catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/signin?email=' . urlencode($email) . ($to !== '' ? '&to=' . urlencode($to) : '')); return;
        }
        $this->render('signin/sent', ['title' => 'Check your email', 'email' => $email, 'to' => $to]);
    }

    public function link($params = []): void {
        $token = (string) ($this->opId() ?? '');
        $row = SignInLink::peek($token);
        $this->render('signin/link', ['title' => 'Sign in', 'token' => $row ? $token : '', 'valid' => $row !== null]);
    }

    public function go($params = []): void {
        if (!$this->validateCSRF()) return;
        $r = SignInLink::consume((string) $this->getParam('token', ''));
        if ($r === null) {
            $this->flash('error', 'That sign-in link was already used or has run out. Send yourself a new one.');
            Flight::redirect('/signin'); return;
        }
        $this->logger->info('Signed in with an emailed link', ['id' => (int) $r['member']->id]);
        Flight::redirect(SignInLink::startSession($r['member'], $r['to']));
    }
}
