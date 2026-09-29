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

class Install extends Control {

    /** password_hash('admin123', PASSWORD_DEFAULT) — the value seeded by sql/schema.sql. */
    public const DEFAULT_HASH = \Model_Member::SEEDED_PASSWORD_HASH;

    /**
     * Emails the seeds put on the bootstrap admin when nobody was named: sql/schema.sql and
     * 01_Member.php use admin@example.com, aibuilder-provision.php <slug>@tiknix.local.
     * Anything else on the seeded ROOT was set by provisioning to the person the instance
     * belongs to, and the wizard then answers only to that person (see save()).
     */
    private const PLACEHOLDER_EMAIL_RE = '/^(admin@example\.com|[^@]+@tiknix\.local)$/i';

    /**
     * True once the site is set up: a ROOT admin exists whose password is no
     * longer the seeded default (and is non-empty). Keyed on level (not the
     * username 'admin') so a renamed admin still counts. Purely DB-derived, so
     * dropping/reseeding the database drops the site back into /install.
     *
     * "No such admin" is three different situations, and they must not share an answer:
     *
     *   1. A ROOT row that still carries the seeded hash. That is a fresh install or a
     *      freshly provisioned instance, and the wizard is what turns it into a real one:
     *      NOT installed, no alarm. For a month this case fell through to (3), because a
     *      provisioned instance already has seeded authcontrol rows and so "looks
     *      established": the wizard never appeared, nothing forced a password, and
     *      serenity ran live for five days with ROOT = admin123 while this method logged
     *      "member table missing" seventy times a day about a table with four rows in it.
     *   2. No ROOT row and no history: a new, empty database. NOT installed, run the wizard.
     *   3. No ROOT row but the database has history. That is how mileage.tiknix.com lost
     *      its site: its member table was emptied by a RedBean table rebuild, the app
     *      reopened this wizard, and the next person to arrive — an invited member editing
     *      their bio — filled it in and was created at level 1. A disaster to be shouted
     *      about, not a setup to run: report installed, so the wizard stays shut.
     */
    public static function isInstalled(): bool {
        $admin = Bean::findOne('member', 'level = 1 AND password != ? AND password != ?',
                            [self::DEFAULT_HASH, '']);
        if ($admin && $admin->id) return true;

        if (self::seededRoot()) return false;   // (1): the wizard's own case

        return self::looksEstablished();        // (2) or (3)
    }

    /** The ROOT row the seeds created, still on the default hash — or null. */
    private static function seededRoot(): ?\RedBeanPHP\OODBBean {
        $r = Bean::findOne('member', 'level = 1 AND password = ? ORDER BY id ASC', [self::DEFAULT_HASH]);
        return ($r && $r->id) ? $r : null;
    }

    /**
     * Has this database been lived in? Checked WITHOUT the member table, which is exactly
     * the thing that may have been destroyed.
     *
     * Deliberately generous: any sign of prior life counts. A false positive locks an
     * operator out of a wizard they can still reach by fixing the database; a false
     * negative hands root to a stranger.
     */
    private static function looksEstablished(): bool {
        foreach (['instance', 'authcontrol', 'settings', 'team', 'apikey', 'contact'] as $t) {
            try {
                if (!in_array($t, Bean::inspect(), true)) continue;
                if ((int) Bean::count($t) > 0) {
                    $members = (int) Bean::count('member');
                    Flight::get('log')?->critical(
                        'Install wizard refused: this database has history but no ROOT (level 1) member. '
                      . ($members === 0
                            ? 'The member table is empty — restore it from a backup rather than reinstalling.'
                            : "The member table has {$members} rows and none is level 1 — restore the admin row, or promote one: "
                            . 'php scripts/clitool.php --user=<ident> --set-level=1 --set-password=...'),
                        ['evidence' => $t . ' has rows', 'members' => $members]
                    );
                    return true;
                }
            } catch (\Throwable $e) {
                // A table that will not answer is not evidence either way; keep looking.
                continue;
            }
        }
        return false;
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

        // Upgrade the seeded default admin into the real one (or create it if the
        // seed is missing). Keyed on ROOT + default hash so a seeded 'admin' is
        // found regardless of the username the operator is choosing now.
        $admin = self::seededRoot();

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{2,50}$/', $username)) $errors[] = 'Username must be 2-50 letters, numbers, or . _ -';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))         $errors[] = 'A valid email address is required.';
        if (strlen($pass) < 8)                                  $errors[] = 'Password must be at least 8 characters.';
        if ($pass !== $confirm)                                 $errors[] = 'Passwords do not match.';
        // This page is public and first-come. Provisioning wrote the owner's email on the
        // seeded admin, so on an instance the wizard finishes THAT person's account and no
        // one else's: a stranger who finds <slug>.tiknix.com before the owner completes
        // setup does not get to be its root. A seed nobody named (a placeholder email) is
        // a standalone install, and whoever is running the wizard is the operator.
        $owner = $admin ? (string) $admin->email : '';
        if ($owner !== '' && !preg_match(self::PLACEHOLDER_EMAIL_RE, $owner) && strcasecmp($owner, $email) !== 0) {
            Flight::get('log')?->warning('Install wizard: email does not match the provisioned owner', ['submitted' => $email]);
            $errors[] = 'Setup must use the email address this project was provisioned for — the one you sign in to tiknix with.';
        }
        if ($errors) {
            $this->render('install/index', ['title' => 'Set up tiknix', 'errors' => $errors, 'username' => $username, 'email' => $email], false);
            return;
        }

        if (!$admin) { $admin = Bean::dispense('member'); $admin->createdAt = date('Y-m-d H:i:s'); $admin->loginCount = 0; }
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
