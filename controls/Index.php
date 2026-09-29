<?php
/**
 * Index Controller
 * Handles the home page and public content
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Index extends BaseControls\Control {

    /**
     * The hidden field bots fill in and people never see.
     *
     * Named like something worth filling. Calling it "honeypot" would tell the one bot
     * that reads field names to skip it, and the ones that don't read names were never
     * going to be fooled by the label anyway.
     */
    private const HONEYPOT_FIELD = 'company_website';

    
    /**
     * Home page — an app's own. Until the app's builder replaces it (override views/index/
     * coming-soon.php, or this controller), it is the pre-launch lead-capture page. The
     * platform's marketing landing is core's own override of this controller.
     */
    public function index() {
        // First-run setup takes precedence over the landing page.
        if (!Install::isInstalled()) { Flight::redirect('/install'); return; }
        $this->renderComingSoon();
    }

    /**
     * The pre-launch lead-capture page. It was the landing until the marketing page
     * took that slot; kept reachable at /index/comingsoon (and still the default for
     * instance clones) so the lead form + its Turnstile gate are not lost.
     */
    public function comingsoon() {
        if (!Install::isInstalled()) { Flight::redirect('/install'); return; }
        $this->renderComingSoon();
    }

    private function renderComingSoon(): void {
        /* When the form was put in front of someone, so dolead() can tell a person typing
           from a script posting. Session rather than a hidden field: a value in the form is
           just another thing for the bot to replay, and this needs to be something it
           cannot see or set. */
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['lead_form_shown'] = time();

        $this->render('index/coming-soon', [
            'title' => 'Coming Soon',
            'subscribed' => (bool)$this->getParam('subscribed'),
            'flagship' => false,
            'showcase' => [],
        ], false);
    }

    /**
     * Process the "Coming Soon" lead capture form.
     * Saves the visitor's name + email as a `lead`, then returns to the
     * landing page with a thank-you message. Public endpoint (covered by the
     * index::* permission).
     */
    public function dolead() {
        // Validate CSRF
        if (!$this->validateCSRF()) {
            return;
        }

        $firstName = trim($this->sanitize($this->getParam('first_name')));
        $lastName  = trim($this->sanitize($this->getParam('last_name')));
        $email     = trim($this->sanitize($this->getParam('email'), 'email'));

        /* Bot signals live in LeadGate (Turnstile, the honeypot, the fill time, and
           LeadValidator's content signals). CSRF cannot help here — a bot loads the page,
           takes a valid token and posts it, which is exactly what the real traffic looked
           like: real harvested addresses, generated names, one submission an hour around
           the clock to stay under any rate limit. The gate FLAGS rather than refuses, and
           the evidence stays in the leads table. Raw values go to the content checks:
           sanitize() runs htmlspecialchars, so O'Brien would fail a rule on OUR escaping. */
        $shown = (int) ($_SESSION['lead_form_shown'] ?? 0);
        unset($_SESSION['lead_form_shown']);   // one submission per render
        $gate = \app\LeadGate::forPublicForm($this->getParams(), (string) (Flight::request()->ip ?? ''), [
            'honeypot' => self::HONEYPOT_FIELD,
            'shown_at' => $shown,
            'name'     => [(string) $this->getParam('first_name'), (string) $this->getParam('last_name')],
            'email'    => (string) $this->getParam('email'),
        ]);

        // Basic validation
        if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Please enter your name and a valid email address.');
            Flight::redirect('/');
            return;
        }

        try {
            /* Recorded, not refused. A bot told "rejected" tries again differently until
               something works; one told "thank you" stops thinking about you. Marking it
               also keeps the evidence — a filter that silently deletes cannot be checked,
               and the first question about any spam filter is what it caught by mistake.
               One lead per email (Model_Lead::capture): a person who signs up twice fills in
               their one row rather than adding another, and a returning lead is never
               re-marked by a later, worse-looking submission. IP and agent are stored
               because there was nothing to look at before: no way to tell one source from
               many. */
            $lead = \Model_Lead::capture($email, $firstName, $lastName, [
                'gate'      => $gate,
                'source'    => 'website',
                'ip'        => (string) (Flight::request()->ip ?? ''),
                'userAgent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);

            if ($gate->reasons) {
                Flight::get('log')->info('Lead flagged as spam', [
                    'email' => $email, 'reason' => $gate->reason(),
                    'ip' => (string) $lead->ipAddress,
                ]);
            }
        } catch (\Throwable $e) {
            Flight::get('log')->error('Lead capture error: ' . $e->getMessage());
            $this->flash('error', 'Sorry, something went wrong. Please try again.');
            Flight::redirect('/');
            return;
        }

        // Back to the landing page in its "thank you" state
        Flight::redirect('/?subscribed=1');
    }
}
