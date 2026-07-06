<?php
/**
 * First-run setup wizard (WordPress-style).
 *
 * A fresh deploy seeds the database (sql/schema.sql) with a default admin whose
 * password ('admin123') is public in the repo. While that default password is
 * unchanged, the site is treated as "not installed": the home page and login
 * redirect to /install, where the operator sets the real admin credentials.
 * Once changed, the wizard self-disables and drops storage/installed.lock so the
 * check stays cheap. The live site (admin hash already differs) is never affected.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;
use RedBeanPHP\R;

class Install extends Control {

    /** password_hash('admin123', PASSWORD_DEFAULT) — the value seeded by sql/schema.sql. */
    private const DEFAULT_HASH = '$2y$10$jVz654DI7bX8e1Dh32O9suFcMW4x1V.0SrniJNpDyknwkzc6gM20a';

    private static function lockFile(): string {
        return dirname(__DIR__) . '/storage/installed.lock';
    }

    /**
     * True once the site is set up. Signalled by the persistent lock (written on the
     * first successful admin login) or, before that, by the seeded default admin
     * password having been changed — so the wizard won't reappear between the wizard
     * submit and the first login.
     */
    public static function isInstalled(): bool {
        if (is_file(self::lockFile())) return true;
        $admin = R::findOne('member', 'username = ?', ['admin']);
        return !$admin || !$admin->id || $admin->password !== self::DEFAULT_HASH;
    }

    /** Persist the "setup complete" marker. Called on the first successful admin login. */
    public static function markComplete(): void {
        $lock = self::lockFile();
        if (is_file($lock)) return;
        @mkdir(dirname($lock), 0775, true);
        @file_put_contents($lock, date('c') . "\n");
    }

    /** GET /install — the setup wizard (only while not installed). */
    public function index($params = []): void {
        if (self::isInstalled()) { Flight::redirect('/auth/login'); return; }
        $this->render('install/index', ['title' => 'Set up tiknix'], false);
    }

    /** POST /install/save — create the admin from the wizard, then lock. */
    public function save($params = []): void {
        if (self::isInstalled()) { Flight::redirect('/auth/login'); return; }
        if (!$this->validateCSRF()) { Flight::redirect('/install'); return; }

        $username = trim((string)$this->getParam('username', 'admin')) ?: 'admin';
        $email    = trim((string)$this->getParam('email', ''));
        $pass     = (string)$this->getParam('password', '');
        $confirm  = (string)$this->getParam('password_confirm', '');

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{2,50}$/', $username)) $errors[] = 'Username must be 2-50 letters, numbers, or . _ -';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))         $errors[] = 'A valid email address is required.';
        if (strlen($pass) < 8)                                  $errors[] = 'Password must be at least 8 characters.';
        if ($pass !== $confirm)                                 $errors[] = 'Passwords do not match.';
        if ($errors) {
            $this->render('install/index', ['title' => 'Set up tiknix', 'errors' => $errors, 'username' => $username, 'email' => $email], false);
            return;
        }

        // Update the seeded admin (or create one if the seed is missing).
        $admin = R::findOne('member', 'username = ?', ['admin']);
        if (!$admin || !$admin->id) { $admin = Bean::dispense('member'); $admin->createdAt = date('Y-m-d H:i:s'); $admin->loginCount = 0; }
        $admin->username  = $username;
        $admin->email     = $email;
        $admin->password  = password_hash($pass, PASSWORD_DEFAULT);
        $admin->level     = 1;         // ROOT
        $admin->status    = 'active';
        $admin->updatedAt = date('Y-m-d H:i:s');
        Bean::store($admin);

        // Do NOT lock here — the "setup complete" marker is written on the first
        // successful admin login (Auth::dologin). The changed password already keeps
        // the wizard from reappearing in the meantime.
        Flight::redirect('/auth/login?installed=1');
    }
}
