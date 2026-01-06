<?php
/**
 * Security Controller
 *
 * Admin interface for managing Claude Code security sandbox rules.
 * Uses an isolated security.db database separate from the main app.
 *
 * Rules control what Claude can access:
 * - path rules: block/allow/protect file paths
 * - command rules: block/allow bash commands
 */

namespace app;

use \Flight as Flight;
use \app\Bean as Bean;
use \Exception as Exception;
use app\BaseControls\Control;

class Security extends Control {

    private string $securityDbPath;

    public function __construct() {
        parent::__construct();
        $this->securityDbPath = dirname(__DIR__) . '/database/security.db';
    }

    /**
     * Switch to security database
     */
    private function useSecurityDb(): void {
        // Add security database if not already added
        if (!in_array('security', \RedBeanPHP\R::$toolboxes ?? [])) {
            Bean::addDatabase('security', 'sqlite:' . $this->securityDbPath);
        }
        Bean::selectDatabase('security');
    }

    /**
     * Switch back to default database
     */
    private function useDefaultDb(): void {
        Bean::selectDatabase('default');
    }

    /**
     * List all security rules
     */
    public function index($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $this->useSecurityDb();

        $filter = $this->getParam('filter', 'all');
        $search = $this->getParam('search', '');

        $sql = 'ORDER BY priority ASC, id ASC';
        $bindings = [];

        if ($filter !== 'all') {
            $sql = 'target = ? ' . $sql;
            $bindings[] = $filter;
        }

        if ($search) {
            $searchSql = $filter !== 'all' ? 'AND' : '';
            $sql = ($filter !== 'all' ? 'target = ? AND ' : '') .
                   '(name LIKE ? OR pattern LIKE ? OR description LIKE ?) ORDER BY priority ASC, id ASC';
            if ($filter !== 'all') {
                $bindings = [$filter, "%$search%", "%$search%", "%$search%"];
            } else {
                $bindings = ["%$search%", "%$search%", "%$search%"];
            }
        }

        $rules = Bean::findAll('securitycontrol', $sql, $bindings);

        // Group by target type
        $pathRules = [];
        $commandRules = [];
        foreach ($rules as $rule) {
            if ($rule->target === 'path') {
                $pathRules[] = $rule;
            } else {
                $commandRules[] = $rule;
            }
        }

        $this->useDefaultDb();

        $this->viewData['title'] = 'Security Rules';
        $this->viewData['pathRules'] = $pathRules;
        $this->viewData['commandRules'] = $commandRules;
        $this->viewData['filter'] = $filter;
        $this->viewData['search'] = $search;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('security/index', $this->viewData);
    }

    /**
     * Show create form
     */
    public function create($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $this->viewData['title'] = 'New Security Rule';
        $this->viewData['rule'] = null;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('security/form', $this->viewData);
    }

    /**
     * Show edit form
     */
    public function edit($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $id = (int)$this->getParam('id');
        if (!$id) {
            Flight::redirect('/security');
            return;
        }

        $this->useSecurityDb();
        $rule = Bean::load('securitycontrol', $id);
        $this->useDefaultDb();

        if (!$rule->id) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Rule not found'];
            Flight::redirect('/security');
            return;
        }

