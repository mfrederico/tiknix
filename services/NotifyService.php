<?php
/**
 * NotifyService — threaded, Mailgun-backed two-way email as a fluent one-liner.
 *
 * A domain-agnostic conversation channel: send a threaded email from anywhere,
 * let the recipient reply from their own mail client, and have that reply land
 * back in-app on the same thread (via controls/Webhook::mailgun). Any domain
 * object attaches to a thread through a generic relatedTo(type, id) link.
 *
 * Every send ALWAYS writes an outbound `notify` row and (re)uses an
 * `emailthread`, so the conversation is complete in-app regardless of whether a
 * real email goes out. When [app].demo_mode is on or [mail].enabled is off, the
 * Mailgun HTTP call is skipped and the message is marked 'sent' locally — the
 * whole subsystem runs offline for demos and tests.
 *
 * Usage:
 *   NotifyService::create()
 *       ->to('dealer@example.com', 'Dealer Name')
 *       ->subject('Your Order #42')
 *       ->relatedTo('order', 42)     // polymorphic link + thread-reuse key
 *       ->owner($memberId)           // who monitors the thread in the inbox
 *       ->send('<p>Hello</p>', ['/path/to/invoice.pdf']);
 *
 * Config:
 *   conf/mailgun.ini  key, domain, fromEmail, fromName, and (optional) signingKey,
 *                     inboundDomain, endpoint, demoMode  — the single source for
 *                     Mailgun settings (shared with lib/Mailer).
 *   conf/config.ini   [app].demo_mode  — offline switch (build threads in-app,
 *                     never call Mailgun).
 */

namespace app\services;

use app\Bean;
use Flight;
use Mailgun\Mailgun;

class NotifyService {

    /** Mailgun attachment ceiling — Mailgun rejects messages over 25 MB. */
    private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    private string $fromEmail   = '';
    private string $fromName    = '';
    private string $appName     = 'Tiknix';
    private string $toEmail     = '';
    private string $toName      = '';
    /** @var array<string> */
    private array  $ccList      = [];
    /** @var array<string> */
    private array  $bccList     = [];
    private string $subjectLine = 'Notification';

    private ?string $relatedType   = null;
    private ?int    $relatedId     = null;
    private ?int    $ownerMemberId = null;

    private ?string $inReplyTo      = null;
    /** @var array<string> */
    private array   $referencesList = [];

    private bool $showLoginButton = false;

    // Resolved config.
    private string  $apiKey        = '';
    private string  $domain        = '';
    private string  $inboundDomain = '';
    private string  $endpoint      = '';
    private bool    $demoMode      = false;
    private ?string $baseUrl       = null;
    private ?Mailgun $client       = null;
    private $logger;

    private static ?array $configCache = null;

    public static function create(): self {
        return new self();
    }

    public function __construct() {
        $this->logger = Flight::get('log');
        $this->loadConfig();
    }

