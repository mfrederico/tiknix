<?php
/**
 * PromptBuilder - Task-to-Prompt Conversion
 *
 * Builds structured prompts for Claude Code based on task type.
 * Each task type has a specialized prompt template that includes:
 * - Context about what needs to be done
 * - Instructions for validation
 * - Requirements for completion
 */

namespace app;

class PromptBuilder {

    /**
     * Build a prompt from task data
     *
     * @param array $task Task data
     * @return string The complete prompt
     */
    public static function build(array $task): string {
        $prompt = match($task['task_type'] ?? 'feature') {
            'feature' => self::buildFeaturePrompt($task),
            'bugfix' => self::buildBugfixPrompt($task),
            'refactor' => self::buildRefactorPrompt($task),
            'security' => self::buildSecurityPrompt($task),
            'docs' => self::buildDocsPrompt($task),
            'test' => self::buildTestPrompt($task),
            default => self::buildGenericPrompt($task),
        };

        // Append environment context (branch, port, authcontrol level)
        $prompt .= "\n\n" . self::getEnvironmentContext($task);

        // Append validation instructions
        $prompt .= "\n\n" . self::getValidationInstructions();

        // Append task update instructions
        $prompt .= "\n\n" . self::getTaskUpdateInstructions($task['id'] ?? 0);

        return $prompt;
    }

    /**
     * Get environment context (workspace, branch, port, authcontrol level)
     */
    private static function getEnvironmentContext(array $task): string {
        $context = "## Environment Context\n\n";

        // Workspace info (isolated project clone)
        if (!empty($task['project_path'])) {
            $context .= "**Workspace**: `{$task['project_path']}`\n";
            $context .= "- You are working in an isolated clone of the repository\n";
            $context .= "- All changes are contained within this workspace\n\n";
        }

        // Branch info
        if (!empty($task['branch_name'])) {
            $context .= "**Working Branch**: `{$task['branch_name']}`\n";
            $context .= "- You are working on a feature branch, NOT main\n";
            $context .= "- All changes should be committed to this branch\n";
            $context .= "- A pull request will be created when the task is complete\n\n";
        }

        // Authcontrol level for new endpoints
        if (!empty($task['authcontrol_level'])) {
            $levelName = array_search($task['authcontrol_level'], LEVELS) ?: 'Custom';
            $context .= "**Endpoint Access Level**: {$levelName} ({$task['authcontrol_level']})\n";
            $context .= "- When creating new endpoints/routes, set authcontrol level to {$task['authcontrol_level']}\n";
            $context .= "- This controls who can access the new endpoints\n\n";
        }

        // Test server port and subdomain URL
        if (!empty($task['assigned_port'])) {
            $context .= "**Test Server Port**: {$task['assigned_port']}\n";
            $context .= "- If testing is needed, the server can be run on port {$task['assigned_port']}\n";

            // Include subdomain test URL if proxy hash is available
            if (!empty($task['proxy_hash'])) {
                $testUrl = "https://{$task['proxy_hash']}.dev.tiknix.com";
                $context .= "- **Test URL**: `{$testUrl}` (available when test server is running)\n";
            }
            $context .= "\n";
        }

        return $context;
    }

