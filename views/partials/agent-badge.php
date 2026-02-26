<?php
/**
 * Agent Badge Partial
 *
 * Displays a member's avatar/initials with agent detection.
 * If the member is an agent (has agentId), shows a robot icon and provider badge.
 * If the member is human, shows the normal avatar/initials.
 *
 * Usage:
 *   <?php include __DIR__ . '/../partials/agent-badge.php'; ?>
 *   <?= render_member_badge($memberBean, $size) ?>
 *
 * Parameters:
 *   $memberBean - A RedBeanPHP member bean
 *   $size       - Avatar size in pixels (default: 40)
 */

if (!function_exists('render_member_badge')) {
    /**
     * Render a member badge with agent detection
     *
     * @param object $memberBean RedBeanPHP member bean
     * @param int $size Avatar size in pixels
     * @return string HTML markup
     */
    function render_member_badge($memberBean, int $size = 40): string {
        if (!$memberBean || !$memberBean->id) {
            return '<div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width: ' . $size . 'px; height: ' . $size . 'px; min-width: ' . $size . 'px;">?</div>';
        }

        $isAgent = !empty($memberBean->agentId);

        if ($isAgent) {
            // Robot avatar for agents
            $iconSize = (int)($size * 0.5);
            return '<div class="rounded-circle bg-info bg-opacity-25 text-info d-flex align-items-center justify-content-center" '
                 . 'style="width: ' . $size . 'px; height: ' . $size . 'px; min-width: ' . $size . 'px;" '
                 . 'title="AI Agent">'
                 . '<i class="bi bi-robot" style="font-size: ' . $iconSize . 'px;"></i>'
                 . '</div>';
        }

        // Human avatar
        if (!empty($memberBean->avatarUrl)) {
            return '<img src="' . htmlspecialchars($memberBean->avatarUrl) . '" '
                 . 'class="rounded-circle" width="' . $size . '" height="' . $size . '" '
                 . 'alt="' . htmlspecialchars($memberBean->displayName()) . '">';
        }

        return '<div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" '
             . 'style="width: ' . $size . 'px; height: ' . $size . 'px; min-width: ' . $size . 'px;">'
             . htmlspecialchars($memberBean->initials())
             . '</div>';
    }

    /**
     * Render the member display name with agent indicator
     *
     * @param object $memberBean RedBeanPHP member bean
     * @return string HTML markup
     */
    function render_member_name($memberBean): string {
        if (!$memberBean || !$memberBean->id) {
            return '<span class="text-muted">Unknown</span>';
        }

        $name = htmlspecialchars($memberBean->displayName());
        $isAgent = !empty($memberBean->agentId);

        if ($isAgent) {
            return $name . ' <span class="badge bg-info bg-opacity-25 text-info ms-1" style="font-size: 0.7em;"><i class="bi bi-robot"></i> Bot</span>';
        }

        return $name;
    }

    /**
     * Render provider badge for an agent
     *
     * @param string $provider Provider name
     * @return string HTML badge markup
     */
    function render_provider_badge(string $provider): string {
        $badges = [
            'claude_cli' => ['bg-purple', 'Claude CLI'],
            'ollama'     => ['bg-success', 'Ollama'],
            'openai'     => ['bg-info', 'OpenAI'],
            'custom'     => ['bg-secondary', 'Custom'],
        ];

        $badge = $badges[$provider] ?? ['bg-secondary', ucfirst($provider)];
        return '<span class="badge ' . $badge[0] . '" style="font-size: 0.7em;">' . htmlspecialchars($badge[1]) . '</span>';
    }
}
?>
<style>
.bg-purple { background-color: #6f42c1 !important; }
</style>
