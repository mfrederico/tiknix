<?php
/**
 * Webhook — inbound mail + delivery-event receiver for the comms subsystem.
 *
 * Mailgun forwards any mail matching a Receiving Route
 *   match_recipient("reply-.*@<inbound_domain>")
 *   forward("https://<host>/webhook/mailgun")
 * to POST here. The reply token is baked into the local-part
 * (reply-{token}@domain), and that token maps 1:1 to an emailthread — so a
 * recipient replying from their own mail client lands back on the same thread.
 *
 * Single-tenant by design. (A future multi-tenant tiknix would widen the
 * local-part to reply-{slug}-{token}@ and resolve the workspace from {slug}
 * before the token lookup — the slug slot is reserved in the regex comment.)
 *
 * Security: HMAC over (timestamp + token) against [mail].mailgun_signing_key,
 * with a ±5 min freshness window. With no signing key configured we accept
 * unverified but log a warning (dev only).
 *
 * Response codes are deliberate:
 *   403 signature/auth failure   → Mailgun retries (harmless)
 *   200 unknown token/thread     → Mailgun stops retrying (unrecoverable)
 *   500 storage failure          → Mailgun retries with backoff
 *
 * Route level: webhook::mailgun = 101 (PUBLIC) — this endpoint authenticates
 * itself via HMAC; it is not session/CSRF protected.
 */

namespace app;

use \app\BaseControls\Control;
use \app\Bean;
use \app\services\NotifyService;

class Webhook extends Control {

    /** Inbound mail + Mailgun event webhooks. */
    public function mailgun(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            \Flight::json(['error' => 'POST only'], 405);
            return;
        }

        $mail       = $this->mailConfig();
        $signingKey = trim((string)($mail['signingKey'] ?? ''), '"');

