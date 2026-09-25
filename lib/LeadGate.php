<?php
/**
 * LeadGate — the evidence that a lead came from a person, required by Model_Lead::capture().
 *
 * A public form (the landing sign-up, an enquiry, a booking made by a visitor) passes
 * through the checks this install has for bots, and the gate carries the verdict:
 *
 *   $gate = LeadGate::forPublicForm($this->getParams(), (string) Flight::request()->ip, [
 *       'honeypot' => 'company_website',                  // a hidden field a person never fills
 *       'shown_at' => $_SESSION['lead_form_shown'] ?? 0, // when the form was rendered
 *       'name'     => [$first, $last], 'email' => $email,
 *   ]);
 *   Model_Lead::capture($email, $first, $last, ['gate' => $gate, ...]);
 *
 * The checks: Cloudflare Turnstile (REQUIRED — a public lead form on an install where
 * Turnstile is not connected throws, naming Connections → Security, rather than accepting
 * whatever posts), the honeypot, the fill time, and LeadValidator's content signals.
 * A failed check FLAGS the lead as spam rather than refusing it: a bot told "rejected"
 * tries again differently until something works; one told "thank you" stops, and the
 * evidence stays in the leads table where a person can check what the filter caught.
 *
 * Where no visitor is involved — staff recording a walk-in, a signed-in member booking —
 * there is nothing to check, and the caller says so in words:
 *
 *   Model_Lead::capture($email, $first, $last, ['gate' => LeadGate::trusted('staff booking for a signed-in member')]);
 *
 * There is no default. capture() without a gate is an error, so a new form cannot forget.
 */
namespace app;

final class LeadGate {

    /** Faster than this and nobody read the form, let alone typed into it. */
    public const MIN_FILL_SECONDS = 3;

    public string $kind;            // 'public' | 'trusted'
    public string $why;             // for a trusted gate: who vouched and how
    /** @var string[] what the checks found; empty means clean */
    public array $reasons = [];

    private function __construct(string $kind, string $why = '') { $this->kind = $kind; $this->why = $why; }

    /**
     * Run this install's bot checks on a public submission.
     *
     * @param array $params the request parameters (the Turnstile token is read from Turnstile::FIELD)
     * @param array{honeypot?:string,shown_at?:int,name?:array{0:string,1:string},email?:string} $opts
     * @throws \RuntimeException when Turnstile is not connected on this install
     */
    public static function forPublicForm(array $params, string $ip, array $opts = []): self {
        if (!Turnstile::enabled()) {
            throw new \RuntimeException('LeadGate: a public lead form needs Cloudflare Turnstile, and it is not connected on this install — add the site and secret keys under Connections → Security (/connections), or record the lead with LeadGate::trusted() if no visitor is involved.');
        }
        $g = new self('public');
        if (!Turnstile::verify(isset($params[Turnstile::FIELD]) ? (string) $params[Turnstile::FIELD] : null, $ip)) {
            $g->reasons[] = 'turnstile';
        }
        if (isset($opts['honeypot']) && trim((string) ($params[$opts['honeypot']] ?? '')) !== '') {
            $g->reasons[] = 'honeypot';
        }
        $shown = (int) ($opts['shown_at'] ?? 0);
        if ($shown > 0 && (time() - $shown) < self::MIN_FILL_SECONDS) {
            $g->reasons[] = 'submitted in ' . (time() - $shown) . 's';
        }
        if (isset($opts['name'], $opts['email'])) {
            // Raw values, not sanitized ones: sanitize() runs htmlspecialchars, so O'Brien
            // becomes "O&#039;Brien" and a content rule would fail a real person on our escaping.
            foreach (LeadValidator::signals((string) ($opts['name'][0] ?? ''), (string) ($opts['name'][1] ?? ''), (string) $opts['email']) as $signal) {
                $g->reasons[] = $signal;
            }
        }
        return $g;
    }

    /** No visitor to check: a staff action or a signed-in member. Say why, in words. */
    public static function trusted(string $why): self {
        $why = trim($why);
        if ($why === '') throw new \RuntimeException('LeadGate::trusted() needs a reason, e.g. "staff walk-in booking".');
        return new self('trusted', $why);
    }

    public function isTrusted(): bool { return $this->kind === 'trusted'; }

    /** The lead status this gate produces: spam when any public check failed. */
    public function status(): string {
        return $this->kind === 'public' && $this->reasons ? \Model_Lead::STATUS_SPAM : \Model_Lead::STATUS_NEW;
    }

    public function reason(): string { return implode(', ', $this->reasons); }
}
