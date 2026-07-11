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

        $thread = Bean::findOne('emailthread', 'reply_token = ?', [$replyToken]);
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
            $notify = Bean::dispense('notify');
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
            $notify->messageId      = $messageId;
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
            $thread->unreadCount   = (int)$thread->unreadCount + 1;
            $thread->lastDirection = 'in';
            $thread->lastPreview   = mb_substr(preg_replace('/\s+/u', ' ', trim($displayPlain)), 0, 220, 'UTF-8');
            $thread->lastMessageAt = $now;
            $thread->updatedAt     = $now;
            Bean::store($thread);

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
            ? Bean::findOne('notify', 'message_id = ? AND direction = ?', [$messageId, 'out'])
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

            $att = Bean::dispense('notifyattachment');
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
}