        // Mailgun posts JSON for event webhooks (delivered/failed/bounced) and
        // form-encoded/multipart for inbound mail. Branch on content type.
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $this->handleEvent($signingKey);
            return;
        }

        // --- HMAC verification (inbound mail) -------------------------------
        $timestamp = (string)($_POST['timestamp'] ?? '');
        $token     = (string)($_POST['token']     ?? '');
        $signature = (string)($_POST['signature'] ?? '');

        if ($signingKey !== '') {
            if ($timestamp === '' || $token === '' || $signature === '') {
                $this->logger?->warning('Webhook: missing signature fields');
                \Flight::json(['error' => 'Signature fields missing'], 403);
                return;
            }
            $expected = hash_hmac('sha256', $timestamp . $token, $signingKey);
            if (!hash_equals($expected, $signature)) {
                $this->logger?->warning('Webhook: HMAC mismatch');
                \Flight::json(['error' => 'Invalid signature'], 403);
                return;
            }
            if (abs(time() - (int)$timestamp) > 300) {
                $this->logger?->warning('Webhook: stale timestamp');
                \Flight::json(['error' => 'Stale request'], 403);
                return;
            }
        } else {
            $this->logger?->warning('Webhook: no signing key configured — accepting unverified (dev only)');
        }

        // --- Fields Mailgun sends for inbound mail --------------------------
        $recipient     = trim((string)($_POST['recipient']     ?? ''));
        $sender        = trim((string)($_POST['sender']        ?? ''));
        $fromRaw       = trim((string)($_POST['from']          ?? $sender));
        $subject       = trim((string)($_POST['subject']       ?? '(no subject)'));
        $bodyPlain     = (string)($_POST['body-plain']    ?? '');
        $bodyHtml      = (string)($_POST['body-html']     ?? '');
        $strippedHtml  = (string)($_POST['stripped-html'] ?? '');
        $strippedPlain = (string)($_POST['stripped-text'] ?? '');
        $messageId     = trim((string)($_POST['Message-Id']  ?? ($_POST['message-id']  ?? '')));
        $inReplyTo     = trim((string)($_POST['In-Reply-To'] ?? ($_POST['in-reply-to'] ?? '')));
        $referencesHdr = trim((string)($_POST['References']  ?? ($_POST['references']  ?? '')));

        // Route by recipient local-part: reply-{token}@<domain>.
        // (Multi-tenant reserve: reply-{slug}-{token}@ — resolve {slug} here.)
        $replyToken = null;
        if (preg_match('/reply-([a-f0-9]{32,})@/i', $recipient, $m)) {
            $replyToken = strtolower($m[1]);
        }
        if ($replyToken === null) {
            $this->logger?->info('Webhook: recipient did not match reply-{token} pattern', ['recipient' => $recipient]);
            \Flight::json(['accepted' => false, 'reason' => 'unrecognized recipient'], 200);
            return;
        }

        $thread = Bean::findOne('thread', 'reply_token = ?', [$replyToken]);
        if (!$thread || !$thread->id) {
            $this->logger?->info('Webhook: no thread for token', ['token' => $replyToken]);
            \Flight::json(['accepted' => false, 'reason' => 'unknown thread'], 200);
            return;
        }

        // Parse "From: Name <email>".
        $fromName  = '';
        $fromEmail = $sender;
        if (preg_match('/^\s*"?([^"<]+?)"?\s*<([^>]+)>\s*$/', $fromRaw, $fm)) {
            $fromName  = trim($fm[1]);
            $fromEmail = trim($fm[2]);
        }

        // Prefer stripped bodies (no quoted history) for display.
        $displayHtml  = $strippedHtml  !== '' ? $strippedHtml  : $bodyHtml;
        $displayPlain = $strippedPlain !== '' ? $strippedPlain : $bodyPlain;

        try {
            $now = date('Y-m-d H:i:s');
            $notify = Bean::dispense('message');
            $notify->threadId       = (int)$thread->id;
            $notify->direction      = 'in';
            $notify->notifyType     = 'email';
            $notify->fromEmail      = $fromEmail;
            $notify->fromName       = $fromName;
            $notify->toEmail        = $recipient;
            $notify->subject        = $subject;
            $notify->content        = $displayHtml !== ''
                ? $this->sanitizeInboundHtml($displayHtml)
                : nl2br(htmlspecialchars(($displayPlain) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $notify->bodyPlain      = $displayPlain;
            $notify->messageEid     = $messageId;
            $notify->inReplyTo      = $inReplyTo;
            $notify->referencesList = $referencesHdr;
            $notify->status         = 'received';
            $notify->ip             = $_SERVER['REMOTE_ADDR'] ?? 'mailgun';
            $notify->createdAt      = $now;
            $notify->sentAt         = $now;
            Bean::store($notify);

            $this->saveAttachments($thread, $notify);

            // Bump thread counters — new inbound message + unread badge.
            $thread->messageCount  = (int)$thread->messageCount + 1;
            $thread->lastDirection = 'in';
            $thread->lastPreview   = mb_substr(preg_replace('/\s+/u', ' ', trim($displayPlain)), 0, 220, 'UTF-8');
            $thread->lastMessageAt = $now;
            $thread->updatedAt     = $now;
            Bean::store($thread);

            // An emailed reply is the one arrival nobody is sitting there expecting,
            // so it is the one that most needs to announce itself. No sender to
            // exclude — it came from an address, not an account.
            $thread->wakeParticipants((int)$notify->id);

            $this->logger?->info('Webhook: inbound stored', [
                'thread_id' => (int)$thread->id,
                'from'      => $fromEmail,
                'subject'   => $subject,
            ]);
            \Flight::json(['accepted' => true, 'thread_id' => (int)$thread->id], 200);
        } catch (\Throwable $e) {
            $this->logger?->error('Webhook: inbound storage failed', ['err' => $e->getMessage()]);
            \Flight::json(['error' => 'Storage failure'], 500);
        }
    }

    /**
     * GitHub push/PR webhook → fire the instance's matching deploy pipelines.
     *
     * DELIVERED TO THE INSTANCE, at https://<slug>.tiknix.com/webhook/github. The
     * domain is the tenant scope, which is why this handler does no instance
     * resolution: it reads its own connection, verifies against its own secret and
     * fires its own pipelines. Nothing here can see another tenant's data, because
     * it is not running in a process that has any.
     *
     * Self-authenticating (PUBLIC route, HMAC only): verifies X-Hub-Signature-256
     * against this install's webhook secret, checks the delivered repo matches the
     * one this install is connected to, then fires every pipeline whose
     * trigger.github matches the event+branch. Dedupes on the delivery id and applies
     * a short cooldown so a push flood can't fork-bomb. Route: webhook::github = 101.
     */
    public function github(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { \Flight::json(['error' => 'POST only'], 405); return; }

        $raw      = file_get_contents('php://input') ?: '';
        $event    = strtolower((string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? ''));
        $sig      = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        $delivery = (string) ($_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? '');

        if ($event === 'ping') { \Flight::json(['accepted' => true, 'pong' => true], 200); return; }

        $payload = json_decode($raw, true) ?: [];
        $repo    = (string) ($payload['repository']['full_name'] ?? '');   // "owner/repo"
        if ($repo === '' || strpos($repo, '/') === false) { \Flight::json(['accepted' => false, 'reason' => 'no repository'], 200); return; }
        [$owner, $name] = explode('/', $repo, 2);

        // THIS install's GitHub connection. The hook is delivered to
        // <slug>.tiknix.com, so the domain already answered "whose is this?" before
        // the request arrived -- there is no repo->instance lookup to do, and the
        // scan that used to live here read every tenant's connection to find out.
        $conn = \app\ConnectionStore::for('github');
        if (!$conn) {
            // Loud on purpose. Reached most likely by a hook still pointed at core
            // from before the move, and "no GitHub connection on this install" is a
            // fact worth saying rather than a silent 200 that looks like success.
            $this->logger?->warning('Webhook/github: no GitHub connection on this install', ['repo' => $repo]);
            \Flight::json(['accepted' => false, 'reason' => 'no GitHub connection on this install'], 200);
            return;
        }

        // The repo match survives as a GUARD, not as a lookup: this install has one
        // GitHub connection, and a delivery for a different repo means the hook is
        // wired to the wrong place. Silently firing that instance's pipelines from
        // another repo's push is the failure worth refusing.
        $meta = json_decode((string) ($conn->metadataJson ?: '{}'), true) ?: [];
        if (strcasecmp((string) ($meta['owner'] ?? ''), $owner) !== 0
            || strcasecmp((string) ($meta['repo'] ?? ''), $name) !== 0) {
            $this->logger?->warning('Webhook/github: delivery for a repo this install is not connected to', [
                'delivered' => $repo,
                'connected' => (string) ($meta['owner'] ?? '') . '/' . (string) ($meta['repo'] ?? ''),
            ]);
            \Flight::json(['accepted' => false, 'reason' => 'this install is not connected to ' . $repo], 200);
            return;
        }

        // HMAC verify — MANDATORY (public endpoint, no dev bypass).
        $secret = '';
        try { $secret = (string) \app\EncryptionService::decrypt((string) ($conn->webhookSecret ?? '')); } catch (\Throwable $e) {}
        if ($secret === '') { \Flight::json(['error' => 'no webhook secret set for this connection'], 403); return; }
        $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
        if ($sig === '' || !hash_equals($expected, $sig)) {
            $this->logger?->warning('Webhook/github: HMAC mismatch', ['repo' => $repo]);
            \Flight::json(['error' => 'invalid signature'], 403);
            return;
        }

        // Dedupe redeliveries; cooldown floods (risk #1).
        if ($this->seenDelivery($delivery)) { \Flight::json(['accepted' => true, 'duplicate' => true], 200); return; }
        if ($this->webhookCooldown()) { \Flight::json(['accepted' => true, 'throttled' => true], 200); return; }

        // The install this code is running in IS the instance -- no instance row to
        // load and no directory to resolve. That lookup only existed because the
        // delivery landed on core and core had to find its way back here.
        $dir    = dirname(__DIR__);
        $branch = (string) preg_replace('#^refs/heads/#', '', (string) ($payload['ref'] ?? ''));
        $sha    = (string) ($payload['after'] ?? ($payload['head_commit']['id'] ?? ($payload['pull_request']['head']['sha'] ?? '')));
        $context = [
            'event'  => $event,
            'branch' => $branch,
            'ref'    => (string) ($payload['ref'] ?? ''),
            'sha'    => $sha,
            'repo'   => $repo,
            'pusher' => (string) ($payload['pusher']['name'] ?? ($payload['sender']['login'] ?? '')),
        ];
        $fired = \app\InstanceAutomations::fireGithub($dir, $event, $branch, $context);
        $this->logger?->info('Webhook/github fired', ['repo' => $repo, 'event' => $event, 'branch' => $branch, 'fired' => count($fired)]);
        \Flight::json(['accepted' => true, 'fired' => $fired], 200);
    }

    /** True if this delivery id was already processed (idempotency); records it otherwise. */
    private function seenDelivery(string $delivery): bool {
        if ($delivery === '') return false;
        if (($ex = Bean::findOne('webhookdelivery', 'delivery = ?', [$delivery])) && $ex->id) return true;
        $d = Bean::dispense('webhookdelivery'); $d->delivery = $delivery; $d->createdAt = date('Y-m-d H:i:s'); Bean::store($d);
        try { \RedBeanPHP\R::exec('DELETE FROM webhookdelivery WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]); } catch (\Throwable $e) {}
        return false;
    }

    /**
     * True if this install fired a webhook within the cooldown window (blunts push
     * floods). No instance id: one install is one instance, so there is one window.
     */
    private function webhookCooldown(int $seconds = 5): bool {
        $key  = 'gh';
        $rate = Bean::findOne('webhookrate', 'rkey = ?', [$key]);
        $now  = time();
        if ($rate && $rate->id && ($now - (int) $rate->lastAt) < $seconds) return true;
        if (!$rate || !$rate->id) { $rate = Bean::dispense('webhookrate'); $rate->rkey = $key; }
        $rate->lastAt = $now; Bean::store($rate);
        return false;
    }

    /**
     * Mailgun event webhook (JSON): delivered / failed / permanent_fail /
     * bounced. On a hard failure, mark the matching outbound notify 'failed'
     * and drop a system note so the thread owner sees the dead address.
     * Always returns 200 so Mailgun stops retrying event deliveries.
     */
    private function handleEvent(string $signingKey): void {
        $raw     = file_get_contents('php://input') ?: '';
        $payload = json_decode(($raw) ?? '', true);
        $data    = $payload['event-data'] ?? [];
        $sig     = $payload['signature']  ?? [];

        // Event signature: HMAC over (timestamp + token).
        if ($signingKey !== '' && !empty($sig)) {
            $expected = hash_hmac('sha256', ($sig['timestamp'] ?? '') . ($sig['token'] ?? ''), $signingKey);
            if (!hash_equals($expected, (string)($sig['signature'] ?? ''))) {
                $this->logger?->warning('Webhook: event HMAC mismatch');
                \Flight::json(['accepted' => false, 'reason' => 'bad signature'], 200);
                return;
            }
        }

        $event = strtolower((string)($data['event'] ?? ''));
        $isHardFail = in_array($event, ['failed', 'permanent_fail', 'bounced', 'rejected'], true);
        $severity = strtolower((string)($data['severity'] ?? ''));
        if ($event === 'failed' && $severity === 'temporary') {
            $isHardFail = false; // transient — Mailgun will retry
        }
        if (!$isHardFail) {
            \Flight::json(['accepted' => true, 'ignored' => $event], 200);
            return;
        }

        // Match the outbound notify by our Message-Id (Mailgun echoes it back).
        $messageId = (string)($data['message']['headers']['message-id'] ?? '');
        if ($messageId !== '' && $messageId[0] !== '<') {
            $messageId = "<{$messageId}>";
        }
        $recipient = (string)($data['recipient'] ?? '');

        $notify = $messageId !== ''
            ? Bean::findOne('message', 'message_eid = ? AND direction = ?', [$messageId, 'out'])
            : null;

        if ($notify && $notify->id) {
            $notify->status       = 'failed';
            $notify->errorMessage = trim(($data['reason'] ?? '') . ' ' . ($data['delivery-status']['message'] ?? ''));
            Bean::store($notify);

            NotifyService::createSystemMessage(
                (int)$notify->threadId,
                '<p><strong>Delivery failed</strong> to ' . htmlspecialchars(($recipient) ?? '', ENT_QUOTES) . '.'
                . ' The message could not be delivered (' . htmlspecialchars(($event) ?? '', ENT_QUOTES) . ').</p>'
            );
            $this->logger?->warning('Webhook: outbound marked failed', [
                'notify_id' => (int)$notify->id,
                'thread_id' => (int)$notify->threadId,
                'event'     => $event,
            ]);
        } else {
            $this->logger?->info('Webhook: failure event with no matching outbound', ['message_id' => $messageId]);
        }

        \Flight::json(['accepted' => true, 'event' => $event], 200);
    }

    /** Persist Mailgun inbound file uploads as notifyattachment rows. */
    private function saveAttachments($thread, $notify): void {
        if (empty($_FILES)) return;
        $dir = 'public/uploads/inbound-mail/' . (int)$thread->id;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) return;

        foreach ($_FILES as $key => $f) {
            if (empty($f['tmp_name']) || ($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) continue;
            $origName = $f['name'] ?: $key;
            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $origName);
            $dest = $dir . '/' . bin2hex(random_bytes(4)) . '-' . $safeName;
            if (!move_uploaded_file($f['tmp_name'], $dest)) continue;

            $att = Bean::dispense('messageattachment');
            $att->threadId  = (int)$thread->id;
            $att->notifyId  = (int)$notify->id;
            $att->filename  = $origName;
            $att->diskPath  = substr($dest, strlen('public')); // web-accessible path
            $att->mimeType  = $f['type'] ?: 'application/octet-stream';
            $att->size      = (int)$f['size'];
            $att->createdAt = date('Y-m-d H:i:s');
            Bean::store($att);
        }
    }

    /**
     * Allowlist-sanitize inbound HTML so a reply rendered in the inbox can't
     * carry script/style/event-handler payloads. Strips tags outside the
     * allowlist, inline on-event attributes, and javascript: URIs.
     */
    private function sanitizeInboundHtml(string $html): string {
        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><span><div><blockquote><pre><code><img>';
        $clean = strip_tags($html, $allowed);
        // Drop on* event attributes and javascript: URIs (quoted + unquoted).
        $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/\b(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $clean);
        $clean = preg_replace('/\b(href|src)\s*=\s*javascript:[^\s>]*/i', '$1="#"', $clean);
        return $clean;
    }

    /** Mailgun settings from conf/mailgun.ini (single source, shared with Mailer). */
    private function mailConfig(): array {
        $file = dirname(__DIR__) . '/conf/mailgun.ini';
        if (!file_exists($file)) return [];
        $ini = parse_ini_file($file) ?: [];
        // Normalize aliases so callers can rely on signingKey/inboundDomain.
        $ini['signingKey']    = $ini['signingKey']    ?? $ini['webhook_signing_key'] ?? $ini['signing_key'] ?? '';
        $ini['inboundDomain'] = $ini['inboundDomain'] ?? $ini['inbound_domain'] ?? ($ini['domain'] ?? '');
        return $ini;
    }

    // ---- telegram ----------------------------------------------------------------

    /**
     * POST /webhook/telegram/<connection id> — inbound messages from a bot.
     *
     * The connection id in the URL is addressing, not authentication: it is a
     * small integer anybody could guess. The secret is
     * X-Telegram-Bot-Api-Secret-Token, which Telegram sends on every delivery
     * because setWebhook was given it, and which is compared here in constant time.
     *
     * Almost everything answers 200. Telegram retries any non-2xx and disables a
     * webhook that keeps failing, so returning 500 because one message was
     * malformed would take the whole channel down. The body says what happened and
     * the log says why; only a genuinely retryable fault deserves a non-200, and
     * there isn't one here — the message is either stored or it is not wanted.
     */
    public function telegram(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            \Flight::json(['error' => 'POST only'], 405);
            return;
        }

        // This install's own store. The id addresses a row in THIS instance's file,
        // so an id from another tenant simply is not there -- the scoping that used
        // to be absent from this lookup is now a property of which file is open.
        $op   = $this->routeParams['operation'] ?? null;
        $cid  = (int) (is_object($op) ? ($op->name ?? 0) : 0);
        $conn = $cid > 0
            ? \app\ConnectionStore::withOwnDb(fn() => Bean::load('connections', $cid), null)
            : null;

        if (!$conn || !$conn->id || (string) $conn->connectorType !== 'telegram') {
            $this->logger?->warning('Telegram webhook: no such connection', ['id' => $cid]);
            \Flight::json(['accepted' => false, 'reason' => 'unknown connection'], 404);
            return;
        }

        $secret = (string) ($conn->webhookSecret ?? '');
        $sent   = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if ($secret === '' || !hash_equals($secret, $sent)) {
            // 403, not 200: an unsigned caller is not Telegram, and there is nothing
            // for Telegram to retry. Loud, because this is the one failure that means
            // somebody is poking at the endpoint.
            $this->logger?->warning('Telegram webhook: bad or missing secret token',
                ['connection' => $cid, 'had_secret' => $secret !== '']);
            \Flight::json(['accepted' => false, 'reason' => 'forbidden'], 403);
            return;
        }

        if (!empty($conn->revokedAt) || (int) $conn->enabled !== 1) {
            \Flight::json(['accepted' => false, 'reason' => 'connection disabled'], 200);
            return;
        }

        // Per connection, not per IP: Telegram delivers from many addresses, and the
        // thing worth bounding is how much one channel can push at us.
        $perMinute = (int) (\Flight::get('integrations.inbound_per_minute') ?: 120);
        if (!\app\RateLimiter::shared('webhook:telegram:' . $cid, $perMinute, 60)) {
            $this->logger?->warning('Telegram webhook: rate limited', ['connection' => $cid, 'limit' => $perMinute]);
            \Flight::json(['accepted' => false, 'reason' => 'rate limited'], 200);
            return;
        }

        $update = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($update)) {
            \Flight::json(['accepted' => false, 'reason' => 'unparseable'], 200);
            return;
        }

        $msg = \app\services\connectors\TelegramConnector::parseUpdate($update);
        if (!$msg) {
            // A sticker, a join, a poll. Real events, but not messages.
            \Flight::json(['accepted' => true, 'stored' => false, 'reason' => 'nothing to store'], 200);
            return;
        }
        if ($msg['is_bot']) {
            // Including our own sends echoing back, which would otherwise be stored
            // as if somebody had said them.
            \Flight::json(['accepted' => true, 'stored' => false, 'reason' => 'from a bot'], 200);
            return;
        }

        try {
            $identity = \Model_Externalidentity::resolve((int) $conn->id, $msg['from_eid'], [
                'display_name'    => $msg['from_name'],
                'external_handle' => $msg['from_handle'],
            ]);
            if (!$identity) {
                // resolve() already logged why (the cap).
                \Flight::json(['accepted' => true, 'stored' => false, 'reason' => 'identity cap'], 200);
                return;
            }
            if ($identity->box()->isBlocked()) {
                \Flight::json(['accepted' => true, 'stored' => false, 'reason' => 'blocked'], 200);
                return;
            }

            $thread = $this->telegramThread($conn, $msg);
            $id = NotifyService::postExternal(
                (int) $thread->id, $identity,
                nl2br(htmlspecialchars($msg['text'], ENT_QUOTES, 'UTF-8')),
                'telegram', $msg['message_id']
            );

            \Flight::json(['accepted' => true, 'stored' => $id !== null,
                           'thread_id' => (int) $thread->id, 'message_id' => $id], 200);
        } catch (\Throwable $e) {
            // 200 on purpose. A bug here must not make Telegram disable the webhook
            // for every future message; the log is where this gets found.
            $this->logger?->error('Telegram webhook: storing failed',
                ['connection' => $cid, 'err' => $e->getMessage()]);
            \Flight::json(['accepted' => true, 'stored' => false, 'reason' => 'error'], 200);
        }
    }

    /**
     * The thread for one Telegram chat, created the first time that chat speaks.
     *
     * Keyed on (connection, chat id) so a group keeps one conversation however many
     * people are in it — which is what makes it read like a channel rather than a
     * pile of separate messages.
     */
    private function telegramThread($conn, array $msg) {
        $thread = Bean::findOne('thread', 'connection_ref = ? AND external_ref = ?',
            [(int) $conn->id, $msg['chat_id']]);
        if ($thread && $thread->id) return $thread;

        $now = date('Y-m-d H:i:s');
        $thread = Bean::dispense('thread');
        $thread->kind          = 'external';
        $thread->connectionRef = (int) $conn->id;
        $thread->externalRef   = $msg['chat_id'];
        $thread->subject       = $msg['chat_type'] === 'private'
                               ? $msg['chat_title']
                               : '#' . $msg['chat_title'];
        // The person who connected the bot owns the conversation, and is who gets
        // woken. wakeParticipants() falls back to the owner when there are no
        // participant rows, which is exactly this case.
        $thread->ownerMemberId = (int) $conn->memberId;
        $thread->status        = 'open';
        $thread->messageCount  = 0;
        $thread->createdAt     = $now;
        $thread->updatedAt     = $now;
        Bean::store($thread);

        return $thread;
    }
}
