<?php
namespace app\concepts\consent;

use \Flight as Flight;
use app\BaseControls\Control;

class Consent extends Control {

    /** The list, sorted by the owner: what this site uses, what still needs a decision, and whether visitors are asked. */
    public function index($params = []) {
        $services = Inventory::all();
        $this->render('consent/index', ['title' => 'Cookie consent', 'mode' => Settings::mode(), 'services' => $services, 'proofs' => (int) \app\Bean::count('consentrecord'), 'scan' => Scan::latest($services, session_name())]);
    }

    /** Ask the platform to walk the site. */
    public function scan($params = []) {
        if (!$this->validateCSRF()) return;
        try {
            Scan::request(strtolower((string) \app\Host::visitorHost()));
            $this->flash('success', 'The scan has started. It takes a few minutes. Come back to this page to see what it found.');
        } catch (\RuntimeException $e) {
            $this->logger->error('consent: the scan was not started: ' . $e->getMessage());
            $this->flash('error', 'The scan did not start: ' . $e->getMessage());
        }
        Flight::redirect('/consent');
    }

    // nav: none — the platform posts what its page scan saw here, with this app's broker key
    public function scanresult($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::jsonError('POST only', 405); return; }
        if (!Scan::fromPlatform((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) { Flight::jsonError('Forbidden.', 403); return; }
        $result = json_decode((string) file_get_contents('php://input', false, null, 0, 1048576), true);
        if (!is_array($result)) { Flight::jsonError('Send the result as JSON.', 400); return; }
        Scan::receive($result);
        Flight::jsonSuccess([], 'Received.');
    }

    /** Put a service in a group. */
    public function sort($params = []) {
        if (!$this->validateCSRF()) return;
        try {
            $again = Inventory::sort((string) $this->getParam('host', ''), (string) $this->getParam('group', ''));
            $this->flash('success', $again ? 'Sorted. Visitors will be asked again, because this is new to them.' : 'Sorted.');
        } catch (\InvalidArgumentException $e) { $this->flash('error', $e->getMessage()); }
        Flight::redirect('/consent');
    }

    /** Start or stop asking. */
    public function mode($params = []) {
        if (!$this->validateCSRF()) return;
        $mode = (string) $this->getParam('mode', '');
        try {
            Settings::setMode($mode);
            $this->flash('success', $mode === 'enforce' ? 'Visitors are now asked, and outside services wait for their answer.' : 'Back to watching. Nothing is blocked and no one is asked.');
        } catch (\InvalidArgumentException $e) { $this->flash('error', $e->getMessage()); }
        Flight::redirect('/consent');
    }

    /** Ask everyone again. */
    public function askagain($params = []) {
        if (!$this->validateCSRF()) return;
        Settings::bumpVersion();
        $this->flash('success', 'Everyone will be asked again on their next visit.');
        Flight::redirect('/consent');
    }

    // nav: none — where a browser reports what a page tried to load (the policy's report-uri); it carries no token and no session is needed
    public function report($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::jsonError('POST only', 405); return; }
        $raw = (string) file_get_contents('php://input', false, null, 0, 16384);
        $body = json_decode($raw, true);
        $r = is_array($body) ? Policy::fromReport($body, (string) \app\Host::visitorHost()) : null;
        if ($r !== null) Inventory::record($r['host'], $r['page'], $r['directive']);
        Flight::response()->status(204);
    }

    // nav: none — the small script the page loads
    public function js($params = []) {
        $path = dirname(__DIR__) . '/assets/tnx-consent.js';
        $body = file_get_contents($path);
        if ($body === false) throw new \RuntimeException("consent: cannot read {$path}");
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo $body;
    }

    /**
     * A visitor's answer: from the banner (fetch) or the cookies page (form). A page can be cached for visitors with no
     * session, so it cannot carry a token: the browser's own Origin header says the post came from this site, which is
     * what stops another site answering for them.
     */
    public function choose($params = []) {
        $ajax = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
        if (Flight::request()->method !== 'POST') { Flight::jsonError('POST only', 405); return; }
        $origin = strtolower((string) parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST));
        $host = strtolower((string) \app\Host::visitorHost());
        if ($origin !== $host) { Flight::jsonError('That answer did not come from this site.', 403); return; }
        $recent = array_values(array_filter((array) ($_SESSION['consent_answers'] ?? []), fn($t) => (int) $t > time() - 3600));
        if (count($recent) >= 30) { Flight::jsonError('That is a lot of answers in an hour. Please wait a while.', 429); return; }
        $how = (string) $this->getParam('how', '');
        $groups = match ($how) {
            'all'  => array_keys(Banner::inUse(Inventory::cached())),
            'none' => [],
            'pick' => $this->pickedGroups(),
            default => null,
        };
        if ($groups === null) { Flight::jsonError('Answer all, none or pick.', 400); return; }
        $value = Choice::save($groups, $how, Flight::isLoggedIn() ? (int) $this->member->id : 0);
        Choice::send($value, $groups, $host, \app\Host::https());
        $recent[] = time(); $_SESSION['consent_answers'] = $recent;
        if ($ajax) { Flight::jsonSuccess(['groups' => $groups], 'Saved.'); return; }
        $this->flash('success', 'Your choices are saved.');
        Flight::redirect('/consent/cookies');
    }

    /** Only groups a visitor is asked about count: a made-up name in the form is ignored, not stored. */
    private function pickedGroups(): array {
        $posted = (array) ($_POST['groups'] ?? []);
        foreach (Groups::asked() as $g) if (!empty($_POST['group_' . $g])) $posted[] = $g;
        return array_values(array_intersect(Groups::asked(), array_map('strval', $posted)));
    }

    /** The public page: what this site uses, and the visitor's own choices. */
    public function cookies($params = []) {
        $this->render('consent/cookies', ['title' => 'Cookies and outside services', 'services' => Inventory::all(), 'current' => Choice::current(), 'last' => Choice::last(), 'sessionCookie' => session_name()]);
    }
}
