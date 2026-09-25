<?php
/**
 * Lead FUSE Model — a person who gave their name and email: a sign-up, an enquiry, a booking.
 *
 * capture() is the ONE way a lead row is written. Every form and flow that meets a person for
 * the first time (the landing form, an appointment, a checkout, a newsletter box) goes through
 * it, so an install has one lead per email address with every blank filled in over time, not
 * one row per form they ever touched:
 *
 *   $lead = Model_Lead::capture('zoe@example.com', 'Zoë', 'Quinn', ['source' => 'appointment', 'phone' => '555-0100']);
 *
 * Rules the callers used to each get slightly wrong:
 *   - one lead per email, matched case-insensitively; the stored email is lower-cased;
 *   - an existing lead is never overwritten: blank first/last name and phone are filled in,
 *     everything else (source, status) stays as it was — a website lead who later books is
 *     still a website lead;
 *   - `source` says where the person first came from ('website', 'appointment', 'checkout',
 *     'newsletter' …) and is set only on create;
 *   - `status` is 'new' or 'spam', set on create from the caller's checks (Index::dolead runs
 *     Turnstile, timing and LeadValidator); a repeat submission never downgrades a lead.
 *
 * Lived in bookingscheduler as lib/LeadCapture and in core as inline code in Index::dolead,
 * which did not dedupe at all. Columns beyond core's originals (source, phone, updated_at)
 * come from services/Schema/Seeds/19_LeadCapture.php.
 */

class Model_Lead extends \RedBeanPHP\SimpleModel {

    public const STATUS_NEW  = 'new';
    public const STATUS_SPAM = 'spam';

    /**
     * Find or create the lead for $email; returns the stored bean.
     *
     * @param array{source?:string,phone?:string,status?:string,spamReason?:string,ip?:string,userAgent?:string} $opts
     * @throws RuntimeException on an email that is not an email address
     */
    public static function capture(string $email, string $first, string $last, array $opts = []): \RedBeanPHP\OODBBean {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("Lead: '{$email}' is not an email address.");
        }
        $first = trim($first); $last = trim($last); $phone = trim((string) ($opts['phone'] ?? ''));
        $now = date('Y-m-d H:i:s');

        $lead = \app\Bean::findOne('lead', 'LOWER(email) = ?', [$email]);
        if ($lead && $lead->id) {
            if (trim((string) $lead->firstName) === '' && $first !== '') $lead->firstName = $first;
            if (trim((string) $lead->lastName) === '' && $last !== '')   $lead->lastName  = $last;
            if (trim((string) $lead->phone) === '' && $phone !== '')     $lead->phone     = $phone;
            if ((string) $lead->email !== $email) $lead->email = $email;
            $lead->updatedAt = $now;
            \app\Bean::store($lead);
            return $lead;
        }

        $status = (string) ($opts['status'] ?? self::STATUS_NEW);
        if (!in_array($status, [self::STATUS_NEW, self::STATUS_SPAM], true)) {
            throw new \RuntimeException("Lead: status must be 'new' or 'spam', not '{$status}'.");
        }
        $lead = \app\Bean::dispense('lead');
        $lead->email      = $email;
        $lead->firstName  = $first;
        $lead->lastName   = $last;
        $lead->phone      = $phone;
        $lead->source     = trim((string) ($opts['source'] ?? 'website'));
        $lead->status     = $status;
        $lead->spamReason = (string) ($opts['spamReason'] ?? '');
        $lead->ipAddress  = mb_substr((string) ($opts['ip'] ?? ''), 0, 45);
        $lead->userAgent  = mb_substr((string) ($opts['userAgent'] ?? ''), 0, 255);
        $lead->createdAt  = $now;
        $lead->updatedAt  = $now;
        \app\Bean::store($lead);
        return $lead;
    }

    /**
     * "Ana María López" → ['Ana', 'María López']; "Cher" → ['Cher', '']. Everything before the
     * first space is the first name. Never null: the name columns are NOT NULL on some installs.
     *
     * @return array{0:string,1:string}
     */
    public static function splitName(string $full): array {
        $full = trim(preg_replace('/\s+/', ' ', $full) ?? '');
        if ($full === '') return ['', ''];
        $parts = explode(' ', $full, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    public function fullName(): string {
        return trim((string) $this->bean->firstName . ' ' . (string) $this->bean->lastName);
    }

    public function isSpam(): bool {
        return (string) $this->bean->status === self::STATUS_SPAM;
    }
}
