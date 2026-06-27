<?php
/**
 * Leads Controller
 * Admin-only view of leads captured from the public "Coming Soon" page.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use app\BaseControls\Control;

class Leads extends Control {

    const ADMIN_LEVEL = 50;

    public function __construct() {
        parent::__construct();

        // Must be logged in
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }

        // Must be an admin (level 50 or lower number = higher privilege)
        if ($this->member->level > self::ADMIN_LEVEL) {
            $this->logger->warning('Unauthorized leads access attempt', [
                'member_id' => $this->member->id,
                'member_level' => $this->member->level
            ]);
            Flight::redirect('/');
            exit;
        }
    }

    /**
     * List captured leads (newest first).
     */
    public function index() {
        $leads = Bean::find('lead', ' ORDER BY created_at DESC ');

        $this->render('leads/index', [
            'title' => 'Leads',
            'leads' => $leads,
            'total' => count($leads)
        ]);
    }
}
