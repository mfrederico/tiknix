<?php
/**
 * First-run setup wizard (WordPress-style).
 *
 * A fresh deploy seeds the database with a default admin whose password
 * ('admin123') is public in the repo. While that default password is unchanged,
 * the site is treated as "not installed": the home page and login redirect to
 * /install, where the operator sets the real admin credentials.
 *
 * Install state is derived purely from the DB — a ROOT admin whose password is
 * no longer the seeded default. No marker file: a fresh, dropped, or reseeded
 * database self-corrects straight back to /install, and a completed install can
 * never be "locked" true while the DB says otherwise.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;
use RedBeanPHP\R;

class Install extends Control {

    /** password_hash('admin123', PASSWORD_DEFAULT) — the value seeded by sql/schema.sql. */
    private const DEFAULT_HASH = '$2y$10$jVz654DI7bX8e1Dh32O9suFcMW4x1V.0SrniJNpDyknwkzc6gM20a';

    /**
     * True once the site is set up: a ROOT admin exists whose password is no
     * longer the seeded default (and is non-empty). Keyed on level (not the
     * username 'admin') so a renamed admin still counts. Purely DB-derived, so
     * dropping/reseeding the database drops the site back into /install.
     */
    public static function isInstalled(): bool {
        $admin = R::findOne('member', 'level = 1 AND password != ? AND password != ?',
                            [self::DEFAULT_HASH, '']);
        return (bool)($admin && $admin->id);
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

        // Upgrade the seeded default admin into the real one (or create it if the
        // seed is missing). Keyed on ROOT + default hash so a seeded 'admin' is
        // found regardless of the username the operator is choosing now.
        $admin = R::findOne('member', 'level = 1 AND password = ? ORDER BY id ASC', [self::DEFAULT_HASH]);
        if (!$admin || !$admin->id) { $admin = Bean::dispense('member'); $admin->createdAt = date('Y-m-d H:i:s'); $admin->loginCount = 0; }
        $admin->username  = $username;
        $admin->email     = $email;
        $admin->password  = password_hash($pass, PASSWORD_DEFAULT);
        $admin->level     = 1;         // ROOT
        $admin->status    = 'active';
        $admin->updatedAt = date('Y-m-d H:i:s');
        Bean::store($admin);

        // No marker file: isInstalled() now reads the changed password straight
        // from the DB, so the wizard self-disables the moment this admin is saved.
        Flight::redirect('/auth/login?installed=1');
    }
}
