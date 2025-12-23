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
        
        $member = $_SESSION['member'] ?? null;
        
        // Get some basic stats for the dashboard
        $stats = $this->getStats();
        
        $this->render('dashboard/index', [
            'title' => 'Dashboard',
            'member' => $member,
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
            $stats['member_since'] = date('F j, Y', strtotime($member->created_at));
            
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