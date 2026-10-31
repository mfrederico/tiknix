<?php
/**
 * Tag — Google's tag on a page, only for a visitor who said yes to Statistics while the site is asking. "Basic" Consent
 * Mode on purpose: Google's advanced mode loads its script before any answer and sends it cookieless pings, which is
 * the visitor's address going to Google unasked, and the consent plugin's policy would refuse it anyway. Ads stay
 * denied unless they also said yes to Ads. Admins are not counted, so the owner's own clicks stay out of the numbers.
 * The ID is a connection (Connections, Marketing, Google Analytics), so each site of an app has its own.
 */

namespace app\concepts\googleanalytics;

use app\concepts\consent\{Choice, Guard, Settings};

class Tag {

    private const QUEUE = 'googleanalytics_events';
    private const MAX_QUEUED = 10;
    private const ID_RE = '/^G-[A-Z0-9]{4,20}$/';

    public static function boot(): void {
        if (PHP_SAPI === 'cli') return;
        \Flight::before('start', function () {
            if (\Flight::has('sidecar.core_root')) return;   // a sidecar runs on core's code but is not this site
            // the connection's second step is done when the site is asking
            \app\ConnectorSetup::extend('googleanalytics', function (array $steps) {
                foreach ($steps as $i => $s) if ($s['id'] === 'asking') $steps[$i]['met'] = Settings::mode() === 'enforce';
                return $steps;
            });
            self::start();
        });
    }

    public static function isId(string $id): bool { return (bool) preg_match(self::ID_RE, $id); }

    /** The connected Measurement ID, '' when this site has none. A connection without a readable ID is a fault. */
    public static function id(): string {
        $conn = \app\ConnectionStore::for('googleanalytics');
        if (!$conn) return '';
        $meta = json_decode((string) $conn->metadataJson, true);
        $id = is_array($meta) ? (string) ($meta['measurement_id'] ?? '') : '';
        if (!self::isId($id)) throw new \UnexpectedValueException("the Google Analytics connection #{$conn->id} holds no Measurement ID: connect it again under Connections");
        return $id;
    }

    /** Is this site using Analytics? Then its hosts are on the consent list, and visitors are asked. */
    public static function active(array $ctx = []): bool { return self::id() !== ''; }

    public static function start(): void {
        try {
            $id = self::id();
            if ($id === '') return;
            self::watchSignups();
            $mode = Settings::mode();
            $admin = \Flight::hasLevel(LEVELS['ADMIN']);
            $answered = Choice::current() !== null;
            $yes = Choice::allows('analytics');
            [$send, $keep] = self::settle((array) ($_SESSION[self::QUEUE] ?? []), $mode === 'enforce', $answered, $yes, $admin);
            if (isset($_SESSION[self::QUEUE])) $_SESSION[self::QUEUE] = $keep;
            if ($mode !== 'enforce' || !$yes || $admin) return;
            if (!self::isId($id)) throw new \UnexpectedValueException("'{$id}' is not a Measurement ID");
            $snippet = self::snippet($id, Choice::allows('marketing'), $send);
            \Flight::response()->addResponseBodyCallback(fn($body) => Guard::inject((string) $body, $snippet));
        } catch (\Throwable $e) {
            \Flight::get('log')->error('googleanalytics: not added to this page: ' . $e->getMessage());
        }
    }

    /**
     * Something happened that the owner wants counted: it waits in this visitor's session and goes out on their next page, in
     * their browser, only if they said yes. Never through Google's server route: that would count people who said no. Name and
     * details are what Google stores, so never put a name, an email or anything else about a person in them.
     *   Tag::event('project_created');   Tag::event('sign_up', ['method' => 'google']);
     */
    public static function event(string $name, array $params = []): void {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) throw new \InvalidArgumentException("'{$name}' is not an event name: lowercase letters, digits and _");
        foreach ($params as $k => $v) if (!is_string($k) || !is_scalar($v)) throw new \InvalidArgumentException("event {$name}: a detail is a name and a plain value");
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $q = (array) ($_SESSION[self::QUEUE] ?? []);
        $q[] = [$name, $params];
        $_SESSION[self::QUEUE] = array_slice($q, -self::MAX_QUEUED);
    }

    /**
     * What goes out now and what waits. No answer yet: it waits, the banner is on the page. A no, or an admin: it is dropped.
     * @return array{0:array,1:array} [send, keep]
     */
    public static function settle(array $queue, bool $asking, bool $answered, bool $yes, bool $admin): array {
        if ($admin || ($answered && !$yes)) return [[], []];
        if (!$asking || !$answered) return [[], $queue];
        return [$queue, []];
    }

    /** A new account, whichever way it was made. The app's own hook still runs first. */
    private static function watchSignups(): void {
        $wrap = fn(?callable $prev, string $method) => function (int $memberId) use ($prev, $method): void {
            if ($prev !== null) $prev($memberId);
            self::event('sign_up', ['method' => $method]);
        };
        \app\Auth::$onNewMember = $wrap(\app\Auth::$onNewMember, 'form');
        $google = 'app\\plugins\\GoogleAuth';   // only where Google sign-in is installed
        if (class_exists($google)) $google::$onNewMember = $wrap($google::$onNewMember, 'google');
    }

    /** Consent first (everything denied), then what they said yes to, then the tag. */
    public static function snippet(string $id, bool $ads, array $events = []): string {
        if (!self::isId($id)) throw new \InvalidArgumentException("'{$id}' is not a Measurement ID");
        $adState = $ads ? 'granted' : 'denied';
        return "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
             . "gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});"
             . "gtag('consent','update',{analytics_storage:'granted',ad_storage:'{$adState}',ad_user_data:'{$adState}',ad_personalization:'{$adState}'});"
             . "gtag('js',new Date());gtag('config','{$id}');" . implode('', array_map(fn($e) => "gtag('event'," . json_encode((string) $e[0]) . ',' . json_encode((object) $e[1], JSON_HEX_TAG | JSON_HEX_AMP) . ');', $events)) . "</script>"
             . "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>";
    }
}
