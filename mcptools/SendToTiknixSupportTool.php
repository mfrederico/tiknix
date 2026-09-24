<?php
/**
 * send_to_tiknix_support — an agent escalates a problem to Tiknix support, AFTER asking.
 *
 * The flow it is for: a member and their AI agent are digging into why something does not
 * work; the agent concludes the cause is the platform, not the app, and ASKS "Should I
 * escalate this to Tiknix support?". Only on a yes does it call this, with what it found.
 * `user_agreed` must be true — the parameter exists so the agent has to state that it asked.
 *
 * Where it runs decides who the ticket is from (lib/Support.php, source 'agent'):
 *   on a project   → core, over the project's own broker key (/brokerinfo/support); the
 *                    ticket is the project owner's and names the project
 *   on tiknix.com  → the API key's member; `project` (a slug they can reach) is optional
 * Agent tickets are limited per member per hour, so a loop cannot flood the queue.
 * HTTP only (not on StdioAllowList): it writes, and it needs an identity.
 */

namespace app\mcptools;

use app\Bean;
use app\ProjectContext;
use app\Support;
use app\Pipeline\MemberModel;

class SendToTiknixSupportTool extends BaseTool {

    public static string $name = 'send_to_tiknix_support';
    public static string $description = 'Escalate a problem to Tiknix platform support. ONLY call this after you have asked the user "Should I escalate this to Tiknix support?" and they said yes — never on your own. Use it when the cause looks like the Tiknix platform (builder, pipelines, hosting, connectors, billing), not the app\'s own code. Write the message for a support engineer: what the user was trying to do, what happened (exact errors, URLs, times), what you already checked, and what you think is wrong. The answer arrives in the user\'s Communications and email. Limited to 5 per hour.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'user_agreed' => ['type' => 'boolean', 'description' => 'true only if you asked the user whether to escalate to Tiknix support and they said yes'],
            'subject'     => ['type' => 'string', 'description' => 'One line, e.g. "Pipeline agent step exits 1 with no output since 14:00"'],
            'message'     => ['type' => 'string', 'description' => 'What was attempted, what happened (exact errors), what was checked, what you suspect'],
            'category'    => ['type' => 'string', 'enum' => ['problem', 'general', 'billing', 'feature'], 'description' => 'Default problem'],
            'project'     => ['type' => 'string', 'description' => 'On tiknix.com only: the project slug it is about (on a project, it is always that project)'],
        ],
        'required' => ['user_agreed', 'subject', 'message'],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        if (($args['user_agreed'] ?? false) !== true) {
            return "# send_to_tiknix_support NOT SENT\n\nAsk the user first: \"Should I escalate this to Tiknix support?\" Call again with user_agreed: true only if they say yes.\n";
        }
        $subject  = trim((string) $args['subject']);
        $message  = trim((string) $args['message']);
        $category = (string) ($args['category'] ?? 'problem');

        if (!is_core_install()) {
            // A project: core knows us by our broker key; the ticket is our owner's.
            try {
                [$base, $key] = MemberModel::broker();
            } catch (\RuntimeException $e) {
                return "# send_to_tiknix_support FAILED\n\n" . $e->getMessage() . "\n";
            }
            [$code, $body] = MemberModel::httpCall('POST', $base . '/brokerinfo/support',
                ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'],
                json_encode(['subject' => $subject, 'message' => $message, 'category' => $category], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 30);
            $d = json_decode((string) $body, true);
            if ($code !== 200 || empty($d['ok'])) {
                $why = is_array($d) ? (string) ($d['message'] ?? $d['error'] ?? '') : '';
                return "# send_to_tiknix_support FAILED\n\nCore answered HTTP {$code}" . ($why !== '' ? ": {$why}" : '') . "\n";
            }
            return "# send_to_tiknix_support — sent (ticket #{$d['ticket']})\n\nTell the user it is with Tiknix support; the answer will arrive in their Communications and email: {$d['url']}\n";
        }

        // tiknix.com: the caller's own account.
        $this->requireAuth();
        $project = null;
        $slug = trim((string) ($args['project'] ?? ''));
        if ($slug !== '') {
            $inst = Bean::findOne('instance', 'slug = ?', [$slug]);
            if (!$inst || !$inst->id || !ProjectContext::canAccess((int) $this->member->id, $inst)) {
                return "# send_to_tiknix_support NOT SENT\n\nNo project '{$slug}' that this account can reach. Leave `project` out, or use a slug from the user's projects.\n";
            }
            $project = $inst;
        }
        try {
            $r = Support::open((int) $this->member->id, $subject, $message, $category, $project, 'agent');
        } catch (\Throwable $e) {
            return "# send_to_tiknix_support NOT SENT\n\n" . $e->getMessage() . "\n";
        }
        $url = rtrim((string) \Flight::get('app.baseurl'), '/') . ($r['thread'] ? '/communications/thread/' . $r['thread'] : '/contact');
        return "# send_to_tiknix_support — sent (ticket #{$r['contact']})\n\nTell the user it is with Tiknix support; the answer will arrive in their Communications and email: {$url}\n";
    }
}
