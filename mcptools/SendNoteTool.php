<?php
/**
 * send_note — write to a member of this install: in the app (Communications) and by email,
 * signed by the caller, replies back into the same conversation (lib/Notes.php).
 *
 * ADMIN, HTTP only: it speaks AS the key's member, so it needs an identity, and it is not on
 * StdioAllowList — a jailed agent has none. The recipient is a member id or an email of an
 * existing member; a project slug means that project's owner.
 */

namespace app\mcptools;

use app\Bean;
use app\Notes;

class SendNoteTool extends BaseTool {

    public static string $name = 'send_note';
    public static string $description = 'Send a note to a member of this install, from you (the API key\'s member): it appears in their Communications and is emailed to them; their email reply comes back into the same conversation. Address them by member id, by email, or by project slug (= that project\'s owner). The body is HTML (<p>, <strong>, <code>, <ul>) or plain text. Requires ADMIN; not available to jailed agents.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'to'      => ['type' => 'string', 'description' => 'Member id, member email, or a project slug (its owner), e.g. "12", "someone@example.com", "lead-machine-1639fe"'],
            'subject' => ['type' => 'string', 'description' => 'Subject of the email copy, e.g. "Your pipelines and model credentials"'],
            'body'    => ['type' => 'string', 'description' => 'The note. HTML or plain text.'],
        ],
        'required' => ['to', 'subject', 'body'],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $this->requireAdmin();
        $to = trim((string) $args['to']);
        $member = self::recipient($to);
        if (!$member) return "# send_note REFUSED\n\nNo member matches '{$to}' (a member id, a member's email, or a project slug).\n";

        $body = (string) $args['body'];
        if (!str_contains($body, '<')) $body = nl2br(htmlspecialchars($body, ENT_QUOTES));
        try {
            $r = Notes::toMember((int) $this->member->id, (int) $member->id, trim((string) $args['subject']), $body);
        } catch (\Throwable $e) {
            return "# send_note FAILED\n\n" . $e->getMessage() . "\n";
        }
        $email = match ($r['email']) {
            'sent'  => "emailed to {$member->email}",
            'none'  => 'not emailed: the member has no email address',
            default => "NOT emailed ({$r['email']}): {$r['email_error']}",
        };
        return "# send_note — sent to {$member->displayName('member #' . $member->id)} (#{$member->id})\n\n"
             . "- in the app: conversation #{$r['thread']}, message #{$r['message']}\n- {$email}\n";
    }

    private static function recipient(string $to): ?\RedBeanPHP\OODBBean {
        if (ctype_digit($to)) {
            $m = Bean::load('member', (int) $to);
            return $m->id ? $m : null;
        }
        if (str_contains($to, '@')) {
            $m = Bean::findOne('member', 'LOWER(email) = ?', [strtolower($to)]);
            return ($m && $m->id) ? $m : null;
        }
        $inst = Bean::findOne('instance', 'slug = ?', [$to]);
        if (!$inst || !$inst->id || (int) $inst->memberId <= 0) return null;
        $m = Bean::load('member', (int) $inst->memberId);
        return $m->id ? $m : null;
    }
}