        $this->viewData['title'] = 'Edit Security Rule';
        $this->viewData['rule'] = $rule;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('security/form', $this->viewData);
    }

    /**
     * Save rule (create or update)
     */
    public function store($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/security');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/security');
            return;
        }

        $id = (int)$this->getParam('id');
        $name = $this->sanitize($this->getParam('name', ''));
        $target = $this->getParam('target', 'path');
        $action = $this->getParam('action', 'block');
        $pattern = $this->getParam('pattern', '');
        $level = $this->getParam('level', '');
        $description = $this->sanitize($this->getParam('description', ''));
        $priority = (int)$this->getParam('priority', 100);
        $isActive = (int)$this->getParam('is_active', 1);

        // Validation
        if (empty($name) || empty($pattern)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Name and pattern are required'];
            Flight::redirect($id ? "/security/edit?id=$id" : '/security/create');
            return;
        }

        if (!in_array($target, ['path', 'command'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid target type'];
            Flight::redirect($id ? "/security/edit?id=$id" : '/security/create');
            return;
        }

        if (!in_array($action, ['block', 'allow', 'protect'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid action type'];
            Flight::redirect($id ? "/security/edit?id=$id" : '/security/create');
            return;
        }

        // Validate regex if it looks like one
        if (preg_match('#^[/#~@].*[/#~@]$#', $pattern)) {
            if (@preg_match($pattern, '') === false) {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid regex pattern'];
                Flight::redirect($id ? "/security/edit?id=$id" : '/security/create');
                return;
            }
        }

        $this->useSecurityDb();

        try {
            if ($id) {
                $rule = Bean::load('securitycontrol', $id);
                if (!$rule->id) {
                    throw new Exception('Rule not found');
                }
            } else {
                $rule = Bean::dispense('securitycontrol');
                $rule->createdAt = date('Y-m-d H:i:s');
            }

            $rule->name = $name;
            $rule->target = $target;
            $rule->action = $action;
            $rule->pattern = $pattern;
            $rule->level = $level === '' ? null : (int)$level;
            $rule->description = $description;
            $rule->priority = $priority;
            $rule->isActive = $isActive;
            $rule->updatedAt = date('Y-m-d H:i:s');

            Bean::store($rule);

            $this->useDefaultDb();

            $_SESSION['flash'][] = ['type' => 'success', 'message' => $id ? 'Rule updated' : 'Rule created'];
            Flight::redirect('/security');

        } catch (Exception $e) {
            $this->useDefaultDb();
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
            Flight::redirect($id ? "/security/edit?id=$id" : '/security/create');
        }
    }

    /**
     * Delete rule
     */
    public function delete($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/security');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/security');
            return;
        }

        $id = (int)$this->getParam('id');

        $this->useSecurityDb();

        try {
            $rule = Bean::load('securitycontrol', $id);
            if ($rule->id) {
                Bean::trash($rule);
                $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Rule deleted'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        $this->useDefaultDb();
        Flight::redirect('/security');
    }

    /**
     * Toggle rule active status
     */
    public function toggle($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $id = (int)$this->getParam('id');

        $this->useSecurityDb();

        try {
            $rule = Bean::load('securitycontrol', $id);
            if (!$rule->id) {
                throw new Exception('Rule not found');
            }

            $rule->isActive = $rule->isActive ? 0 : 1;
            $rule->updatedAt = date('Y-m-d H:i:s');
            Bean::store($rule);

            $this->useDefaultDb();

            Flight::json([
                'success' => true,
                'is_active' => (bool)$rule->isActive
            ]);

        } catch (Exception $e) {
            $this->useDefaultDb();
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Check if a pattern matches a subject
     */
    private function patternMatches(string $pattern, string $subject): bool {
        $pattern = trim($pattern);
        if (empty($pattern)) return false;

        $firstChar = $pattern[0] ?? '';
        $lastChar = $pattern[strlen($pattern) - 1] ?? '';

        if (strlen($pattern) >= 3 &&
            $firstChar === $lastChar &&
            in_array($firstChar, ['/', '#', '~', '@'], true)) {
            $result = @preg_match($pattern, $subject);
            if ($result === false) {
                // Invalid regex - treat as literal
                return strpos($subject, $pattern) !== false;
            }
            return (bool)$result;
        }

        return strpos($subject, $pattern) !== false;
    }

    /**
     * Evaluate a single rule against a subject
     */
    private function evaluateRule($rule, string $subject, int $level, bool $isWrite): ?array {
        switch ($rule->action) {
            case 'block':
                if ($rule->level !== null && $level <= $rule->level) {
                    return null; // User has sufficient level to bypass
                }
                return [
                    'allowed' => false,
                    'reason' => $rule->description ?: $rule->name,
                    'rule_id' => $rule->id
                ];

            case 'allow':
                if ($rule->level !== null && $level > $rule->level) {
                    return null; // User doesn't have sufficient level
                }
                return ['allowed' => true, 'reason' => 'Allowed by: ' . $rule->name];

            case 'protect':
                if (!$isWrite) {
                    return ['allowed' => true, 'reason' => 'Read allowed (protected path)'];
                }
                if ($rule->level !== null && $level > $rule->level) {
                    return [
                        'allowed' => false,
                        'reason' => 'Write requires higher level: ' . $rule->name,
                        'rule_id' => $rule->id
                    ];
                }
                return ['allowed' => true, 'reason' => 'Write allowed (sufficient level)'];
        }

        return null;
    }

    /**
     * Test a path or command against current rules
     */
    public function test($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        $target = $this->getParam('target', 'path');
        $subject = $this->getParam('subject', '');
        $level = (int)$this->getParam('level', 100);
        $isWrite = (bool)$this->getParam('is_write', false);

        if (empty($subject)) {
            Flight::jsonError('Subject required', 400);
            return;
        }

        $this->useSecurityDb();

        $rules = Bean::findAll('securitycontrol', 'is_active = 1 ORDER BY priority ASC');

        $matchedRules = [];
        $result = ['allowed' => true, 'reason' => 'No blocking rules matched'];

        // First, check rules matching the target type
        foreach ($rules as $rule) {
            if ($rule->target !== $target) continue;

            if (!$this->patternMatches($rule->pattern, $subject)) continue;

            $matchedRules[] = [
                'id' => $rule->id,
                'name' => $rule->name,
                'action' => $rule->action,
                'pattern' => $rule->pattern,
                'level' => $rule->level,
                'target' => $rule->target
            ];

            $evalResult = $this->evaluateRule($rule, $subject, $level, $isWrite);
            if ($evalResult !== null) {
                $result = $evalResult;
                break;
            }
        }

        // For commands, also check embedded paths against path rules
        if ($target === 'command' && $result['allowed']) {
            // Extract file paths from the command
            if (preg_match_all('#(?:^|\s)(/[^\s]+)#', $subject, $matches)) {
                $safePaths = ['/dev/null', '/dev/stdout', '/dev/stderr'];

                foreach ($matches[1] as $path) {
                    if (in_array($path, $safePaths)) continue;

                    // Check this path against path rules
                    foreach ($rules as $rule) {
                        if ($rule->target !== 'path') continue;

                        if (!$this->patternMatches($rule->pattern, $path)) continue;

                        $matchedRules[] = [
                            'id' => $rule->id,
                            'name' => $rule->name,
                            'action' => $rule->action,
                            'pattern' => $rule->pattern,
                            'level' => $rule->level,
                            'target' => 'path',
                            'matched_path' => $path
                        ];

                        $evalResult = $this->evaluateRule($rule, $path, $level, $isWrite);
                        if ($evalResult !== null) {
                            if (!$evalResult['allowed']) {
                                $evalResult['reason'] = "Path in command blocked: " . $evalResult['reason'];
                            }
                            $result = $evalResult;
                            break 2;
                        }
                    }
                }
            }
        }

        $this->useDefaultDb();

        Flight::json([
            'success' => true,
            'result' => $result,
            'matched_rules' => $matchedRules,
            'test_params' => [
                'target' => $target,
                'subject' => $subject,
                'level' => $level,
                'is_write' => $isWrite
            ]
        ]);
    }
}