    /**
     * Build prompt for new feature
     */
    private static function buildFeaturePrompt(array $task): string {
        $prompt = "# Feature Implementation Task\n\n";
        $prompt .= "## Task: " . ($task['title'] ?? 'Implement new feature') . "\n\n";

        if (!empty($task['description'])) {
            $prompt .= "## Description\n";
            $prompt .= $task['description'] . "\n\n";
        }

        if (!empty($task['acceptance_criteria'])) {
            $prompt .= "## Acceptance Criteria\n";
            $prompt .= "The feature is complete when:\n";
            $prompt .= $task['acceptance_criteria'] . "\n\n";
        }

        if (!empty($task['related_files'])) {
            $prompt .= "## Related Files\n";
            $prompt .= "Start by examining these files:\n";
            foreach ($task['related_files'] as $file) {
                $prompt .= "- `{$file}`\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## Instructions\n";
        $prompt .= "1. Analyze the codebase to understand existing patterns and architecture\n";
        $prompt .= "2. Plan the implementation approach\n";
        $prompt .= "3. Implement the feature following existing conventions\n";
        $prompt .= "4. Add appropriate tests if applicable\n";
        $prompt .= "5. Ensure all validation checks pass\n";
        $prompt .= "6. Create a commit with a descriptive message\n";
        $prompt .= "7. Create a pull request if configured\n";

        return $prompt;
    }

    /**
     * Build prompt for bug fix
     */
    private static function buildBugfixPrompt(array $task): string {
        $prompt = "# Bug Fix Task\n\n";
        $prompt .= "## Task: " . ($task['title'] ?? 'Fix bug') . "\n\n";

        if (!empty($task['description'])) {
            $prompt .= "## Bug Description\n";
            $prompt .= $task['description'] . "\n\n";
        }

        if (!empty($task['acceptance_criteria'])) {
            $prompt .= "## Expected Behavior\n";
            $prompt .= "After the fix:\n";
            $prompt .= $task['acceptance_criteria'] . "\n\n";
        }

        if (!empty($task['related_files'])) {
            $prompt .= "## Likely Affected Files\n";
            foreach ($task['related_files'] as $file) {
                $prompt .= "- `{$file}`\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## Instructions\n";
        $prompt .= "1. Reproduce or understand the bug from the description\n";
        $prompt .= "2. Identify the root cause in the code\n";
        $prompt .= "3. Implement the fix with minimal changes\n";
        $prompt .= "4. Verify the fix doesn't introduce regressions\n";
        $prompt .= "5. Add a test case if possible to prevent recurrence\n";
        $prompt .= "6. Create a commit explaining the fix\n";

        return $prompt;
    }

    /**
     * Build prompt for refactoring
     */
    private static function buildRefactorPrompt(array $task): string {
        $prompt = "# Refactoring Task\n\n";
        $prompt .= "## Task: " . ($task['title'] ?? 'Refactor code') . "\n\n";

        if (!empty($task['description'])) {
            $prompt .= "## Refactoring Goals\n";
            $prompt .= $task['description'] . "\n\n";
        }

        if (!empty($task['acceptance_criteria'])) {
            $prompt .= "## Success Criteria\n";
            $prompt .= $task['acceptance_criteria'] . "\n\n";
        }

        if (!empty($task['related_files'])) {
            $prompt .= "## Files to Refactor\n";
            foreach ($task['related_files'] as $file) {
                $prompt .= "- `{$file}`\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## Instructions\n";
        $prompt .= "1. Understand the current implementation thoroughly\n";
        $prompt .= "2. Identify code smells and areas for improvement\n";
        $prompt .= "3. Plan refactoring steps (small, incremental changes)\n";
        $prompt .= "4. Refactor while maintaining existing behavior\n";
        $prompt .= "5. Ensure all tests still pass\n";
        $prompt .= "6. Update any affected documentation\n";
        $prompt .= "7. Create atomic commits for each refactoring step\n";

        return $prompt;
    }

    /**
     * Build prompt for security fix
     */
    private static function buildSecurityPrompt(array $task): string {
        $prompt = "# Security Fix Task\n\n";
        $prompt .= "## Task: " . ($task['title'] ?? 'Fix security issue') . "\n\n";

        if (!empty($task['description'])) {
            $prompt .= "## Security Issue\n";
            $prompt .= $task['description'] . "\n\n";
        }

        if (!empty($task['acceptance_criteria'])) {
            $prompt .= "## Security Requirements\n";
            $prompt .= $task['acceptance_criteria'] . "\n\n";
        }

        if (!empty($task['related_files'])) {
            $prompt .= "## Affected Files\n";
            foreach ($task['related_files'] as $file) {
                $prompt .= "- `{$file}`\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## CRITICAL Instructions\n";
        $prompt .= "1. IMPORTANT: Do not expose sensitive information in logs or commits\n";
        $prompt .= "2. Identify the security vulnerability thoroughly\n";
        $prompt .= "3. Review OWASP guidelines for this type of vulnerability\n";
        $prompt .= "4. Implement the fix following security best practices\n";
        $prompt .= "5. Use the tiknix:security_scan tool to verify the fix\n";
        $prompt .= "6. Check for similar vulnerabilities elsewhere in the codebase\n";
        $prompt .= "7. Add input validation and sanitization where needed\n";
        $prompt .= "8. Create a commit with a clear security fix message\n";

        return $prompt;
    }

    /**
     * Build prompt for documentation
     */
    private static function buildDocsPrompt(array $task): string {
        $prompt = "# Documentation Task\n\n";
        $prompt .= "## Task: " . ($task['title'] ?? 'Update documentation') . "\n\n";

        if (!empty($task['description'])) {
            $prompt .= "## Documentation Needs\n";
            $prompt .= $task['description'] . "\n\n";
        }

        if (!empty($task['acceptance_criteria'])) {
            $prompt .= "## Requirements\n";
            $prompt .= $task['acceptance_criteria'] . "\n\n";
        }

        if (!empty($task['related_files'])) {
            $prompt .= "## Files to Document\n";
            foreach ($task['related_files'] as $file) {
                $prompt .= "- `{$file}`\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## Instructions\n";
        $prompt .= "1. Review the code or feature to understand it fully\n";
        $prompt .= "2. Identify what documentation is missing or outdated\n";
        $prompt .= "3. Write clear, concise documentation\n";
        $prompt .= "4. Include code examples where helpful\n";
        $prompt .= "5. Follow existing documentation style\n";
        $prompt .= "6. Update any related README files\n";

        return $prompt;
    }

    /**
     * Build prompt for test creation
     */
    private static function buildTestPrompt(array $task): string {
        $prompt = "# Testing Task\n\n";
        $prompt .= "## Task: " . ($task['title'] ?? 'Add or fix tests') . "\n\n";

        if (!empty($task['description'])) {
            $prompt .= "## Testing Goals\n";
            $prompt .= $task['description'] . "\n\n";
        }

        if (!empty($task['acceptance_criteria'])) {
            $prompt .= "## Test Coverage Requirements\n";
            $prompt .= $task['acceptance_criteria'] . "\n\n";
        }

        if (!empty($task['related_files'])) {
            $prompt .= "## Code to Test\n";
            foreach ($task['related_files'] as $file) {
                $prompt .= "- `{$file}`\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## Instructions\n";
        $prompt .= "1. Analyze the code to understand what needs testing\n";
        $prompt .= "2. Identify edge cases and error conditions\n";
        $prompt .= "3. Write unit tests for individual functions\n";
        $prompt .= "4. Write integration tests where appropriate\n";
        $prompt .= "5. Ensure tests are deterministic and isolated\n";
        $prompt .= "6. Run the test suite to verify all tests pass\n";
        $prompt .= "7. Aim for meaningful coverage, not just high numbers\n";

        return $prompt;
    }

    /**
     * Build generic prompt
     */
    private static function buildGenericPrompt(array $task): string {
        $prompt = "# Task\n\n";
        $prompt .= "## " . ($task['title'] ?? 'Complete task') . "\n\n";

        if (!empty($task['description'])) {
            $prompt .= "## Description\n";
            $prompt .= $task['description'] . "\n\n";
        }

        if (!empty($task['acceptance_criteria'])) {
            $prompt .= "## Acceptance Criteria\n";
            $prompt .= $task['acceptance_criteria'] . "\n\n";
        }

        if (!empty($task['related_files'])) {
            $prompt .= "## Related Files\n";
            foreach ($task['related_files'] as $file) {
                $prompt .= "- `{$file}`\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## Instructions\n";
        $prompt .= "Complete this task according to the description and acceptance criteria.\n";
        $prompt .= "Follow existing code patterns and conventions.\n";

        return $prompt;
    }

    /**
     * Get validation instructions
     */
    private static function getValidationInstructions(): string {
        return <<<'INSTRUCTIONS'
## Validation Requirements

Before completing this task, ensure:

1. **PHP Syntax**: All PHP files pass syntax check (`php -l`)
2. **Coding Standards**: Follow the project's RedBeanPHP and FlightPHP conventions
3. **Security**: No SQL injection, XSS, or other OWASP Top 10 vulnerabilities
4. **Bean Names**: Use lowercase for R::dispense() or the Bean:: wrapper

Use the following MCP tools for validation:
- `tiknix:validate_php` - Check PHP syntax
- `tiknix:security_scan` - Scan for security issues
- `tiknix:check_redbean` - Verify RedBeanPHP conventions
- `tiknix:full_validation` - Run all validators

Fix any issues before committing.
INSTRUCTIONS;
    }

    /**
     * Get task update instructions
     */
    private static function getTaskUpdateInstructions(int $taskId): string {
        if (!$taskId) {
            return '';
        }

        return <<<INSTRUCTIONS
## Progress Reporting & Communication (CRITICAL)

You are running inside a workbench task. The user is monitoring your progress through a web UI.

**MANDATORY: Use Tiknix MCP tools for ALL communication with the user.**
Tool names use format: mcp__tiknix__<tool_name>

### Asking Questions - CRITICAL RULE

**NEVER use the built-in AskUserQuestion tool. ALWAYS use `mcp__tiknix__ask_question` instead.**

The built-in tool does NOT update the workbench UI. If you use it, the user won't see your question
and the task will appear stuck. The MCP tool properly updates the task status and shows the question
in the web interface.

**`mcp__tiknix__ask_question`** - Ask the user a clarifying question
  Parameters:
  - `task_id` (required): {$taskId}
  - `question` (required): Your question text
  - `context` (optional): Why you're asking
  - `options` (optional): Array of suggested answers

  Use this tool proactively when:
  - You need clarification on requirements
  - There are multiple valid approaches
  - You want to confirm your understanding before implementing
  - The task description is ambiguous
  - You encounter an unexpected situation

**`mcp__tiknix__get_task`** - Get current task details including user responses
  Parameters:
  - `task_id` (required): {$taskId}

  Call this after asking a question to see the user's response in the comments.

### Progress Updates

- `mcp__tiknix__add_task_log` - Add log entries for significant events
- `mcp__tiknix__update_task` - Update progress message or status

### Completion

**`mcp__tiknix__complete_task`** - Report work is done
  Parameters:
  - `task_id` (required): {$taskId}
  - `summary`: What was accomplished
  - `branch_name`: Git branch if applicable
  - `pr_url`: Pull request URL if created

Current Task ID: {$taskId}

### WORKFLOW RULES

1. **ALWAYS** use `mcp__tiknix__ask_question` instead of AskUserQuestion
2. After asking, call `mcp__tiknix__get_task` to read the user's response
3. Report progress with `mcp__tiknix__add_task_log` at key milestones
4. When finished, call `mcp__tiknix__complete_task` with a summary
INSTRUCTIONS;
    }
}
