<?php
/**
 * AgentOrchestrator - Manages agent invocation for task conversations
 *
 * Handles triggering agents when they are:
 * - @mentioned in a task comment
 * - Assigned to a task
 *
 * Collects conversation context, builds prompts, invokes the provider,
 * and posts agent responses as taskcomments.
 */

namespace app;

use \app\Bean;
use \RedBeanPHP\R;
use \Exception as Exception;

class AgentOrchestrator {

    private $logger;

    /** Maximum number of recent comments to include as context */
    private const MAX_CONTEXT_COMMENTS = 20;

    public function __construct() {
        $this->logger = \Flight::get('log');
    }

    /**
     * Trigger agents mentioned in a comment
     *
     * @param int $taskId The task ID
     * @param array $agentIds Array of agent IDs to trigger
     * @param string $triggerContent The comment content that triggered the agents
     * @param int $triggerMemberId The member who posted the triggering comment
     * @return array Results per agent ['agent_id' => ['success' => bool, 'message' => string]]
     */
    public function triggerMentionedAgents(int $taskId, array $agentIds, string $triggerContent, int $triggerMemberId): array {
        $results = [];

        foreach ($agentIds as $agentId) {
            try {
                $results[$agentId] = $this->invokeAgent($taskId, (int)$agentId, $triggerContent, $triggerMemberId);
            } catch (Exception $e) {
                $this->logger->error('Agent invocation failed', [
                    'agent_id' => $agentId,
                    'task_id' => $taskId,
                    'error' => $e->getMessage()
                ]);
                $results[$agentId] = [
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Invoke a single agent for a task conversation
     *
     * @param int $taskId Task ID
     * @param int $agentId Agent ID
     * @param string $triggerContent The message that triggered the agent
     * @param int $triggerMemberId Who triggered the agent
     * @return array ['success' => bool, 'message' => string, 'comment_id' => int|null]
     */
    public function invokeAgent(int $taskId, int $agentId, string $triggerContent, int $triggerMemberId): array {
        $agent = Bean::load('agent', $agentId);
        if (!$agent->id || !$agent->isActive) {
            return ['success' => false, 'message' => 'Agent not found or inactive'];
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            return ['success' => false, 'message' => 'Task not found'];
        }

        // Build conversation context
        $context = $this->buildContext($task, $agent, $triggerContent);

        // Route to the appropriate provider
        $response = $this->callProvider($agent, $context);

        if (empty($response)) {
            return ['success' => false, 'message' => 'Agent returned empty response'];
        }

        // Post the response as a taskcomment
        $commentId = $this->postAgentComment($taskId, $agent, $response);

        $this->logger->info('Agent responded to task', [
            'agent_id' => $agentId,
            'agent_name' => $agent->name,
            'task_id' => $taskId,
            'comment_id' => $commentId,
            'response_length' => strlen($response)
        ]);

        return [
            'success' => true,
            'message' => 'Agent responded',
            'comment_id' => $commentId
        ];
    }

    /**
     * Build conversation context for the agent prompt
     *
     * @param object $task Workbenchtask bean
     * @param object $agent Agent bean
     * @param string $triggerContent The triggering message
     * @return string Full prompt context
     */
    private function buildContext($task, $agent, string $triggerContent): string {
        $parts = [];

        // Agent's system prompt
        if (!empty($agent->systemPrompt)) {
            $parts[] = $agent->systemPrompt;
        }

        // Task context
        $parts[] = "## Task: " . $task->title;
        if (!empty($task->description)) {
            $parts[] = "### Description\n" . $task->description;
        }
        if (!empty($task->acceptanceCriteria)) {
            $parts[] = "### Acceptance Criteria\n" . $task->acceptanceCriteria;
        }

        // Recent conversation history
        $comments = Bean::getAll(
            'SELECT tc.*, m.username, m.first_name, m.last_name, m.agent_id as commenter_agent_id
             FROM taskcomment tc
             LEFT JOIN member m ON tc.member_id = m.id
             WHERE tc.task_id = ?
             ORDER BY tc.created_at DESC
             LIMIT ?',
            [$task->id, self::MAX_CONTEXT_COMMENTS]
        );

        if (!empty($comments)) {
            $parts[] = "### Recent Conversation";
            foreach (array_reverse($comments) as $c) {
                $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                if (empty($name)) $name = $c['username'] ?? 'Unknown';
                if (!empty($c['commenter_agent_id'])) {
                    $name .= ' [Agent]';
                }
                $parts[] = "**{$name}**: {$c['content']}";
            }
        }

        // The triggering message
        $parts[] = "### Current Message (respond to this)\n" . $triggerContent;

        return implode("\n\n", $parts);
    }

    /**
     * Call the agent's provider to get a response
     *
     * @param object $agent Agent bean
     * @param string $context Full prompt context
     * @return string Agent's response text
     */
    private function callProvider($agent, string $context): string {
        $config = json_decode($agent->providerConfig ?: '{}', true);

        switch ($agent->provider) {
            case 'claude_cli':
                return $this->callClaudeCli($context, $config);
            case 'ollama':
                return $this->callOllama($context, $config);
            case 'openai':
                return $this->callOpenAi($context, $config);
            default:
                throw new Exception('Unsupported provider: ' . $agent->provider);
        }
    }

    /**
     * Call Claude CLI for a single-shot response
     */
    private function callClaudeCli(string $context, array $config): string {
        $binary = $config['binary_path'] ?? 'claude';
        $model = $config['model'] ?? '';

        $cmd = $binary . ' --print';
        if (!empty($model)) {
            $cmd .= ' --model ' . escapeshellarg($model);
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new Exception('Failed to start Claude CLI');
        }

        fwrite($pipes[0], $context);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new Exception('Claude CLI exited with code ' . $exitCode);
        }

        return trim($output);
    }

    /**
     * Call Ollama API for a response
     */
    private function callOllama(string $context, array $config): string {
        $baseUrl = rtrim($config['base_url'] ?? 'http://localhost:11434', '/');
        $model = $config['model'] ?? 'llama3';

        $payload = json_encode([
            'model' => $model,
            'prompt' => $context,
            'stream' => false
        ]);

        $ch = curl_init($baseUrl . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Ollama returned HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        return trim($data['response'] ?? '');
    }

    /**
     * Call OpenAI API for a response
     */
    private function callOpenAi(string $context, array $config): string {
        $apiKey = $config['api_key'] ?? '';
        $model = $config['model'] ?? 'gpt-4o';

        if (empty($apiKey)) {
            throw new Exception('OpenAI API key not configured');
        }

        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $context]
            ],
            'max_tokens' => 4096
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('OpenAI returned HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        return trim($data['choices'][0]['message']['content'] ?? '');
    }

    /**
     * Post the agent's response as a taskcomment
     *
     * @param int $taskId Task ID
     * @param object $agent Agent bean
     * @param string $response Agent's response text
     * @return int The created comment ID
     */
    private function postAgentComment(int $taskId, $agent, string $response): int {
        $comment = Bean::dispense('taskcomment');
        $comment->taskId = $taskId;
        $comment->memberId = (int)$agent->memberId;
        $comment->agentId = (int)$agent->id;
        $comment->content = $response;
        $comment->isFromAgent = 1;
        $comment->isFromClaude = ($agent->provider === 'claude_cli') ? 1 : 0;
        $comment->createdAt = date('Y-m-d H:i:s');
        return (int)Bean::store($comment);
    }

    /**
     * Parse @mentions from comment text and return matching agent IDs
     *
     * @param string $content Comment content
     * @return array Array of agent IDs found in @mentions
     */
    public static function parseMentions(string $content): array {
        $agentIds = [];

        if (preg_match_all('/@([a-z0-9][a-z0-9-]*)/i', $content, $matches)) {
            foreach ($matches[1] as $slug) {
                $agent = Bean::findOne('agent', 'slug = ? AND is_active = 1', [strtolower($slug)]);
                if ($agent) {
                    $agentIds[] = (int)$agent->id;
                }
            }
        }

        return array_unique($agentIds);
    }
}
