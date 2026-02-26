<?php
/**
 * Taskcomment FUSE Model
 *
 * Enables RedBeanPHP associations for the taskcomment bean:
 * - workbenchtask: The task this comment belongs to (via task_id)
 * - member: The member who wrote this comment (via member_id)
 * - agent: The agent that wrote this comment (via agent_id, NULL for human comments)
 *
 * Agent-related columns:
 * - agentId: FK to agent table (NULL for human comments)
 * - isFromAgent: 1 if this comment was written by an agent
 * - mentionedAgents: JSON array of agent IDs mentioned via @mentions
 */

use app\Bean;

class Model_Taskcomment extends \RedBeanPHP\SimpleModel {

    /**
     * Check if this comment was posted by an agent
     *
     * @return bool
     */
    public function isAgentComment(): bool {
        return !empty($this->bean->isFromAgent) || !empty($this->bean->agentId);
    }

    /**
     * Get the agent that posted this comment, or null
     *
     * @return \RedBeanPHP\OODBBean|null
     */
    public function getAgent() {
        if (empty($this->bean->agentId)) {
            return null;
        }
        $agent = Bean::load('agent', (int)$this->bean->agentId);
        return ($agent && $agent->id) ? $agent : null;
    }

    /**
     * Get list of mentioned agent IDs from this comment
     *
     * @return array Array of agent IDs
     */
    public function getMentionedAgentIds(): array {
        $json = $this->bean->mentionedAgents;
        if (empty($json)) {
            return [];
        }
        $ids = json_decode($json, true);
        return is_array($ids) ? $ids : [];
    }

    /**
     * Parse @mentions from content and populate mentionedAgents
     * Looks for @agent-slug patterns in the content text
     *
     * @return array Array of matched agent IDs
     */
    public function parseMentions(): array {
        $content = $this->bean->content ?? '';
        $agentIds = [];

        // Match @agent-slug patterns (alphanumeric and hyphens)
        if (preg_match_all('/@([a-z0-9][a-z0-9-]*)/i', $content, $matches)) {
            foreach ($matches[1] as $slug) {
                $agent = Bean::findOne('agent', 'slug = ? AND is_active = 1', [strtolower($slug)]);
                if ($agent) {
                    $agentIds[] = (int)$agent->id;
                }
            }
        }

        $agentIds = array_unique($agentIds);
        $this->bean->mentionedAgents = json_encode(array_values($agentIds));
        return $agentIds;
    }
}
