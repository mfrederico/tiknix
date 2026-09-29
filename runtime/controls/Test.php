<?php
/**
 * Test Controller for CLI functionality
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Test extends BaseControls\Control {
    
    /**
     * Simple hello method for testing
     */
    public function hello() {
        if (Flight::get('cli_mode')) {
            echo "Hello from CLI!\n";
            if (isset($_SESSION['member'])) {
                echo "Running as: " . $_SESSION['member']['username'] . "\n";
            }
        } else {
            $this->render('test/hello', [
                'title' => 'Test Hello',
                'message' => 'Hello from Web!'
            ]);
        }
    }
    
    /**
     * Test method with parameters
     */
    public function params() {
        $request = Flight::request();
        
        if (Flight::get('cli_mode')) {
            echo "Parameters received:\n";
            echo "Query params: " . json_encode($request->query->getData()) . "\n";
            echo "Data params: " . json_encode($request->data->getData()) . "\n";
        } else {
            $this->render('test/params', [
                'title' => 'Test Parameters',
                'query' => $request->query->getData(),
                'data' => $request->data->getData()
            ]);
        }
    }
    
    /**
     * Test database operation
     */
    public function dbtest() {
        try {
            $memberCount = Bean::count('member');
            $members = Bean::findAll('member', 'LIMIT 5');
            
            if (Flight::get('cli_mode')) {
                echo "Database test results:\n";
                echo "Total members: {$memberCount}\n";
                echo "First 5 members:\n";
                foreach ($members as $member) {
                    echo "  - {$member->username} (ID: {$member->id})\n";
                }
            } else {
                $this->render('test/dbtest', [
                    'title' => 'Database Test',
                    'count' => $memberCount,
                    'members' => $members
                ]);
            }
        } catch (\Exception $e) {
            if (Flight::get('cli_mode')) {
                echo "Database error: " . $e->getMessage() . "\n";
                exit(1);
            } else {
                $this->flash('error', 'Database error: ' . $e->getMessage());
                Flight::redirect('/');
            }
        }
    }
    
    /**
     * Cron job example - cleanup old sessions
     */
    public function cleanup() {
        if (!Flight::get('cli_mode')) {
            $this->error(403, 'This method is only available via CLI');
            return;
        }
        
        $options = Flight::get('cli_options');
        $verbose = isset($options['verbose']);
        $cron = isset($options['cron']);
        
        if (!$cron && $verbose) {
            echo "Starting cleanup process...\n";
        }
        
        // Example cleanup logic
        $sessionPath = session_save_path() ?: '/tmp';
        $maxAge = 86400; // 24 hours
        $deleted = 0;
        
        if (is_dir($sessionPath)) {
            $files = glob($sessionPath . '/sess_*');
            foreach ($files as $file) {
                if (filemtime($file) < time() - $maxAge) {
                    if (@unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }
        
        if (!$cron) {
            echo "Cleanup complete. Deleted {$deleted} old session files.\n";
        }
        
        // Log the action
        Flight::get('log')->info('Session cleanup completed', [
            'deleted' => $deleted,
            'cli_member' => $_SESSION['member']['username'] ?? 'unknown'
        ]);
    }
}