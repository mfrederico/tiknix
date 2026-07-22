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
     * Home page
     */
    public function index() {
        // First-run setup takes precedence over the landing page.
        if (!Install::isInstalled()) { Flight::redirect('/install'); return; }

        // Public "Coming Soon" landing page.
        // Rendered without the Tiknix header/footer layout so visitors see a
        // clean, standalone page. To restore the original homepage, revert this
        // method to render 'index/index' with the layout (see git history).
        // The showcase + pricing are marketing surfaces for the PRIMARY tiknix.com
        // site only. Provisioned instances are clones of this app, so gate those
        // surfaces off on any non-flagship host — an instance shows just the plain
        // "coming soon" page, with no confusion about which is the real Tiknix.
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

        // Basic validation
        if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Please enter your name and a valid email address.');
            Flight::redirect('/');
            return;
        }

        try {
            $lead = Bean::dispense('lead');
            $lead->firstName = $firstName;
            $lead->lastName  = $lastName;
            $lead->email     = $email;
            $lead->createdAt = date('Y-m-d H:i:s');
            Bean::store($lead);
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
    
    /**
     * Privacy policy
     */
    public function privacy() {
        $this->render('index/privacy', [
            'title' => 'Privacy Policy'
        ]);
    }
    
    /**
     * Terms of service
     */
    public function terms() {
        $this->render('index/terms', [
            'title' => 'Terms of Service'
        ]);
    }
}