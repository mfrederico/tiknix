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
     * Home page
     */
    public function index() {
        // First-run setup takes precedence over the landing page.
        if (!Install::isInstalled()) { Flight::redirect('/install'); return; }

        // Rendered without the Tiknix header/footer layout so visitors see a clean,
        // standalone page. The PRIMARY tiknix.com site shows the marketing landing
        // (ICP: freelance devs & small agencies). Provisioned instances are clones of
        // this app, so a non-flagship host still shows the plain "coming soon" lead
        // page — no confusion about which is the real Tiknix, and no marketing pitch
        // on someone else's project.
        if (self::isFlagship()) {
            $showcase = $this->showcaseItems();
            $this->render('index/landing', [
                'title'    => 'tiknix — build a real app for every client',
                'showcase' => $showcase,
                'stories'  => \Model_Showcase::stories($showcase),
            ], false);
            return;
        }
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

    /** Render the coming-soon lead page (shared by index() on a clone and comingsoon()). */
    private function renderComingSoon(): void {
        /* When the form was put in front of someone, so dolead() can tell a person typing
           from a script posting. Session rather than a hidden field: a value in the form is
           just another thing for the bot to replay, and this needs to be something it
           cannot see or set. */
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['lead_form_shown'] = time();

        $flagship = self::isFlagship();
        $this->render('index/coming-soon', [
            'title' => 'Coming Soon',
            'subscribed' => (bool)$this->getParam('subscribed'),
            'flagship' => $flagship,
            'showcase' => $flagship ? $this->showcaseItems() : [],
        ], false);
    }

    /**
     * Whether THIS deploy is the primary marketing site (the root control plane),
     * as opposed to a provisioned instance clone. Marketing surfaces (showcase +
     * pricing) show only here. Reuses the established host-based detection
     * (lib/functions.php is_control_plane — keys off baseurl host vs the apex, so
     * an instance served at <slug>.tiknix.com self-identifies as a sandbox and
     * nothing has to change in provisioning). Same signal that gates builder tools.
     */
    public static function isFlagship(): bool {
        return !function_exists('is_control_plane') || is_control_plane();
    }

    /**
     * Curated "built with Tiknix" showcase entries for the landing rail.
     * Enabled entries, ordered; seeded by scripts/seed-showcase.php and
     * screenshotted by scripts/capture-showcase.php.
     */
    private function showcaseItems(): array {
        try {
            return Bean::find('showcase', 'enabled = 1 ORDER BY sort_order ASC, id ASC');
        } catch (\Throwable $e) {
            return [];   // table not seeded yet — landing still renders
        }
    }

    /**
     * Public pricing page — PRIMARY site only. On a provisioned instance this
     * redirects to the plain landing page so instance visitors never see it.
     * Pre-launch gate: the CTA is the same lead-capture as the landing hero
     * (no sign-ups / checkout yet).
     */
    public function pricing($params = []) {
        if (!self::isFlagship()) { Flight::redirect('/'); return; }
        $this->render('index/pricing', [
            'title' => 'Pricing — Tiknix',
            'subscribed' => (bool)$this->getParam('subscribed'),
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
    
    /**
     * About page
     */
    public function about() {
        $this->render('index/about', [
            'title' => 'About Us'
        ]);
    }
    
    /**
     * Contact page
     */
    public function contact() {
        $this->render('index/contact', [
            'title' => 'Contact Us'
        ]);
    }
    
    /**
     * Process contact form
     */
    public function docontact() {
        // Validate CSRF
        if (!$this->validateCSRF()) {
            return;
        }
        
        $name = $this->sanitize($this->getParam('name'));
        $email = $this->sanitize($this->getParam('email'), 'email');
        $subject = $this->sanitize($this->getParam('subject'));
        $message = $this->sanitize($this->getParam('message'));
        
        // Validate input
        if (empty($name) || empty($email) || empty($message)) {
            $this->flash('error', 'Please fill in all required fields');
            Flight::redirect('/contact');
            return;
        }
        
        // TODO: Send email or save to database
        
        $this->flash('success', 'Thank you for your message. We will get back to you soon!');
        Flight::redirect('/contact');
    }

    /** Public security-overview / trust page (/index/security). */
    public function security() {
        $this->render('index/security', ['title' => 'Security — Tiknix']);
    }
    // Privacy/Terms live in their own controllers (Privacy::index at /privacy, Terms::index
    // at /terms) — the URLs the signup form links to. The duplicate Index::privacy/terms
    // here rendered non-existent index/* views (a 500) and were never linked; removed.
}