    /**
     * Load Mailgun settings from conf/mailgun.ini — the tiknix convention,
     * shared with lib/Mailer. config.ini contributes only the app-level
     * [app].demo_mode switch. Cached per-process.
     *
     * mailgun.ini keys: key, domain, fromEmail, fromName, and (optional for
     * comms) signingKey, inboundDomain, endpoint, demoMode.
     */
    private function loadConfig(): void {
        if (self::$configCache === null) {
            $ini = [];
            $file = dirname(__DIR__) . '/conf/mailgun.ini';
            if (file_exists($file)) {
                $ini = parse_ini_file($file) ?: [];
            }

            // First non-empty value (ini values may be present-but-blank).
            $pick = static function (array $vals): string {
                foreach ($vals as $v) {
                    $v = trim((string)$v, " \t\n\r\0\x0B\"");
                    if ($v !== '') return $v;
                }
                return '';
            };

            $domain = $pick([$ini['domain'] ?? '']);
            self::$configCache = [
                'apiKey'        => $pick([$ini['key'] ?? '', $ini['apiKey'] ?? '']),
                'domain'        => $domain,
                'inboundDomain' => $pick([$ini['inboundDomain'] ?? '', $ini['inbound_domain'] ?? '', $domain]),
                'fromEmail'     => $pick([$ini['fromEmail'] ?? '', $domain !== '' ? 'noreply@' . $domain : '']),
                'fromName'      => $pick([$ini['fromName'] ?? '', (string)(Flight::get('app.name') ?: ''), 'Tiknix']),
                // Region endpoint — US (default) or https://api.eu.mailgun.net for EU domains.
                'endpoint'      => $pick([$ini['endpoint'] ?? '', $ini['apiUrl'] ?? '']),
                // Offline switch: config.ini [app].demo_mode, or demoMode in mailgun.ini.
                'demoMode'      => self::truthy(Flight::get('app.demo_mode') ?? ($ini['demoMode'] ?? false)),
            ];
        }

        $c = self::$configCache;
        $this->apiKey        = $c['apiKey'];
        $this->domain        = $c['domain'];
        $this->inboundDomain = $c['inboundDomain'] ?: $this->domain;
        $this->fromEmail     = $c['fromEmail'];
        $this->fromName      = $c['fromName'];
        $this->appName       = Flight::get('app.name') ?: ($this->fromName ?: 'Tiknix');
        $this->endpoint      = $c['endpoint'];
        $this->demoMode      = $c['demoMode'];
        $this->baseUrl       = Flight::get('app.baseurl') ?: (Flight::get('baseurl') ?: null);

        if ($this->apiKey !== '') {
            // Pass the region endpoint when configured (EU domains need
            // https://api.eu.mailgun.net); default US otherwise.
            $this->client = $this->endpoint !== ''
                ? Mailgun::create($this->apiKey, $this->endpoint)
                : Mailgun::create($this->apiKey);
        }
    }

    /** Loose truthiness for ini values ("true"/"1"/"on"/true → true). */
    private static function truthy($v): bool {
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes', 'enabled'], true);
    }

    // ---- fluent setters ----------------------------------------------------

    public function to(string $email, string $name = ''): self {
        $this->toEmail = trim($email);
        $safe = preg_replace('/[\r\n<>",;]/', '', $name);
        $this->toName = $safe !== '' ? $safe : $this->toEmail;
        return $this;
    }

    public function cc(string $email): self { return $this->addRecipient($this->ccList, $email); }
    public function bcc(string $email): self { return $this->addRecipient($this->bccList, $email); }

