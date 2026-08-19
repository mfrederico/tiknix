<?php
/**
 * Dashboard Controller
 * Generic dashboard for all logged-in users
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Dashboard extends BaseControls\Control {
    
    /**
     * Main dashboard page
     */
    public function index() {
        // Require login
        if (!$this->requireLogin()) return;
        
        // No 'member' key: Control already supplies the signed-in member, refreshed from
        // the database. This passed $_SESSION['member'] instead — a snapshot taken at
        // login, so a level or email changed since showed the old value here and the new
        // one everywhere else.
        $stats = $this->getStats();

        $this->render('dashboard/index', [
            'title' => 'Dashboard',
            'stats' => $stats
        ]);
    }
    
    /**
     * Get basic stats for dashboard
     */
    private function getStats() {
        $stats = [];
        
        try {
            // Get member's last login
            $member = Bean::load('member', $_SESSION['member']['id']);
            $stats['last_login'] = $member->last_login ?? 'Never';
            $stats['login_count'] = $member->login_count ?? 0;
            
            // Get member since date
            $stats['member_since'] = $member->createdAt
                ? date('F j, Y', strtotime($member->createdAt))
                : 'Unknown';
            
            // Get total members (if admin)
            if (Flight::hasLevel(LEVELS['ADMIN'])) {
                $stats['total_members'] = Bean::count('member');
                $stats['active_members'] = Bean::count('member', 'status = ?', ['active']);
            }
            
        } catch (\Exception $e) {
            Flight::get('log')->error('Dashboard stats error: ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Quick stats widget (AJAX)
     */
    public function stats() {
        if (!$this->requireLogin()) return;
        
        $stats = $this->getStats();
        
        $this->json([
            'success' => true,
            'stats' => $stats
        ]);
    }
}