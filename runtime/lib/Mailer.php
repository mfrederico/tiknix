<?php
/**
 * Mailer - Email Service using Mailgun
 *
 * Sends through the install's `mail` connection (CONNECTOR-CATALOG-PLAN.md decision 9): a
 * Mailgun connection in this install's own connection store, bound to core's `mail` role
 * (ConnectionBindings::for('core', 'mail') — one candidate binds itself; a site may bind its
 * own). The API key, the sending domain, the region endpoint, the from-address and the
 * inbound domain all come from that connection; conf/mailgun.ini is no longer read (seed
 * 23_MailConnection migrated it into a connection once). settings() is the one resolver —
 * NotifyService and /webhook/mailgun read the same answer.
 */

namespace app;

use \Flight as Flight;
use Mailgun\Mailgun;
use \Exception as Exception;

class Mailer {

    public const CONNECTOR = 'mailgun';
    private const US_ENDPOINT = 'https://api.mailgun.net';

    /**
     * The install's mail settings, from the bound `mail` connection. Throws, naming the fix,
     * when there is none (MissingConnectorException: connect one under Connections →
     * Mailgun), when it is ambiguous or dead (UnboundRoleException), or when the connection
     * is broken (RuntimeException: no sending domain, key will not decrypt).
     *
     * The from-address: a SITE's own `[mail] from_email` (conf/sites/<slug>.ini, recorded
     * by Sites::applyConfig) wins, then the connection's from_email field, then
     * noreply@<sending domain>. Not config.ini's [mail] from_email: that section is the
     * SMTP/example block and on most installs still says noreply@example.com.
     *
     * @return array{connection:\RedBeanPHP\OODBBean,alias:string,key:string,domain:string,endpoint:string,from_email:string,from_name:string,inbound_domain:string,signing_key:string}
     */
    public static function settings(): array {
        $conn = ConnectionBindings::for(ConnectionBindings::CORE, 'mail');
        $alias = ConnectionStore::alias($conn);
        $meta = json_decode((string) ($conn->metadataJson ?? ''), true);
        $fields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
        $domain = trim((string) ($fields['domain'] ?? ''));
        if ($domain === '') {
            throw new \RuntimeException("Mail: the Mailgun connection '{$alias}' (#{$conn->id}) has no sending domain — reconnect it under Connections → Mailgun with the domain filled in.");
        }
        $key = ConnectionStore::ownToken($conn);
        if ($key === '') {
            throw new \RuntimeException("Mail: the Mailgun connection '{$alias}' (#{$conn->id}) has no usable API key (empty, or it could not be decrypted with this install's key) — reconnect it under Connections → Mailgun.");
        }
        $base = rtrim((string) ($meta['base_url'] ?? self::US_ENDPOINT), '/');
        $applied = Flight::get('site.config_applied');
        $siteFrom = is_array($applied) && in_array('mail.from_email', $applied, true) ? trim((string) Flight::get('mail.from_email')) : '';
        $from = $siteFrom !== '' ? $siteFrom : (trim((string) ($fields['from_email'] ?? '')) ?: "noreply@{$domain}");
        return [
            'connection'     => $conn,
            'alias'          => $alias,
            'key'            => $key,
            'domain'         => $domain,
            'endpoint'       => $base === self::US_ENDPOINT ? '' : $base,   // the SDK's default is US
            'from_email'     => $from,
            'from_name'      => Flight::siteName(),
            'inbound_domain' => trim((string) ($fields['inbound_domain'] ?? '')) ?: $domain,
            'signing_key'    => ConnectionStore::ownSecret($conn, 'webhookSecret'),
        ];
    }

    private static ?Mailer $instance = null;
    private ?Mailgun $client = null;

    private string $domain = '';
    private string $fromEmail = '';
    private string $fromName = '';
    private string $toEmail = '';
    private string $toName = '';
    private string $subject = '';
    private string $replyTo = '';
    private array $cc = [];
    private array $bcc = [];

    private array $attachments = [];
    private bool $configured = false;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Static factory for fluent API
     */
    public static function create(): self {
        return new self();
    }

    /**
     * Constructor - resolves the bound mail connection
     */
    public function __construct() {
        $this->loadConfig();
    }

    /** Why the last construction found no usable mail connection ('' when configured). */
    private string $notConfigured = '';

