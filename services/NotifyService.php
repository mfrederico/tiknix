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
use app\ThreadMembers;
use app\Mentions;
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
    private ?int    $threadId      = null;

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

    /**
     * Override only the From display name (e.g. the sending member's real name),
     * leaving fromEmail as the verified Mailgun sender so Reply-To routing and
     * SPF/DKIM stay intact. Blank/whitespace names are ignored (keeps the default).
     */
    public function fromName(string $name): self {
        $safe = trim(preg_replace('/[\r\n<>",;]/', '', $name));
        if ($safe !== '') {
            $this->fromName = $safe;
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

    /**
     * Continue an existing conversation by id — the reliable way for inbox
     * replies to stay on the same thread even when it has no related entity.
     * Takes precedence over the (relatedType, relatedId) reuse key.
     */
    public function onThread(int $threadId): self {
        $this->threadId = $threadId ?: null;
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
        // Explicit thread id wins — inbox replies continue a known conversation
        // even when it carries no polymorphic related entity.
        if ($this->threadId !== null) {
            $existing = Bean::load('thread', $this->threadId);
            if ($existing && $existing->id) {
                return $existing;
            }
        }

        if ($this->relatedType !== null && $this->relatedId !== null) {
            $existing = Bean::findOne(
                'thread',
                'related_type = ? AND related_id = ?',
                [$this->relatedType, $this->relatedId]
            );
            if ($existing && $existing->id) {
                return $existing;
            }
        }

        $now = date('Y-m-d H:i:s');
        $thread = Bean::dispense('thread');
        $thread->kind            = 'email';
        $thread->subject         = $this->subjectLine;
        $thread->replyToken      = self::mintReplyToken();
        $thread->relatedType     = $this->relatedType;
        $thread->relatedId       = $this->relatedId;
        $thread->ownerMemberId   = $this->ownerMemberId ?: 0;
        $thread->recipientEmail  = $this->toEmail;
        $thread->recipientName   = $this->toName;
        $thread->messageCount    = 0;
        $thread->lastDirection   = 'out';
        $thread->lastPreview     = '';
        $thread->lastMessageAt   = $now;
        $thread->status          = 'open';
        $thread->createdAt       = $now;
        $thread->updatedAt       = $now;
        Bean::store($thread);
        // Seat the owner as a participant. An outbound-created thread had the same gap
        // inbound ones did: an owner_member_id and an empty roster, so nobody could be
        // added to a conversation this side started either.
        if ($this->ownerMemberId) {
            ThreadMembers::ensure((int)$thread->id, [(int)$this->ownerMemberId], ThreadMembers::ROLE_OWNER);
        }
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
        } while (Bean::count('thread', 'reply_token = ?', [$token]) > 0);
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
        $notify = Bean::dispense('message');
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
        $notify->messageEid     = $messageId;
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
    /**
     * Find or create the direct conversation between two people.
     *
     * A DM is identified by WHO IS IN IT, not by a subject line — so the lookup is over
     * participants, and the pair (a,b) is the same conversation as (b,a). Anything else
     * and every new message starts a new thread.
     */
    public static function dmThread(int $memberA, int $memberB): ?int {
        if ($memberA <= 0 || $memberB <= 0 || $memberA === $memberB) return null;

        $existing = (int) Bean::getCell(
            'SELECT t.id FROM emailthread t '
          . 'JOIN threadmember ma ON ma.thread_id = t.id AND ma.member_id = ? '
          . 'JOIN threadmember mb ON mb.thread_id = t.id AND mb.member_id = ? '
          . "WHERE t.kind = 'dm' LIMIT 1",
            [$memberA, $memberB]
        );
        if ($existing > 0) return $existing;

        $now = date('Y-m-d H:i:s');
        $thread = Bean::dispense('thread');
        $thread->subject        = '';        // a DM is named by its people, not a subject
        $thread->kind           = 'dm';
        $thread->replyToken     = self::mintReplyToken();
        $thread->ownerMemberId  = $memberA;
        $thread->recipientEmail = '';        // nothing here is addressed to an inbox
        $thread->recipientName  = '';
        $thread->messageCount   = 0;
        $thread->lastDirection  = 'out';
        $thread->lastPreview    = '';
        $thread->lastMessageAt  = $now;
        $thread->status         = 'open';
        $thread->createdAt      = $now;
        $thread->updatedAt      = $now;
        $id = (int) Bean::store($thread);
        if (!$id) return null;

        ThreadMembers::ensure($id, [$memberA], ThreadMembers::ROLE_OWNER);
        ThreadMembers::ensure($id, [$memberB]);
        return $id;
    }

    /**
     * Post a message that stays inside the application. No Mailgun, no message-id, no
     * reply token — it never leaves, so none of the email machinery applies.
     *
     * The sender is marked read immediately: nobody needs a badge for what they just
     * typed. Everyone else's unread is derived from their own read mark, so there is no
     * per-recipient counter to keep in step.
     *
     * @return int|null the new message id
     */
    public static function postInApp(int $threadId, int $senderMemberId, string $html): ?int {
        $thread = Bean::load('thread', $threadId);
        if (!$thread->id) return null;

        $now = date('Y-m-d H:i:s');
        $sender = Bean::load('member', $senderMemberId);

        $n = Bean::dispense('message');
        $n->threadId       = $threadId;
        $n->senderMemberId = $senderMemberId;
        $n->transport      = 'inapp';
        $n->direction      = 'out';
        $n->notifyType     = 'message';
        $n->fromEmail      = (string) ($sender->email ?? '');
        $n->fromName       = $sender->displayName('Someone');
        $n->toEmail        = '';
        $n->subject        = (string) ($thread->subject ?: '');
        $n->content        = $html;
        $n->bodyPlain      = self::htmlToPlain($html);
        $n->status         = 'delivered';    // in-app delivery is the write itself
        $n->ip             = $_SERVER['REMOTE_ADDR'] ?? '';
        $n->createdAt      = $now;
        $n->sentAt         = $now;
        $id = (int) Bean::store($n);
        if (!$id) return null;

        $thread->messageCount  = (int) $thread->messageCount + 1;
        $thread->lastDirection = 'out';
        $thread->lastPreview   = self::previewFromHtml($html);
        $thread->lastMessageAt = $now;
        $thread->updatedAt     = $now;
        Bean::store($thread);

        ThreadMembers::markRead($threadId, $senderMemberId);

        // Who this message named. Recorded here rather than at the call sites so every
        // way of posting — DM, room, team page — gets mentions without having to know
        // about them.
        Mentions::record($threadId, $id, $senderMemberId, $html);

        // Ring the doorbell. Not the sender: their own browser already rendered the
        // message, and a wake would make it re-fetch its own post.
        $thread->wakeParticipants($id, $senderMemberId);

        // If this conversation came from a connected platform, answer back to it.
        // Unchecked for the same reason as the wake: the reply is already stored,
        // and Model_Thread::relayExternal logs its own failures.
        $thread->relayExternal($html);

        return $id;
    }

    /**
     * Record a message written by someone with no account — a Telegram or Slack
     * handle (models/Model_Externalidentity.php).
     *
     * Mirrors postInApp(), with two differences that matter. sender_member_id
     * stays NULL and external_identity_ref carries the author instead: exactly one
     * of the two is ever set, so "who wrote this" has a single answer. And
     * provider_id holds the platform's own message id, which is what makes a
     * redelivered webhook a no-op rather than a second copy — the partial unique
     * index in database/migrate-external-identity.php enforces it.
     *
     * @return int|null message id, or null if this message is already stored
     */
    public static function postExternal(
        int $threadId, \RedBeanPHP\OODBBean $identity, string $html,
        string $transport, string $providerId = ''
    ): ?int {
        $thread = Bean::load('thread', $threadId);
        if (!$thread->id) return null;

        // Cheap check first; the unique index below is what actually guarantees it
        // under two retries arriving at once.
        if ($providerId !== '') {
            $seen = Bean::findOne('message', 'transport = ? AND provider_id = ?', [$transport, $providerId]);
            if ($seen && $seen->id) return null;
        }

        $now = date('Y-m-d H:i:s');
        $n = Bean::dispense('message');
        $n->threadId            = $threadId;
        $n->senderMemberId      = null;
        $n->externalIdentityRef = (int) $identity->id;
        $n->transport           = $transport;
        $n->direction           = 'in';
        $n->notifyType          = 'message';
        $n->fromEmail           = '';
        $n->fromName            = $identity->box()->displayName('Someone');
        $n->toEmail             = '';
        $n->subject             = (string) ($thread->subject ?: '');
        $n->content             = $html;
        $n->bodyPlain           = self::htmlToPlain($html);
        $n->providerId          = $providerId;
        $n->status              = 'received';
        $n->ip                  = $_SERVER['REMOTE_ADDR'] ?? '';
        $n->createdAt           = $now;
        $n->sentAt              = $now;

        try {
            $id = (int) Bean::store($n);
        } catch (\Throwable $e) {
            // idx_message_provider. The other delivery won the race and stored it,
            // which is the right outcome — this one just stops.
            return null;
        }
        if (!$id) return null;

        $thread->messageCount  = (int) $thread->messageCount + 1;
        $thread->lastDirection = 'in';
        $thread->lastPreview   = self::previewFromHtml($html);
        $thread->lastMessageAt = $now;
        $thread->updatedAt     = $now;
        Bean::store($thread);

        $identity->box()->touch();

        // No sender to exclude: whoever wrote this has no account to be reading in.
        $thread->wakeParticipants($id);

        return $id;
    }

    /**
     * Open a thread for something that arrived from OUTSIDE the email pipeline.
     *
     * The support form is the case this exists for. A contact submission is a message
     * from a person, and the inbox is where messages belong — but it never travelled
     * through Mailgun, so nothing here knew about it and it was only ever an emailed
     * alert plus a row in a table with no link to it.
     *
     * The thread is OWNED by the operator (so it appears in their Communications) and
     * ADDRESSED to whoever wrote in (so replying in the thread answers them, through the
     * ordinary reply path, with no special case). Idempotent per related entity: called
     * twice for the same contact row, it returns the existing thread.
     *
     * @return int|null thread id, or null if the thread could not be created
     */
    public static function openInboundThread(
        int $ownerMemberId,
        string $fromEmail,
        string $fromName,
        string $subject,
        string $html,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): ?int {
        if ($relatedType !== null && $relatedId !== null) {
            $existing = Bean::findOne('thread', 'related_type = ? AND related_id = ?',
                [$relatedType, $relatedId]);
            if ($existing && $existing->id) {
                self::recordInbound((int)$existing->id, $fromEmail, $fromName, $subject, $html);
                return (int)$existing->id;
            }
        }

        $now = date('Y-m-d H:i:s');
        $thread = Bean::dispense('thread');
        // The RAW subject: normalizeSubject() lowercases because it is a matching key
        // for grouping "Re: Foo" with "foo", not something to show a person.
        $thread->kind           = 'email';   // set at creation, not only by the backfill
        $thread->subject        = trim($subject);
        $thread->replyToken     = self::mintReplyToken();
        $thread->relatedType    = $relatedType;
        $thread->relatedId      = $relatedId;
        $thread->ownerMemberId  = $ownerMemberId;
        $thread->recipientEmail = $fromEmail;
        $thread->recipientName  = $fromName;
        $thread->messageCount   = 0;
        $thread->lastDirection  = 'in';
        $thread->lastPreview    = '';
        $thread->lastMessageAt  = $now;
        $thread->status         = 'open';
        $thread->createdAt      = $now;
        $thread->updatedAt      = $now;
        $id = (int) Bean::store($thread);
        if (!$id) return null;

        // The operator is a PARTICIPANT, not merely the owner. This was missed when
        // participants were introduced: support threads predate them, so they had an
        // owner and an empty roster — which meant nobody could be added to one, and a
        // support team could not share a conversation. owner_member_id names one person
        // and can only ever name one.
        ThreadMembers::ensure($id, [$ownerMemberId], ThreadMembers::ROLE_OWNER);

        self::recordInbound($id, $fromEmail, $fromName, $subject, $html);
        return $id;
    }

    /**
     * Record a received message on a thread and move the thread's counters.
     * Mirrors createSystemMessage(), but keeps the real sender rather than "System".
     */
    private static function recordInbound(
        int $threadId, string $fromEmail, string $fromName, string $subject, string $html
    ): ?int {
        $thread = Bean::load('thread', $threadId);
        if (!$thread->id) return null;
        $now = date('Y-m-d H:i:s');

        $n = Bean::dispense('message');
        $n->threadId   = $threadId;
        $n->direction  = 'in';
        $n->notifyType = 'inbound';
        $n->fromEmail  = $fromEmail;
        $n->fromName   = $fromName;
        $n->toEmail    = '';
        $n->subject    = $subject;
        $n->content    = $html;
        $n->bodyPlain  = self::htmlToPlain($html);
        $n->status     = 'received';
        $n->ip         = $_SERVER['REMOTE_ADDR'] ?? '';
        $n->createdAt  = $now;
        $n->sentAt     = $now;
        $id = (int) Bean::store($n);

        $thread->messageCount  = (int)$thread->messageCount + 1;
        $thread->lastDirection = 'in';
        $thread->lastPreview   = self::previewFromHtml($html);
        $thread->lastMessageAt = $now;
        $thread->updatedAt     = $now;
        Bean::store($thread);

        // No sender to exclude: this came from an email address, not an account.
        $thread->wakeParticipants($id);

        return $id;
    }

    public static function createSystemMessage(int $threadId, string $html): ?int {
        $thread = Bean::load('thread', $threadId);
        if (!$thread->id) {
            return null;
        }
        $now = date('Y-m-d H:i:s');

        $n = Bean::dispense('message');
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
        $thread->lastDirection = 'in';
        $thread->lastPreview   = self::previewFromHtml($html);
        $thread->lastMessageAt = $now;
        $thread->updatedAt     = $now;
        Bean::store($thread);

        // A system notice is still something arriving in someone's inbox.
        $thread->wakeParticipants((int)$id);

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
        $appName  = htmlspecialchars(($this->appName ?: 'Tiknix') ?? '');
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
