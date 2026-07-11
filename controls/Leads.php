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
        $this->render('leads/index', [
            'title' => 'Leads',
            'total' => Bean::count('lead'),
        ]);
    }

    /**
     * AJAX feed for the leads table — speaks the DataTables server-side protocol
     * via the shared DataTableResponse primitive (SQL paging / search / sort).
     * Column order MUST match the <thead> in views/leads/index.php.
     */
    public function data() {
        $columns = [
            ['db' => 'first_name', 'search' => 'like'],   // 0  First Name
            ['db' => 'last_name',  'search' => 'like'],   // 1  Last Name
            ['db' => 'email',      'search' => 'like'],   // 2  Email
            ['db' => 'created_at', 'search' => null],     // 3  Signed Up
            ['db' => null,         'orderable' => false], // 4  Actions
        ];

        $resp = DataTableResponse::build('lead', $columns, $this->getParams(), [
            'globalCols' => ['first_name', 'last_name', 'email'],
            'row' => function (array $r): array {
                $email = h($r['email'] ?? '');
                $name  = trim(((string)($r['first_name'] ?? '')) . ' ' . ((string)($r['last_name'] ?? '')));
                $btn = $email !== ''
                    ? '<button type="button" class="btn btn-sm btn-outline-primary lead-email-btn"'
                      . ' data-email="' . h($r['email'] ?? '') . '" data-name="' . h($name) . '">'
                      . '<i class="bi bi-envelope"></i> Email</button>'
                    : '';
                return [
                    h($r['first_name'] ?? ''),
                    h($r['last_name'] ?? ''),
                    $email !== '' ? '<a href="mailto:' . $email . '">' . $email . '</a>' : '—',
                    '<span class="ui-mono small text-secondary">' . h($r['created_at'] ?? '') . '</span>',
                    '<div class="text-end">' . $btn . '</div>',
                ];
            },
        ]);

        Flight::json($resp);
    }
}