    private function addRecipient(array &$list, string $email): self {
        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $list[] = $email;
        }
        return $this;
    }

    public function from(string $email, string $name = ''): self {
        $this->fromEmail = trim($email);
        if ($name !== '') {
            $this->fromName = preg_replace('/[\r\n<>",;]/', '', $name);
        }
        return $this;
    }

    public function subject(string $subject): self {
        $this->subjectLine = $subject;
        return $this;
    }

    /** Polymorphic link + thread-reuse key, e.g. relatedTo('order', 42). */
    public function relatedTo(string $type, int $id): self {
        $this->relatedType = $type !== '' ? $type : null;
        $this->relatedId   = $id ?: null;
        return $this;
    }

    /** Member who monitors this thread in the inbox. */
    public function owner(int $memberId): self {
        $this->ownerMemberId = $memberId ?: null;
        return $this;
    }

    /** RFC-5322 threading on replies. */
    public function inReplyTo(?string $messageId, array $prevReferences = []): self {
        $this->inReplyTo      = $messageId;
        $this->referencesList = array_values(array_filter($prevReferences));
        if ($messageId && !in_array($messageId, $this->referencesList, true)) {
            $this->referencesList[] = $messageId;
        }
        return $this;
    }

    public function showLoginButton(bool $show = true): self {
        $this->showLoginButton = $show;
        return $this;
    }

    // ---- thread resolution -------------------------------------------------

    /**
     * Find-or-create the conversation thread for this send.
     *
     * Reuses an existing emailthread for (relatedType, relatedId) when both are
     * set; otherwise mints a fresh thread with a unique reply token. Threads are
     * always created so the conversation is visible in the inbox even for
     * one-shot sends.
     */
    private function resolveThread(): object {
        if ($this->relatedType !== null && $this->relatedId !== null) {
            $existing = Bean::findOne(
                'emailthread',
                'related_type = ? AND related_id = ?',
                [$this->relatedType, $this->relatedId]
            );
            if ($existing && $existing->id) {
                return $existing;
            }
        }

        $now = date('Y-m-d H:i:s');
        $thread = Bean::dispense('emailthread');
        $thread->subject         = $this->subjectLine;
        $thread->replyToken      = self::mintReplyToken();
        $thread->relatedType     = $this->relatedType;
        $thread->relatedId       = $this->relatedId;
        $thread->ownerMemberId   = $this->ownerMemberId ?: 0;
        $thread->recipientEmail  = $this->toEmail;
        $thread->recipientName   = $this->toName;
        $thread->messageCount    = 0;
        $thread->unreadCount     = 0;
        $thread->lastDirection   = 'out';
        $thread->lastPreview     = '';
        $thread->lastMessageAt   = $now;
        $thread->status          = 'open';
        $thread->createdAt       = $now;
        $thread->updatedAt       = $now;
        Bean::store($thread);
        return $thread;
    }

    /**
     * Mint a unique inbound-routing token.
     *
     * The comms schema is bean-pure, so `reply_token` has no DB-level UNIQUE
     * constraint (RedBean can't declare one on a non-*_id column via beans).
     * Uniqueness is guaranteed here instead: 16 random bytes = 128 bits of
     * entropy, so a collision is astronomically unlikely — but we still verify
     * against the table and re-roll on the vanishing chance of one.
     */
    private static function mintReplyToken(): string {
        do {
            $token = bin2hex(random_bytes(16));
        } while (Bean::count('emailthread', 'reply_token = ?', [$token]) > 0);
        return $token;
    }

    /** Strip Re:/Fwd: prefixes, collapse whitespace, lower-case — for matching. */
    public static function normalizeSubject(string $subject): string {
        $s = trim($subject);
        do {
            $before = $s;
            $s = preg_replace('/^\s*(?:re|fwd?)\s*:\s*/i', '', $s);
        } while ($s !== $before);
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower(trim($s), 'UTF-8');
    }

    // ---- send --------------------------------------------------------------

    /**
     * Send (or, in demo/offline mode, log) the message and thread it.
     *
     * @param string        $content     HTML body (wrapped in the email template)
     * @param array<string> $attachments Absolute file paths to attach
     * @return array{thread:int, notify:int, sent:bool, message_id:?string, error:?string}
     */
    public function send(string $content, array $attachments = []): array {
        if ($this->toEmail === '' || !filter_var($this->toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['thread' => 0, 'notify' => 0, 'sent' => false, 'message_id' => null, 'error' => 'No valid recipient'];
        }

        $now    = date('Y-m-d H:i:s');
        $thread = $this->resolveThread();

        // Stable Message-ID so inbound replies can be matched via In-Reply-To.
        $msgDomain = $this->domain ?: ($this->inboundDomain ?: 'localhost');
        $messageId = sprintf('<tk.%d.%s@%s>', (int)$thread->id, bin2hex(random_bytes(8)), $msgDomain);

        // ---- always write the outbound notify row --------------------------
        $notify = Bean::dispense('notify');
        $notify->threadId       = (int)$thread->id;
        $notify->direction      = 'out';
        $notify->notifyType     = 'email';
        $notify->fromEmail      = $this->fromEmail;
        $notify->fromName       = $this->fromName;
        $notify->toEmail        = $this->toEmail;
        $notify->toName         = $this->toName;
        $notify->subject        = $this->subjectLine;
        $notify->content        = $content;
        $notify->bodyPlain      = self::htmlToPlain($content);
        $notify->messageId      = $messageId;
        $notify->inReplyTo      = $this->inReplyTo ?? '';
        $notify->referencesList = $this->referencesList ? implode(' ', $this->referencesList) : '';
        $notify->relatedType    = $this->relatedType;
        $notify->relatedId      = $this->relatedId;
        $notify->ip             = $_SERVER['REMOTE_ADDR'] ?? 'internal';
        $notify->status         = 'queued';
        $notify->createdAt      = $now;

        $error = null;

        // ---- demo / offline: skip the wire, mark sent locally --------------
        if ($this->demoMode || !$this->client || $this->domain === '') {
            $notify->status = 'sent';
            $notify->sentAt = $now;
            $reason = $this->demoMode ? 'demo_mode' : (!$this->client || $this->domain === '' ? 'mailgun.ini not configured' : 'no client');
            $this->logger?->info("NotifyService: offline send ({$reason}) to {$this->toEmail} Re: {$this->subjectLine}", [
                'thread' => (int)$thread->id,
            ]);
        } else {
            // ---- live send via Mailgun -------------------------------------
            $params = [
                'from'         => "{$this->fromName} <{$this->fromEmail}>",
                'to'           => "{$this->toName} <{$this->toEmail}>",
                'subject'      => $this->subjectLine,
                'html'         => $this->wrapInTemplate($content),
                'h:Message-Id' => $messageId,
                'h:Reply-To'   => "reply-{$thread->replyToken}@{$this->inboundDomain}",
            ];
            if ($this->inReplyTo) {
                $params['h:In-Reply-To'] = $this->inReplyTo;
            }
            if (!empty($this->referencesList)) {
                $params['h:References'] = implode(' ', $this->referencesList);
            }
            if (!empty($this->ccList))  { $params['cc']  = implode(', ', $this->ccList); }
            if (!empty($this->bccList)) { $params['bcc'] = implode(', ', $this->bccList); }

            $attached = $this->collectAttachments($attachments);
            if (!empty($attached)) {
                $params['attachment'] = $attached;
            }

            try {
                $result = $this->client->messages()->send($this->domain, $params);
                $notify->status = 'sent';
                $notify->sentAt = date('Y-m-d H:i:s');
                if (is_object($result) && method_exists($result, 'getId')) {
                    $notify->providerId = $result->getId();
                }
                $this->logger?->info("NotifyService: sent to {$this->toEmail} Re: {$this->subjectLine}", [
                    'thread'    => (int)$thread->id,
                    'cc_count'  => count($this->ccList),
                    'bcc_count' => count($this->bccList),
                    'attach'    => count($attached),
                ]);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                $notify->status       = 'failed';
                $notify->errorMessage = $error;
                $this->logger?->error("NotifyService: send failed — {$error}");
            }
        }

        $notifyId = Bean::store($notify);

        // ---- bump thread on a successful (or offline-sent) message ---------
        if ($notify->status === 'sent') {
            $thread->messageCount  = (int)$thread->messageCount + 1;
            $thread->lastDirection = 'out';
            $thread->lastPreview   = self::previewFromHtml($content);
            $thread->lastMessageAt = $notify->sentAt ?: $now;
            $thread->updatedAt     = date('Y-m-d H:i:s');
            // Keep the recipient current for later replies from the inbox.
            if (!$thread->recipientEmail) { $thread->recipientEmail = $this->toEmail; }
            if (!$thread->recipientName)  { $thread->recipientName  = $this->toName; }
            Bean::store($thread);
        }

        return [
            'thread'     => (int)$thread->id,
            'notify'     => (int)$notifyId,
            'sent'       => $notify->status === 'sent',
            'message_id' => $messageId,
            'error'      => $error,
        ];
    }

    /**
     * Drop an in-app-only system note into a thread — NO email is sent.
     * Used to record internal events ("Order #42 shipped", "Address bounced")
     * so the thread owner sees them alongside real messages.
     */
    public static function createSystemMessage(int $threadId, string $html): ?int {
        $thread = Bean::load('emailthread', $threadId);
        if (!$thread->id) {
            return null;
        }
        $now = date('Y-m-d H:i:s');

        $n = Bean::dispense('notify');
        $n->threadId   = (int)$thread->id;
        $n->direction  = 'in';
        $n->notifyType = 'system';
        $n->fromEmail  = 'system@internal';
        $n->fromName   = 'System';
        $n->toEmail    = $thread->recipientEmail ?: '';
        $n->subject    = $thread->subject ?: 'System notice';
        $n->content    = $html;
        $n->bodyPlain  = self::htmlToPlain($html);
        $n->status     = 'received';
        $n->ip         = 'internal';
        $n->createdAt  = $now;
        $n->sentAt     = $now;
        $id = Bean::store($n);

        $thread->messageCount  = (int)$thread->messageCount + 1;
        $thread->unreadCount   = (int)$thread->unreadCount + 1;
        $thread->lastDirection = 'in';
        $thread->lastPreview   = self::previewFromHtml($html);
        $thread->lastMessageAt = $now;
        $thread->updatedAt     = $now;
        Bean::store($thread);

        return (int)$id;
    }

    /** One-shot convenience send (no relatedTo/owner threading key). */
    public static function sendEmail(string $to, string $subject, string $html, string $toName = ''): array {
        return self::create()->to($to, $toName)->subject($subject)->send($html);
    }

    // ---- helpers -----------------------------------------------------------

    /** Filter attachment paths to existing files under the 25 MB Mailgun cap. */
    private function collectAttachments(array $paths): array {
        $out = [];
        $total = 0;
        foreach ($paths as $path) {
            if (!is_string($path) || !file_exists($path)) continue;
            $size = (int)@filesize($path);
            if ($total + $size > self::MAX_ATTACHMENT_BYTES) {
                $this->logger?->warning('NotifyService: attachment skipped (25 MB cap)', ['path' => $path]);
                continue;
            }
            $total += $size;
            $out[] = ['filePath' => $path, 'filename' => basename($path)];
        }
        return $out;
    }

    private static function htmlToPlain(string $html): string {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function previewFromHtml(string $html): string {
        return mb_substr(self::htmlToPlain($html), 0, 220, 'UTF-8');
    }

    private function wrapInTemplate(string $content): string {
        $appName  = htmlspecialchars($this->appName ?: 'Tiknix');
        $loginUrl = $this->baseUrl ? rtrim($this->baseUrl, '/') : '#';
        $year     = date('Y');

        $loginBlock = $this->showLoginButton
            ? '<p style="text-align:center;margin:24px 0;"><a href="' . $loginUrl . '" style="display:inline-block;padding:12px 24px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Log in to ' . $appName . '</a></p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta name="viewport" content="width=device-width"/>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <title>{$appName}</title>
</head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#333;">
    <div style="max-width:600px;margin:0 auto;padding:20px;">
        <div style="background:#fff;border-radius:8px;padding:30px;border:1px solid #e9e9e9;">
            <div style="text-align:center;padding-bottom:16px;border-bottom:1px solid #eee;margin-bottom:20px;">
                <strong style="font-size:20px;color:#333;">{$appName}</strong>
            </div>
            <div>{$content}</div>
            {$loginBlock}
            <div style="text-align:center;padding-top:16px;border-top:1px solid #eee;margin-top:20px;color:#999;font-size:12px;">
                <p>You can reply directly to this email.</p>
                <p>&copy; {$year} {$appName}</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