    /**
     * Resolve the `mail` binding. No connection is a legitimate state for a fresh install
     * (mail is optional) and is logged as a WARNING naming the fix; a connection that exists
     * but is broken is an ERROR. Either way send() refuses and says which.
     */
    private function loadConfig(): void {
        try {
            $s = self::settings();
        } catch (MissingConnectorException | UnboundRoleException $e) {
            $this->notConfigured = $e->getMessage();
            Flight::get('log')?->warning('Mailer: no mail connection — ' . $e->getMessage());
            return;
        } catch (\Throwable $e) {
            $this->notConfigured = $e->getMessage();
            Flight::get('log')?->error('Mailer: mail connection unusable — ' . $e->getMessage());
            return;
        }
        $this->client = $s['endpoint'] !== '' ? Mailgun::create($s['key'], $s['endpoint']) : Mailgun::create($s['key']);
        $this->domain = $s['domain'];
        $this->fromEmail = $s['from_email'];
        // From-NAME is the site's display name (setSender()/from() can still override per message).
        $this->fromName = $s['from_name'];
        $this->configured = true;
    }

    /**
     * Check if mailer is configured
     */
    public static function isConfigured(): bool {
        return self::getInstance()->configured;
    }

    /**
     * Set recipient
     */
    public function to(string $email, string $name = ''): self {
        if (empty($email)) {
            throw new Exception('Recipient email is required');
        }

        $this->toEmail = filter_var($email, FILTER_SANITIZE_EMAIL);
        $this->toName = $name ?: $email;

        return $this;
    }

    /**
     * Add a CC recipient. Call more than once to add several; each is "Name <email>".
     */
    public function cc(string $email, string $name = ''): self {
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        if ($email !== '') $this->cc[] = $name ? "{$name} <{$email}>" : $email;
        return $this;
    }

    /**
     * Add a BCC recipient. Call more than once to add several.
     */
    public function bcc(string $email, string $name = ''): self {
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        if ($email !== '') $this->bcc[] = $name ? "{$name} <{$email}>" : $email;
        return $this;
    }

    /**
     * Set sender (override default)
     */
    public function from(string $email, string $name = ''): self {
        $this->fromEmail = filter_var($email, FILTER_SANITIZE_EMAIL);
        if ($name) {
            $this->fromName = $name;
        }

        return $this;
    }

