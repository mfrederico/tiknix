<?php
/**
 * support_draft — leave an investigation's findings and a draft reply on a support ticket, for a person to read, edit and send.
 * It cannot send anything: the draft only fills the ticket page's reply box. ADMIN, HTTP only, like send_note. A terminal session
 * on this host uses scripts/support-draft.php.
 */

namespace app\mcptools;

use app\SupportDraft;

class SupportDraftTool extends BaseTool {

    public static string $name = 'support_draft';
    public static string $description = 'Leave your investigation of a support ticket and a draft reply on it. A person reads it on the ticket page, edits it and presses Send: this tool sends nothing, and a new draft replaces the old one. Write the reply for the customer in plain text. Keep paths, log lines and anything internal in findings, which only admins see.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'ticket'   => ['type' => 'integer', 'description' => 'The support ticket number (/contact/view?id=N)'],
            'reply'    => ['type' => 'string', 'description' => 'The reply a person would send to the customer, plain text'],
            'findings' => ['type' => 'string', 'description' => 'What you checked and found, for the admin only'],
        ],
        'required' => ['ticket', 'reply', 'findings'],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $this->requireAdmin();
        try {
            $id = SupportDraft::save((int) $args['ticket'], (string) $args['reply'], (string) $args['findings'], 'agent (' . ($this->member->email ?? 'admin') . ')');
        } catch (\InvalidArgumentException $e) {
            return "# support_draft REFUSED\n\n" . $e->getMessage() . "\n";
        }
        return "# support_draft: saved (draft #{$id})\n\nNothing was sent. It waits on /contact/view?id=" . (int) $args['ticket'] . " for a person to send.\n";
    }
}
