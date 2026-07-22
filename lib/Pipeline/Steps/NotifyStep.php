<?php
/**
 * notify — send an email via lib/Mailer (merges myctobot's mailgun + email_out).
 * When Mailer isn't configured (local/dev), it records the intended message as
 * output instead of failing, so a pipeline is still runnable offline.
 */

namespace app\Pipeline\Steps;

use app\Mailer;

class NotifyStep implements StepInterface {

    public static function type(): string { return 'notify'; }

    public static function schema(): array {
        return [
            'summary' => 'Send an email (via the configured Mailer).',
            'config'  => [
                'to'      => 'string — recipient email',
                'subject' => 'string — subject',
                'body'    => 'string — HTML or text body',
                'name'    => 'string (optional) — recipient name',
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $to      = trim((string) ($config['to'] ?? ''));
        $subject = (string) ($config['subject'] ?? '(no subject)');
        $body    = (string) ($config['body'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'invalid recipient', 'exit' => 1];
        }
        if (!class_exists('\\app\\Mailer') || !Mailer::isConfigured()) {
            return ['ok' => true, 'output' => ['sent' => false, 'reason' => 'mailer-not-configured', 'to' => $to, 'subject' => $subject],
                    'stdout' => "would email {$to}: {$subject}", 'stderr' => '', 'exit' => 0];
        }
        try {
            $sent = Mailer::create()->to($to, (string) ($config['name'] ?? ''))->subject($subject)->send($body);
            return ['ok' => (bool) $sent, 'output' => ['sent' => (bool) $sent, 'to' => $to, 'subject' => $subject],
                    'stdout' => $sent ? "emailed {$to}" : 'send failed', 'stderr' => $sent ? '' : 'send returned false', 'exit' => $sent ? 0 : 1];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $e->getMessage(), 'exit' => 1];
        }
    }
}