    /**
     * Set subject
     */
    public function subject(string $subject): self {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set reply-to address
     */
    public function replyTo(string $email): self {
        $this->replyTo = filter_var($email, FILTER_SANITIZE_EMAIL);
        return $this;
    }

    /**
     * Add attachment
     */
    public function attach(string $filePath, string $filename = ''): self {
        if (file_exists($filePath)) {
            $this->attachments[] = [
                'filePath' => $filePath,
                'filename' => $filename ?: basename($filePath)
            ];
        }
        return $this;
    }

    /**
     * Send email with HTML content
     */
    public function send(string $content, string $plainText = ''): bool {
        if (!$this->configured) {
            Flight::get('log')->error('Mailer: email not sent — ' . ($this->notConfigured ?: 'no mail connection'));
            return false;
        }

        if (empty($this->toEmail)) {
            Flight::get('log')->error('Mailer: No recipient specified');
            return false;
        }

        // Wrap content in HTML template
        $html = $this->wrapInTemplate($content);

        // Build message parameters
        $params = [
            'from' => "{$this->fromName} <{$this->fromEmail}>",
            'to' => $this->toName ? "{$this->toName} <{$this->toEmail}>" : $this->toEmail,
            'subject' => $this->subject ?: 'Message from ' . (Flight::siteName()),
            'html' => $html,
        ];

        // CC / BCC (Mailgun takes comma-separated recipient lists)
        if ($this->cc)  $params['cc']  = implode(',', $this->cc);
        if ($this->bcc) $params['bcc'] = implode(',', $this->bcc);

        // Add plain text if provided
        if ($plainText) {
            $params['text'] = $plainText;
        }

        // Add reply-to if set
        if ($this->replyTo) {
            $params['h:Reply-To'] = $this->replyTo;
        }

        // Add attachments
        if (!empty($this->attachments)) {
            $params['attachment'] = $this->attachments;
        }

        try {
            Flight::get('log')->info("Mailer: Sending to {$this->toEmail} - {$this->subject}");

            $this->client->messages()->send($this->domain, $params);

            Flight::get('log')->info("Mailer: Sent successfully");

            // Reset for next email
            $this->reset();

            return true;

        } catch (Exception $e) {
            Flight::get('log')->error('Mailer: Send failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reset state for next email
     */
    private function reset(): void {
        $this->toEmail = '';
        $this->toName = '';
        $this->subject = '';
        $this->replyTo = '';
        $this->attachments = [];
    }

    /**
     * Wrap content in HTML email template
     */
    private function wrapInTemplate(string $content): string {
        $appName = Flight::siteName();
        $baseUrl = app_url();
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta name="viewport" content="width=device-width"/>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <title>{$appName}</title>
    <style type="text/css">
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            background-color: #f6f6f6;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .content {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #333;
            font-size: 24px;
        }
        .body-content {
            color: #333;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #0d6efd;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
        }
        .btn:hover {
            background-color: #0b5ed7;
        }
        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #eee;
            margin-top: 20px;
            color: #999;
            font-size: 12px;
        }
        .footer a {
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            <div class="header">
                <h1>{$appName}</h1>
            </div>
            <div class="body-content">
                {$content}
            </div>
            <div class="footer">
                <p>&copy; {$year} {$appName}. All rights reserved.</p>
                <p><a href="{$baseUrl}">{$baseUrl}</a></p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    // ==================== Convenience Methods ====================

    /**
     * Send password reset email
     */
    public static function sendPasswordReset(string $email, string $name, string $resetUrl): bool {
        $appName = Flight::siteName();

        $content = <<<HTML
<h2>Password Reset Request</h2>
<p>Hi {$name},</p>
<p>We received a request to reset your password. Click the button below to create a new password:</p>
<p style="text-align: center;">
    <a href="{$resetUrl}" class="btn">Reset Password</a>
</p>
<p>Or copy and paste this link into your browser:</p>
<p style="word-break: break-all; color: #666; font-size: 12px;">{$resetUrl}</p>
<p><strong>This link will expire in 1 hour.</strong></p>
<p>If you didn't request this, you can safely ignore this email.</p>
<p>Thanks,<br>The {$appName} Team</p>
HTML;

        return self::create()
            ->to($email, $name)
            ->subject("Reset your {$appName} password")
            ->send($content);
    }

    /**
     * Send contact form response
     */
    public static function sendContactResponse(
        string $toEmail,
        string $toName,
        string $originalSubject,
        string $originalMessage,
        string $responseText,
        string $adminName
    ): bool {
        $appName = Flight::siteName();

        $content = <<<HTML
<h2>Response to Your Message</h2>
<p>Hi {$toName},</p>
<p>Thank you for contacting us. Here is our response to your inquiry:</p>
<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 20px 0;">
    <strong>Your original message:</strong>
    <p style="color: #666;">{$originalSubject}</p>
    <p style="color: #666; font-style: italic;">{$originalMessage}</p>
</div>
<div style="background: #e7f3ff; padding: 15px; border-radius: 6px; margin: 20px 0;">
    <strong>Our response:</strong>
    <p>{$responseText}</p>
    <p style="color: #666; font-size: 12px;">- {$adminName}</p>
</div>
<p>If you have any further questions, feel free to reply to this email.</p>
<p>Best regards,<br>The {$appName} Team</p>
HTML;

        return self::create()
            ->to($toEmail, $toName)
            ->subject("Re: {$originalSubject}")
            ->send($content);
    }

    /**
     * Send team invitation
     */
    public static function sendTeamInvite(
        string $email,
        string $teamName,
        string $inviterName,
        string $role,
        string $acceptUrl
    ): bool {
        $appName = Flight::siteName();

        $content = <<<HTML
<h2>You've Been Invited!</h2>
<p>Hi there,</p>
<p><strong>{$inviterName}</strong> has invited you to join the team <strong>{$teamName}</strong> as a <strong>{$role}</strong>.</p>
<p style="text-align: center;">
    <a href="{$acceptUrl}" class="btn">Accept Invitation</a>
</p>
<p>Or copy and paste this link into your browser:</p>
<p style="word-break: break-all; color: #666; font-size: 12px;">{$acceptUrl}</p>
<p>This invitation will expire in 7 days.</p>
<p>Best regards,<br>The {$appName} Team</p>
HTML;

        return self::create()
            ->to($email)
            ->subject("You're invited to join {$teamName} on {$appName}")
            ->send($content);
    }

    /**
     * Invitation to join Tiknix itself, while public sign-ups are closed.
     *
     * Distinct from sendTeamInvite: that one adds an existing person to a team, this one
     * is the only route to an account at all. It says who invited them and when the link
     * dies, because an invitation with neither reads like spam — and this arrives
     * unsolicited, to someone who has never heard of us.
     */
    public static function sendSiteInvite(
        string $email,
        string $inviterName,
        string $acceptUrl,
        string $expiresOn,
        string $note = ''
    ): bool {
        $appName = Flight::siteName();
        $safeNote = $note !== ''
            ? '<p style="border-left:3px solid #ddd;padding-left:12px;color:#444"><em>'
              . htmlspecialchars($note, ENT_QUOTES) . '</em></p>'
            : '';
        $who = htmlspecialchars($inviterName, ENT_QUOTES);

        $content = <<<HTML
<h2>You've been invited to {$appName}</h2>
<p><strong>{$who}</strong> has invited you to {$appName}, where you describe what you want built and AI agents build it.</p>
{$safeNote}
<p style="text-align: center;">
    <a href="{$acceptUrl}" class="btn">Accept your invitation</a>
</p>
<p>Or copy and paste this link into your browser:</p>
<p style="word-break: break-all; color: #666; font-size: 12px;">{$acceptUrl}</p>
<p><strong>This invitation expires on {$expiresOn}</strong> and can only be used once, for this email address.</p>
<p>If you weren't expecting this, you can ignore it — no account is created until you use the link.</p>
<p>Best regards,<br>The {$appName} Team</p>
HTML;

        return self::create()
            ->to($email)
            ->subject("{$inviterName} invited you to {$appName}")
            ->send($content);
    }

    /**
     * Tell the operators a support message arrived.
     *
     * The contact form used to write a row and stop there. Nothing announced it, so the
     * table accumulated five months of messages at "new" — including ones from signed-in
     * members, who had every reason to think somebody was reading them. Filing a message
     * where nobody looks is the same as losing it, so arrival is now announced.
     *
     * Failure to send is reported to the caller rather than swallowed: the message itself
     * is already saved, and an operator who is told delivery failed can go and look.
     */
    public static function sendContactAlert(
        string $toEmail,
        string $fromName,
        string $fromEmail,
        string $category,
        string $subject,
        string $message,
        int $contactId,
        bool $fromMember
    ): bool {
        $appName = Flight::siteName();
        $baseUrl = rtrim((string) (Flight::get('app.baseurl') ?? ''), '/');
        $link    = $baseUrl . '/contact/view?id=' . $contactId;

        $who  = htmlspecialchars($fromName, ENT_QUOTES);
        $addr = htmlspecialchars($fromEmail, ENT_QUOTES);
        $subj = htmlspecialchars($subject, ENT_QUOTES);
        $cat  = htmlspecialchars($category, ENT_QUOTES);
        $body = nl2br(htmlspecialchars($message, ENT_QUOTES));

        // Worth stating plainly at the top: a message from a signed-in member is a
        // different thing from the anonymous traffic this form mostly receives.
        $badge = $fromMember
            ? '<p style="background:#e7f3ff;padding:8px 12px;border-radius:6px;margin:0 0 16px">'
              . '<strong>From a signed-in member.</strong></p>'
            : '';

        $content = <<<HTML
<h2>New support message</h2>
{$badge}
<p><strong>{$who}</strong> &lt;{$addr}&gt; &mdash; <em>{$cat}</em></p>
<p style="font-size:16px"><strong>{$subj}</strong></p>
<div style="background:#f8f9fa;padding:15px;border-radius:6px;margin:20px 0">{$body}</div>
<p><a href="{$link}" class="btn">Open it in {$appName}</a></p>
<p style="word-break:break-all;color:#666;font-size:12px">{$link}</p>
HTML;

        return self::create()
            ->to($toEmail)
            ->subject("[{$cat}] {$subject}")
            ->send($content);
    }

    /**
     * Send welcome email after registration
     */
    public static function sendWelcome(string $email, string $name): bool {
        $appName = Flight::siteName();
        $baseUrl = Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? '';

        $content = <<<HTML
<h2>Welcome to {$appName}!</h2>
<p>Hi {$name},</p>
<p>Thanks for signing up! Your account is now active and ready to use.</p>
<p style="text-align: center;">
    <a href="{$baseUrl}/dashboard" class="btn">Go to Dashboard</a>
</p>
<p>If you have any questions, don't hesitate to reach out.</p>
<p>Best regards,<br>The {$appName} Team</p>
HTML;

        return self::create()
            ->to($email, $name)
            ->subject("Welcome to {$appName}!")
            ->send($content);
    }
}
