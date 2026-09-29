<?php
/**
 * Lead FUSE Model — a person who gave their name and email: a sign-up, an enquiry, a booking.
 *
 * capture() is the ONE way a lead row is written. Every form and flow that meets a person for
 * the first time (the landing form, an appointment, a checkout, a newsletter box) goes through
 * it, so an install has one lead per email address with every blank filled in over time, not
 * one row per form they ever touched:
 *
 *   $lead = Model_Lead::capture('zoe@example.com', 'Zoë', 'Quinn', [
 *       'gate' => LeadGate::forPublicForm($params, $ip, [...]),   // or LeadGate::trusted('staff walk-in')
 *       'source' => 'appointment', 'phone' => '555-0100',
 *   ]);
 *
 * Rules the callers used to each get slightly wrong:
 *   - one lead per email, matched case-insensitively; the stored email is lower-cased;
 *   - an existing lead is never overwritten: blank first/last name and phone are filled in,
 *     everything else (source, status) stays as it was — a website lead who later books is
 *     still a website lead;
 *   - `source` says where the person first came from ('website', 'appointment', 'checkout',
 *     'newsletter' …) and is set only on create;
 *   - every capture carries a LeadGate (lib/LeadGate.php): a public form's Turnstile /
 *     honeypot / timing / content verdict, or a stated reason no visitor was involved;
 *     `status` ('new' | 'spam') and `spam_reason` come from it on create, and a repeat
 *     submission never downgrades a lead. Without a gate there is no lead.
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
     * @param array{gate:\app\LeadGate,source?:string,phone?:string,ip?:string,userAgent?:string} $opts
     *   gate  REQUIRED — LeadGate::forPublicForm() for anything a visitor posted (Turnstile,
     *         honeypot, timing, content checks; a failure flags the lead as spam), or
     *         LeadGate::trusted('why') when no visitor is involved. No gate, no lead.
     * @throws RuntimeException on a missing gate or an email that is not an email address
     */
    public static function capture(string $email, string $first, string $last, array $opts = []): \RedBeanPHP\OODBBean {
        $gate = $opts['gate'] ?? null;
        if (!$gate instanceof \app\LeadGate) {
            throw new \RuntimeException("Lead: capture() needs a gate — LeadGate::forPublicForm(...) for a visitor's form, or LeadGate::trusted('why') when no visitor is involved.");
        }
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

        $lead = \app\Bean::dispense('lead');
        $lead->email      = $email;
        $lead->firstName  = $first;
        $lead->lastName   = $last;
        $lead->phone      = $phone;
        $lead->source     = trim((string) ($opts['source'] ?? 'website'));
        $lead->status     = $gate->status();
        $lead->spamReason = $gate->reason();
        $lead->gate       = $gate->isTrusted() ? 'trusted: ' . $gate->why : 'public';
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
