<?php
/**
 * Mailer - Email Service using Mailgun
 *
 * Provides email sending functionality via Mailgun API.
 * Configuration loaded from conf/mailgun.ini
 */

namespace app;

use \Flight as Flight;
use Mailgun\Mailgun;
use \Exception as Exception;

class Mailer {

    private static ?Mailer $instance = null;
    private ?Mailgun $client = null;

    private string $domain = '';
    private string $fromEmail = '';
    private string $fromName = '';
    private string $toEmail = '';
    private string $toName = '';
    private string $subject = '';
    private string $replyTo = '';

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
     * Constructor - loads config from mailgun.ini
     */
    public function __construct() {
        $this->loadConfig();
    }

    /**
     * Load configuration from mailgun.ini
     */
    private function loadConfig(): void {
        $configPath = dirname(__DIR__) . '/conf/mailgun.ini';

        if (!file_exists($configPath)) {
            Flight::get('log')->warning('Mailer: mailgun.ini not found');
            return;
        }

        try {
            $config = parse_ini_file($configPath);

            if (empty($config['key']) || empty($config['domain'])) {
                Flight::get('log')->warning('Mailer: Missing key or domain in mailgun.ini');
                return;
            }

            $this->client = Mailgun::create($config['key']);
            $this->domain = $config['domain'];
            $this->fromEmail = $config['fromEmail'] ?? "noreply@{$this->domain}";
            $this->fromName = $config['fromName'] ?? Flight::get('app.name') ?? 'Tiknix';
            $this->configured = true;

        } catch (Exception $e) {
            Flight::get('log')->error('Mailer: Failed to load config - ' . $e->getMessage());
        }
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
            Flight::get('log')->error('Mailer: Not configured - email not sent');
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
            'subject' => $this->subject ?: 'Message from ' . (Flight::get('app.name') ?? 'Tiknix'),
            'html' => $html,
        ];

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
        $appName = Flight::get('app.name') ?? 'Tiknix';
        $baseUrl = Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? 'https://tiknix.com';
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
        $appName = Flight::get('app.name') ?? 'Tiknix';

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
        $appName = Flight::get('app.name') ?? 'Tiknix';

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
        $appName = Flight::get('app.name') ?? 'Tiknix';

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
     * Send welcome email after registration
     */
    public static function sendWelcome(string $email, string $name): bool {
        $appName = Flight::get('app.name') ?? 'Tiknix';
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
