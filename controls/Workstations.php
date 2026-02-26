<?php
/**
 * Workstations Controller
 *
 * Manages remote workstations (runners) where agents execute tasks.
 * Supports SSH+tmux execution mode with SSH key management,
 * connectivity testing, and full diagnostics.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use app\BaseControls\Control;

class Workstations extends Control {

    public function __construct() {
        parent::__construct();
    }

    /**
     * List all workstations
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Workstations';

        $runners = Bean::find('runner', 'created_by = ? OR is_default = 1 ORDER BY name ASC', [$this->member->id]);

        $this->viewData['runners'] = $runners;
        $this->render('workstations/index', $this->viewData);
    }

    /**
     * Show create workstation form
     */
    public function create($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Add Workstation';
        $this->viewData['runner'] = null;
        $this->viewData['sshkeys'] = $this->getSshKeysForMember();
        $this->render('workstations/form', $this->viewData);
    }

    /**
     * Store a new workstation
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workstations');
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workstations/create');
            return;
        }

        try {
            $runner = Bean::dispense('runner');
            $this->populateRunner($runner);
            $runner->createdBy = $this->member->id;
            $runner->healthStatus = 'unknown';
            Bean::store($runner);

            $this->logger->info('Workstation created', [
                'runner_id' => $runner->id,
                'name' => $runner->name,
                'host' => $runner->host,
                'created_by' => $this->member->id
            ]);

            $this->flash('success', 'Workstation created successfully');
            Flight::redirect('/workstations/edit?id=' . $runner->id);

        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/workstations/create');
        } catch (Exception $e) {
            $this->logger->error('Failed to create workstation', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to create workstation');
            Flight::redirect('/workstations/create');
        }
    }

    /**
     * Show edit workstation form
     */
    public function edit($params = []) {
        if (!$this->requireLogin()) return;

        $runnerId = (int)$this->getParam('id');
        $runner = $this->loadRunner($runnerId);
        if (!$runner) return;

        $this->viewData['title'] = 'Edit Workstation - ' . $runner->name;
        $this->viewData['runner'] = $runner;
        $this->viewData['sshkeys'] = $this->getSshKeysForMember();
        $this->render('workstations/form', $this->viewData);
    }

    /**
     * Update a workstation
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workstations');
            return;
        }

        $runnerId = (int)$this->getParam('id');
        $runner = $this->loadRunner($runnerId);
        if (!$runner) return;

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workstations/edit?id=' . $runnerId);
            return;
        }

        try {
            $this->populateRunner($runner);
            Bean::store($runner);

            $this->logger->info('Workstation updated', [
                'runner_id' => $runnerId,
                'updated_by' => $this->member->id
            ]);

            $this->flash('success', 'Workstation updated successfully');
            Flight::redirect('/workstations/edit?id=' . $runnerId);

        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/workstations/edit?id=' . $runnerId);
        } catch (Exception $e) {
            $this->logger->error('Failed to update workstation', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to update workstation');
            Flight::redirect('/workstations/edit?id=' . $runnerId);
        }
    }

    /**
     * Delete a workstation
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workstations');
            return;
        }

        $runnerId = (int)$this->getParam('id');
        $runner = $this->loadRunner($runnerId);
        if (!$runner) return;

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workstations');
            return;
        }

        try {
            // Check if any agents reference this runner
            $agentCount = Bean::count('agent', 'runner_id = ?', [$runnerId]);
            if ($agentCount > 0) {
                $this->flash('error', "Cannot delete: $agentCount agent(s) still reference this workstation");
                Flight::redirect('/workstations/edit?id=' . $runnerId);
                return;
            }

            Bean::trash($runner);

            $this->logger->info('Workstation deleted', [
                'runner_id' => $runnerId,
                'deleted_by' => $this->member->id
            ]);

            $this->flash('success', 'Workstation deleted');
            Flight::redirect('/workstations');

        } catch (Exception $e) {
            $this->logger->error('Failed to delete workstation', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to delete workstation');
            Flight::redirect('/workstations');
        }
    }

    /**
     * AJAX: Test SSH connectivity to a workstation
     */
    public function test($params = []) {
        if (!$this->requireLogin()) return;

        $runnerId = (int)$this->getParam('id');

        // If POST, test with form values (before saving)
        $request = Flight::request();
        if ($request->method === 'POST') {
            $host = trim($this->getParam('host', ''));
            $sshUser = trim($this->getParam('ssh_user', 'claudeuser'));
            $sshPort = (int)$this->getParam('ssh_port', 22);
            $sshkeyId = (int)$this->getParam('sshkey_id', 0);
        } else {
            $runner = $this->loadRunner($runnerId, true);
            if (!$runner) return;
            $host = $runner->host;
            $sshUser = $runner->sshUser;
            $sshPort = (int)$runner->sshPort ?: 22;
            $sshkeyId = (int)$runner->sshkeyId;
        }

        if (empty($host)) {
            Flight::json(['success' => false, 'message' => 'Host address is required']);
            return;
        }

        $result = $this->testSshConnection($host, $sshUser, $sshPort, $sshkeyId);

        // Update runner health if we have a saved runner
        if ($runnerId) {
            $runner = Bean::load('runner', $runnerId);
            if ($runner->id) {
                $runner->healthStatus = $result['connected'] ? 'healthy' : 'unhealthy';
                $runner->lastHealthCheck = date('Y-m-d H:i:s');
                $runner->sshValidated = $result['connected'] ? 1 : 0;
                $runner->sshLastCheck = date('Y-m-d H:i:s');
                Bean::store($runner);
            }
        }

        Flight::json([
            'success' => $result['connected'],
            'message' => $result['message'],
            'checks' => $result['checks'] ?? []
        ]);
    }

    /**
     * AJAX: Run full diagnostic on a workstation
     */
    public function diagnose($params = []) {
        if (!$this->requireLogin()) return;

        $runnerId = (int)$this->getParam('id');

        // Support POST with form values or GET with saved runner
        $request = Flight::request();
        if ($request->method === 'POST') {
            $host = trim($this->getParam('host', ''));
            $sshUser = trim($this->getParam('ssh_user', 'claudeuser'));
            $sshPort = (int)$this->getParam('ssh_port', 22);
            $sshkeyId = (int)$this->getParam('sshkey_id', 0);
        } else {
            $runner = $this->loadRunner($runnerId, true);
            if (!$runner) return;
            $host = $runner->host;
            $sshUser = $runner->sshUser;
            $sshPort = (int)$runner->sshPort ?: 22;
            $sshkeyId = (int)$runner->sshkeyId;
        }

        if (empty($host)) {
            Flight::json(['success' => false, 'message' => 'Host address is required']);
            return;
        }

        $results = $this->runDiagnostics($host, $sshUser, $sshPort, $sshkeyId);

        $allPassed = !in_array(false, array_column($results, 'passed'));

        // Update runner if saved
        if ($runnerId) {
            $runner = Bean::load('runner', $runnerId);
            if ($runner->id) {
                $runner->healthStatus = $allPassed ? 'healthy' : 'unhealthy';
                $runner->lastHealthCheck = date('Y-m-d H:i:s');
                $runner->sshValidated = $results[0]['passed'] ? 1 : 0;
                $runner->sshLastDiagnostic = json_encode($results);
                $runner->sshLastCheck = date('Y-m-d H:i:s');
                Bean::store($runner);
            }
        }

        Flight::json([
            'success' => $allPassed,
            'message' => $allPassed ? 'All diagnostics passed' : 'Some checks failed',
            'checks' => $results
        ]);
    }

    /**
     * AJAX: List SSH keys for the current member
     */
    public function sshkeys($params = []) {
        if (!$this->requireLogin()) return;

        $keys = $this->getSshKeysForMember();
        $list = [];
        foreach ($keys as $k) {
            $list[] = [
                'id' => (int)$k->id,
                'name' => $k->name,
                'key_type' => $k->keyType,
                'fingerprint' => $k->fingerprint,
                'is_shared' => (bool)$k->isShared,
                'created_at' => $k->createdAt,
            ];
        }

        Flight::json(['success' => true, 'keys' => $list]);
    }

    /**
     * AJAX: Generate a new SSH key pair
     */
    public function generatesshkey($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::json(['success' => false, 'message' => 'POST required']);
            return;
        }

        $name = trim($this->getParam('name', ''));
        $keyType = trim($this->getParam('key_type', 'ed25519'));

        if (empty($name)) {
            Flight::json(['success' => false, 'message' => 'Key name is required']);
            return;
        }

        $validTypes = ['ed25519', 'ecdsa', 'rsa'];
        if (!in_array($keyType, $validTypes)) {
            Flight::json(['success' => false, 'message' => 'Invalid key type']);
            return;
        }

        try {
            $keyData = $this->generateKeyPair($keyType);

            $sshkey = Bean::dispense('sshkey');
            $sshkey->memberId = $this->member->id;
            $sshkey->name = $name;
            $sshkey->keyType = $keyType;
            $sshkey->publicKey = $keyData['public_key'];
            $sshkey->privateKeyEncrypted = $keyData['private_key_encrypted'];
            $sshkey->fingerprint = $keyData['fingerprint'];
            $sshkey->isShared = 0;
            Bean::store($sshkey);

            $this->logger->info('SSH key generated', [
                'sshkey_id' => $sshkey->id,
                'key_type' => $keyType,
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'key' => [
                    'id' => (int)$sshkey->id,
                    'name' => $sshkey->name,
                    'key_type' => $keyType,
                    'fingerprint' => $keyData['fingerprint'],
                    'public_key' => $keyData['public_key'],
                ],
                'message' => 'SSH key generated. Add this public key to the remote server.'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to generate SSH key', ['error' => $e->getMessage()]);
            Flight::json(['success' => false, 'message' => 'Failed to generate key: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX: Delete an SSH key
     */
    public function deletesshkey($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::json(['success' => false, 'message' => 'POST required']);
            return;
        }

        $keyId = (int)$this->getParam('id');
        $key = Bean::load('sshkey', $keyId);

        if (!$key->id || (int)$key->memberId !== (int)$this->member->id) {
            Flight::json(['success' => false, 'message' => 'SSH key not found']);
            return;
        }

        // Check if any runners reference this key
        $runnerCount = Bean::count('runner', 'sshkey_id = ?', [$keyId]);
        if ($runnerCount > 0) {
            Flight::json(['success' => false, 'message' => "Cannot delete: $runnerCount workstation(s) still use this key"]);
            return;
        }

        Bean::trash($key);

        Flight::json(['success' => true, 'message' => 'SSH key deleted']);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Load and validate runner ownership
     */
    private function loadRunner(int $id, bool $jsonResponse = false): ?\RedBeanPHP\OODBBean {
        if (!$id) {
            if ($jsonResponse) {
                Flight::json(['success' => false, 'message' => 'Runner ID required']);
            } else {
                Flight::redirect('/workstations');
            }
            return null;
        }

        $runner = Bean::load('runner', $id);
        if (!$runner->id) {
            if ($jsonResponse) {
                Flight::json(['success' => false, 'message' => 'Workstation not found']);
            } else {
                $this->flash('error', 'Workstation not found');
                Flight::redirect('/workstations');
            }
            return null;
        }

        // Check ownership (creator or admin)
        if ((int)$runner->createdBy !== (int)$this->member->id
            && !$runner->isDefault
            && (int)$this->member->level > LEVELS['ADMIN']) {
            if ($jsonResponse) {
                Flight::json(['success' => false, 'message' => 'Permission denied']);
            } else {
                $this->flash('error', 'You do not have permission to manage this workstation');
                Flight::redirect('/workstations');
            }
            return null;
        }

        return $runner;
    }

    /**
     * Populate runner bean from request params
     */
    private function populateRunner($runner): void {
        $runner->name = trim($this->getParam('name', ''));
        $runner->description = trim($this->getParam('description', ''));
        $runner->host = trim($this->getParam('host', ''));
        $runner->sshUser = trim($this->getParam('ssh_user', 'claudeuser'));
        $runner->sshPort = (int)$this->getParam('ssh_port', 22) ?: 22;
        $runner->isActive = (int)$this->getParam('is_active', 1);
        $runner->maxConcurrentJobs = (int)$this->getParam('max_concurrent_jobs', 2) ?: 2;

        $sshkeyId = (int)$this->getParam('sshkey_id', 0);
        $runner->sshkeyId = $sshkeyId ?: null;

        // Capabilities
        $caps = trim($this->getParam('capabilities', '[]'));
        json_decode($caps);
        if (json_last_error() === JSON_ERROR_NONE) {
            $runner->capabilities = $caps;
        }
    }

    /**
     * Get SSH keys visible to current member
     */
    private function getSshKeysForMember(): array {
        return Bean::find('sshkey', '(member_id = ? OR is_shared = 1) ORDER BY name ASC', [$this->member->id]);
    }

    /**
     * Build SSH command prefix
     */
    private function sshPrefix(string $host, string $user, int $port, ?int $sshkeyId): array {
        $cmd = 'ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -o BatchMode=yes';

        if ($port !== 22) {
            $cmd .= ' -p ' . (int)$port;
        }

        $keyPath = null;
        if ($sshkeyId) {
            $keyPath = $this->prepareTempKeyFile($sshkeyId);
            if ($keyPath) {
                $cmd .= ' -i ' . escapeshellarg($keyPath);
            }
        }

        $cmd .= ' ' . escapeshellarg($user . '@' . $host);

        return ['cmd' => $cmd, 'keyPath' => $keyPath];
    }

    /**
     * Prepare a temporary key file from encrypted storage
     */
    private function prepareTempKeyFile(int $sshkeyId): ?string {
        $key = Bean::load('sshkey', $sshkeyId);
        if (!$key->id || empty($key->privateKeyEncrypted)) {
            return null;
        }

        // Decrypt the private key (simple base64 for now; production should use proper encryption)
        $privateKey = base64_decode($key->privateKeyEncrypted);
        if (!$privateKey) {
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'tiknix_ssh_');
        file_put_contents($tmpFile, $privateKey);
        chmod($tmpFile, 0600);

        // Register cleanup
        register_shutdown_function(function () use ($tmpFile) {
            if (file_exists($tmpFile)) {
                // Zero out before delete
                $size = filesize($tmpFile);
                file_put_contents($tmpFile, str_repeat("\0", $size));
                unlink($tmpFile);
            }
        });

        return $tmpFile;
    }

    /**
     * Test basic SSH connectivity
     */
    private function testSshConnection(string $host, string $user, int $port, int $sshkeyId): array {
        $ssh = $this->sshPrefix($host, $user, $port, $sshkeyId);

        $output = [];
        $returnCode = 0;
        exec($ssh['cmd'] . ' "echo SSH_OK" 2>&1', $output, $returnCode);

        $outputStr = implode("\n", $output);

        if ($returnCode === 0 && str_contains($outputStr, 'SSH_OK')) {
            return [
                'connected' => true,
                'message' => "SSH connection to {$user}@{$host}:{$port} successful"
            ];
        }

        return [
            'connected' => false,
            'message' => "SSH connection failed: " . ($outputStr ?: 'timeout or connection refused')
        ];
    }

    /**
     * Run full diagnostics (7 checks)
     */
    private function runDiagnostics(string $host, string $user, int $port, int $sshkeyId): array {
        $ssh = $this->sshPrefix($host, $user, $port, $sshkeyId);
        $results = [];

        // 1. SSH connectivity
        $output = [];
        $code = 0;
        exec($ssh['cmd'] . ' "echo SSH_OK" 2>&1', $output, $code);
        $results[] = [
            'name' => 'SSH Connectivity',
            'passed' => $code === 0 && str_contains(implode("\n", $output), 'SSH_OK'),
            'detail' => $code === 0 ? 'Connected' : implode("\n", $output),
        ];

        if (!$results[0]['passed']) {
            // No point continuing if SSH itself fails
            return $results;
        }

        // 2. Claude CLI
        $output = [];
        $code = 0;
        exec($ssh['cmd'] . ' "export PATH=\$HOME/.local/bin:\$HOME/.claude/bin:/usr/local/bin:\$PATH && claude --version 2>&1" 2>&1', $output, $code);
        $results[] = [
            'name' => 'Claude CLI',
            'passed' => $code === 0,
            'detail' => $code === 0 ? trim(implode(' ', $output)) : 'Not found',
        ];

        // 3. tmux
        $output = [];
        $code = 0;
        exec($ssh['cmd'] . ' "tmux -V 2>&1" 2>&1', $output, $code);
        $results[] = [
            'name' => 'tmux',
            'passed' => $code === 0,
            'detail' => $code === 0 ? trim(implode(' ', $output)) : 'Not found',
        ];

        // 4. git
        $output = [];
        $code = 0;
        exec($ssh['cmd'] . ' "git --version 2>&1" 2>&1', $output, $code);
        $results[] = [
            'name' => 'Git',
            'passed' => $code === 0,
            'detail' => $code === 0 ? trim(implode(' ', $output)) : 'Not found',
        ];

        // 5. Node.js
        $output = [];
        $code = 0;
        exec($ssh['cmd'] . ' "source \$HOME/.nvm/nvm.sh 2>/dev/null; node --version 2>&1" 2>&1', $output, $code);
        $results[] = [
            'name' => 'Node.js',
            'passed' => $code === 0,
            'detail' => $code === 0 ? trim(implode(' ', $output)) : 'Not found',
        ];

        // 6. Write test
        $output = [];
        $code = 0;
        $testFile = '/tmp/tiknix_write_test_' . time();
        exec($ssh['cmd'] . ' "echo test > ' . $testFile . ' && rm ' . $testFile . ' && echo WRITE_OK" 2>&1', $output, $code);
        $results[] = [
            'name' => 'Write Test (/tmp)',
            'passed' => $code === 0 && str_contains(implode("\n", $output), 'WRITE_OK'),
            'detail' => $code === 0 ? 'Writable' : 'Failed',
        ];

        // 7. tmux session test
        $output = [];
        $code = 0;
        $sessionName = 'tiknix_diag_' . time();
        exec($ssh['cmd'] . ' "tmux new-session -d -s ' . $sessionName . ' && tmux kill-session -t ' . $sessionName . ' && echo TMUX_OK" 2>&1', $output, $code);
        $results[] = [
            'name' => 'tmux Session Test',
            'passed' => $code === 0 && str_contains(implode("\n", $output), 'TMUX_OK'),
            'detail' => $code === 0 ? 'tmux sessions work' : 'Failed to create/kill session',
        ];

        return $results;
    }

    /**
     * Generate an SSH key pair
     */
    private function generateKeyPair(string $type): array {
        $tmpDir = sys_get_temp_dir();
        $keyFile = $tmpDir . '/tiknix_keygen_' . uniqid();

        $typeFlag = match ($type) {
            'ed25519' => '-t ed25519',
            'ecdsa' => '-t ecdsa -b 521',
            'rsa' => '-t rsa -b 4096',
            default => '-t ed25519',
        };

        $cmd = "ssh-keygen {$typeFlag} -f " . escapeshellarg($keyFile) . " -N '' -C 'tiknix-agent' 2>&1";
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception('ssh-keygen failed: ' . implode("\n", $output));
        }

        $privateKey = file_get_contents($keyFile);
        $publicKey = file_get_contents($keyFile . '.pub');

        // Get fingerprint
        $fpOutput = [];
        exec('ssh-keygen -lf ' . escapeshellarg($keyFile . '.pub') . ' 2>&1', $fpOutput);
        $fingerprint = trim($fpOutput[0] ?? '');

        // Clean up temp files (zero first)
        $size = filesize($keyFile);
        file_put_contents($keyFile, str_repeat("\0", $size));
        unlink($keyFile);
        unlink($keyFile . '.pub');

        return [
            'public_key' => trim($publicKey),
            'private_key_encrypted' => base64_encode($privateKey),
            'fingerprint' => $fingerprint,
        ];
    }
}
