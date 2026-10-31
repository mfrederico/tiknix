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
            if ($id === '' || Settings::mode() !== 'enforce' || !Choice::allows('analytics') || \Flight::hasLevel(LEVELS['ADMIN'])) return;
            $snippet = self::snippet($id, Choice::allows('marketing'));
            \Flight::response()->addResponseBodyCallback(fn($body) => Guard::inject((string) $body, $snippet));
        } catch (\Throwable $e) {
            \Flight::get('log')->error('googleanalytics: not added to this page: ' . $e->getMessage());
        }
    }

    /** Consent first (everything denied), then what they said yes to, then the tag. */
    public static function snippet(string $id, bool $ads): string {
        if (!self::isId($id)) throw new \InvalidArgumentException("'{$id}' is not a Measurement ID");
        $adState = $ads ? 'granted' : 'denied';
        return "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
             . "gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});"
             . "gtag('consent','update',{analytics_storage:'granted',ad_storage:'{$adState}',ad_user_data:'{$adState}',ad_personalization:'{$adState}'});"
             . "gtag('js',new Date());gtag('config','{$id}');</script>"
             . "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>";
    }
}
